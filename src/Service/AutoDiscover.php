<?php
namespace App\Service;

use App\Service\Git\ProviderRegistry;
use App\Config\AppConfig;
use GuzzleHttp\Client;

class AutoDiscover
{
    private JenkinsService $jenkins;
    private ProviderRegistry $gitRegistry;
    private AppConfig $config;
    private MappingManager $mapping;
    private ?Logger $logger;

    public function __construct(JenkinsService $jenkins, ProviderRegistry $gitRegistry, AppConfig $config, MappingManager $mapping, ?Logger $logger = null)
    {
        $this->jenkins     = $jenkins;
        $this->gitRegistry = $gitRegistry;
        $this->config      = $config;
        $this->mapping     = $mapping;
        $this->logger      = $logger;
    }

    public function discover(): array
    {
        $buildMode = $this->mapping->buildMode();

        // ⚠️ 关键安全约束：按当前构建模式严格隔离，Jenkins 和 GitLab CI 映射绝不允许互相干扰
        // - jenkins 模式：只参考 build_provider=jenkins 的已有记录去重
        // - gitlab_ci 模式：只参考 build_provider=gitlab_ci 的已有记录去重
        // - both 模式：两类都纳入去重（归一化 remote 后互斥，同一仓库只挂一个 provider）
        $activeRemotes = [];   // 归一化后的 key：host/path（统一格式，跨协议去重）
        $existingNames = [];
        foreach ($this->config->getJobGitMap() as $m) {
            $bp = $m['build_provider'] ?? 'jenkins';

            // 单 provider 模式：排斥对方 provider 的记录，杜绝交叉污染
            if ($buildMode !== 'both' && $bp !== $buildMode) continue;

            if (!empty($m['job_name'])) $existingNames[] = $m['job_name'];
            if (empty($m['git_remote'])) continue;
            if (($m['status'] ?? 'active') === 'active') {
                $key = $this->normalizeRemote($m['git_remote']);
                if ($key) $activeRemotes[] = $key;
            }
        }

        $errors    = [];
        $found     = [];

        if (in_array($buildMode, ['jenkins', 'both'])) {
            try {
                $found = array_merge($found, $this->scanJenkins($activeRemotes, $existingNames));
            } catch (\Exception $e) {
                $errors[] = 'Jenkins: ' . $e->getMessage();
            }
        }

        if (in_array($buildMode, ['gitlab_ci', 'both'])) {
            try {
                $found = array_merge($found, $this->scanGitlabCi($activeRemotes, $existingNames));
            } catch (\Exception $e) {
                $errors[] = 'GitLab CI: ' . $e->getMessage();
            }
        }

        if (!empty($errors)) {
            $found[] = ['entry' => ['job_name' => '__errors__'], 'source' => '_errors', '_errors' => $errors];
        }

        return $found;
    }

    public function saveDiscovered(array $discovered): int
    {
        $saved = 0;
        $buildMode = $this->mapping->buildMode();
        $maps  = $this->config->getJobGitMap();

        // 同样按模式隔离：只收集当前模式相关 provider 的 job_name，防止跨 provider 误判重复
        $names = [];
        foreach ($maps as $m) {
            $bp = $m['build_provider'] ?? 'jenkins';
            if ($buildMode !== 'both' && $bp !== $buildMode) continue;
            if (!empty($m['job_name'])) $names[] = $m['job_name'];
        }

        foreach ($discovered as $item) {
            $e = $item['entry'];
            if (in_array($e['job_name'], $names)) continue;
            // 新发现全部设为 pending，用户手动启用后才变 active
            $e['status'] = 'pending';
            $maps[] = $e;
            $saved++;
        }
        if ($saved > 0) $this->config->saveJobGitMap($maps);
        return $saved;
    }

    // ── Jenkins ──

    private function scanJenkins(array $activeRemotes, array $existingNames): array
    {
        $found = [];
        $seen  = [];  // 归一化 key，仅本 provider 内去重
        try {
            foreach ($this->jenkins->getAllJobs() as $jobName) {
                $remotes  = $this->jenkins->getGitRemotes($jobName);
                $remote   = $remotes[0] ?? '';
                $rKey     = $remote ? $this->normalizeRemote($remote) : '';
                // 该仓库已被当前模式的已有 active 记录映射（归一化 key 比对，跨协议生效）
                if ($rKey && in_array($rKey, $activeRemotes)) continue;
                // 本 provider 内同一仓库不重复显示
                if ($rKey && in_array($rKey, $seen)) continue;
                if (in_array($jobName, $existingNames)) continue;
                $platform = $this->detectPlatform($remote);
                if ($rKey) $seen[] = $rKey;

                $found[] = ['entry' => [
                    'job_name'       => $jobName,
                    'build_provider' => 'jenkins',
                    'git_platform'   => $platform,
                    'git_remote'     => $remote,
                    'current_path'   => $this->extractPath($remote, $jobName),
                    'project_id'     => null,
                    'web_url'        => '',
                    'harbor_repository' => '',
                ], 'source' => 'jenkins'];
            }
        } catch (\Exception $e) {
            $this->logger?->warning('AutoDiscover Jenkins 扫描失败', ['error' => $e->getMessage()]);
        }
        return $found;
    }

