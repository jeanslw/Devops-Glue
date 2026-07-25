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
        // - both 模式：两类都纳入去重（同一 remote 只挂一个 provider，避免重复）
        $activeRemotes = [];
        $existingNames = [];
        foreach ($this->config->getJobGitMap() as $m) {
            $bp = $m['build_provider'] ?? 'jenkins';

            // 单 provider 模式：排斥对方 provider 的记录，杜绝交叉污染
            if ($buildMode !== 'both' && $bp !== $buildMode) continue;

            if (!empty($m['job_name'])) $existingNames[] = $m['job_name'];
            if (empty($m['git_remote'])) continue;
            if (($m['status'] ?? 'active') === 'active') {
                $activeRemotes[] = $m['git_remote'];
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
        $seen  = [];  // 仅本 provider 内去重，不污染 activeRemotes
        try {
            foreach ($this->jenkins->getAllJobs() as $jobName) {
                $remotes  = $this->jenkins->getGitRemotes($jobName);
                $remote   = $remotes[0] ?? '';
                // 该 remote 已被当前模式的已有 active 记录映射，跳过（跨 provider 仅 both 模式生效）
                if ($remote && in_array($remote, $activeRemotes)) continue;
                // 本 provider 内同一 remote 不重复显示
                if ($remote && in_array($remote, $seen)) continue;
                if (in_array($jobName, $existingNames)) continue;
                $platform = $this->detectPlatform($remote);
                if ($remote) $seen[] = $remote;

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
            $seen = [];  // 仅本 provider 内去重，不污染 activeRemotes
            while ($page <= 10) {
                $resp = $client->get("{$base}/api/v4/projects?per_page=100&page={$page}&membership=true&order_by=last_activity_at");
                $data = json_decode($resp->getBody(), true);
                if (!is_array($data) || empty($data)) break;

                foreach ($data as $p) {
                    $path = $p['path_with_namespace'] ?? '';
                    $pid  = $p['id'] ?? 0;
                    $remote = $p['http_url_to_repo'] ?? '';
                    // 该 remote 已被当前模式的已有 active 记录映射，跳过
                    if ($remote && in_array($remote, $activeRemotes)) continue;
                    // 本 provider 内同一 remote 不重复显示
                    if ($remote && in_array($remote, $seen)) continue;
                    if (in_array($path, $existingNames)) continue;
                    if ($remote) $seen[] = $remote;

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