    // ── GitLab CI ──

    private function scanGitlabCi(array $activeRemotes, array $existingNames): array
    {
        $found = [];
        $glCfg = $this->config->getGitlabConfig();
        $base  = rtrim($glCfg['base_url'] ?? '', '/');
        $token = $glCfg['token'] ?? '';
        if (empty($base) || empty($token)) return $found;

        try {
            $client = new Client([
                'headers' => ['PRIVATE-TOKEN' => $token],
                'timeout' => 10, 'connect_timeout' => 5, 'http_errors' => false,
            ]);
            // 快速验证认证
            $test = $client->get("{$base}/api/v4/user");
            if ($test->getStatusCode() === 401) {
                throw new \RuntimeException('GitLab Token 无效，请检查 GITLAB_TOKEN');
            }
            $page = 1;
            $seen = [];  // 归一化 key，仅本 provider 内去重
            while ($page <= 10) {
                $resp = $client->get("{$base}/api/v4/projects?per_page=100&page={$page}&membership=true&order_by=last_activity_at");
                $data = json_decode($resp->getBody(), true);
                if (!is_array($data) || empty($data)) break;

                foreach ($data as $p) {
                    $path = $p['path_with_namespace'] ?? '';
                    $pid  = $p['id'] ?? 0;
                    $remote = $p['http_url_to_repo'] ?? '';
                    $rKey   = $remote ? $this->normalizeRemote($remote) : '';
                    // 该仓库已被当前模式的已有 active 记录映射（归一化 key 比对，跨协议生效）
                    if ($rKey && in_array($rKey, $activeRemotes)) continue;
                    // 本 provider 内同一仓库不重复显示
                    if ($rKey && in_array($rKey, $seen)) continue;
                    if (in_array($path, $existingNames)) continue;
                    if ($rKey) $seen[] = $rKey;

                    $found[] = ['entry' => [
                        'job_name'       => $path,
                        'build_provider' => 'gitlab_ci',
                        'git_platform'   => 'gitlab',
                        'git_remote'     => $remote,
                        'current_path'   => $path,
                        'project_id'     => $p['id'] ?? null,
                        'web_url'        => $p['web_url'] ?? '',
                        'harbor_repository' => '',
                    ], 'source' => 'gitlab_ci'];
                }
                $page++;
            }
        } catch (\Exception $e) {
            $this->logger?->warning('AutoDiscover GitLab CI 扫描失败', ['error' => $e->getMessage()]);
        }
        return $found;
    }

    // ── helpers ──

    /**
     * 归一化 Git remote URL 为纯路径（org/repo），用于跨协议/跨 host 去重。
     *
     * 只保留仓库路径，忽略协议和 host，解决内网同一仓库用域名/IP 不同的问题。
     * 同一 DevOps 实例内，不同 host 上路径相同的仓库视为同一仓库。
     *
     * 支持格式：
     *   git@github.com:org/repo.git  → org/repo
     *   https://github.com/org/repo   → org/repo
     *   https://10.0.0.5/team/repo    → team/repo
     *   git@git.internal.com:team/repo → team/repo（域名/IP 不同但路径相同 → 命中）
     */
    private function normalizeRemote(string $remote): string
    {
        $r = trim($remote);

        // 去掉协议前缀
        $r = preg_replace('#^(https?|ssh|git)://#i', '', $r);

        // git@host:path → host/path
        if (preg_match('#^git@([^:]+):(.+)#', $r, $m)) {
            $r = $m[1] . '/' . $m[2];
        }

        // 去掉尾部 .git
        $r = preg_replace('#\.git$#i', '', $r);
        $r = rtrim($r, '/');

        // 提取路径部分（去掉 host），统一小写用于比对
        $slashPos = strpos($r, '/');
        if ($slashPos !== false) {
            $r = strtolower(substr($r, $slashPos + 1));
        } else {
            $r = strtolower($r);
        }

        return $r;
    }

    private function detectPlatform(string $remote): string
    {
        if (empty($remote)) return $this->config->getDefaultGitPlatform();
        try { return $this->gitRegistry->detect($remote); }
        catch (\Exception $e) { return $this->config->getDefaultGitPlatform(); }
    }

    private function extractPath(string $remote, string $jobName): string
    {
        if (preg_match('#[:/]([^/]+/[^/]+?)(\.git)?$#', $remote, $m)) {
            return $m[1];
        }
        return $jobName;
    }
}
