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
    private ?Client $gitlabClient = null;

    public function __construct(JenkinsService $jenkins, ProviderRegistry $gitRegistry, AppConfig $config, MappingManager $mapping, ?Logger $logger = null, ?Client $gitlabClient = null)
    {
        $this->jenkins     = $jenkins;
        $this->gitRegistry = $gitRegistry;
        $this->config      = $config;
        $this->mapping     = $mapping;
        $this->logger      = $logger;
        $this->gitlabClient = $gitlabClient;
    }

    public function discover(): array
    {
        $buildMode = $this->mapping->buildMode();

        // ⚠️ 关键安全约束：按当前构建模式严格隔离
        // - jenkins 模式：只参考 build_provider=jenkins 的已有记录去重
        // - gitlab_ci 模式：只参考 build_provider=gitlab_ci 的已有记录去重
        // - both 模式：jenkins + gitlab_ci 都纳入去重
        // - custom_push_enabled 开启时：custom_push 记录也纳入去重（正交维度）
        $cpEnabled = $this->config->getCustomPushEnabled();
        $activeRemotes = [];   // 归一化后的 key：host/path（统一格式，跨协议去重）
        $existingNames = [];
        foreach ($this->config->getJobGitMap() as $m) {
            $bp = $m['build_provider'] ?? AppConfig::PROVIDER_JENKINS;

            // 单 provider 模式：排斥对方 provider 的记录，杜绝交叉污染
            if ($buildMode !== AppConfig::BUILD_MODE_BOTH && $bp !== $buildMode) {
                // 但 custom_push 记录在 custom_push_enabled 时始终纳入去重
                if (!($cpEnabled && $bp === AppConfig::PROVIDER_CUSTOM_PUSH)) {
                    continue;
                }
            }

            if (!empty($m['job_name'])) {
                $existingNames[] = $m['job_name'];
            }
            if (empty($m['git_remote'])) {
                continue;
            }
            if (($m['status'] ?? AppConfig::STATUS_ACTIVE) === AppConfig::STATUS_ACTIVE) {
                $key = $this->normalizeRemote($m['git_remote']);
                if ($key) {
                    $activeRemotes[] = $key;
                }
            }
        }

        $errors    = [];
        $found     = [];

        if (in_array($buildMode, [AppConfig::BUILD_MODE_JENKINS, AppConfig::BUILD_MODE_BOTH])) {
            try {
                $found = array_merge($found, $this->scanJenkins($activeRemotes, $existingNames));
            } catch (\Exception $e) {
                $errors[] = 'Jenkins: ' . $e->getMessage();
            }
        }

        if (in_array($buildMode, [AppConfig::BUILD_MODE_GITLAB_CI, AppConfig::BUILD_MODE_BOTH])) {
            try {
                $found = array_merge($found, $this->scanGitlabCi($activeRemotes, $existingNames));
            } catch (\Exception $e) {
                $errors[] = 'GitLab CI: ' . $e->getMessage();
            }
        }

        // custom_push 开关开启时：扫描 Git 平台项目，build_provider 设为 custom_push
        if ($cpEnabled) {
            try {
                $found = array_merge($found, $this->scanGitPlatforms($activeRemotes, $existingNames));
            } catch (\Exception $e) {
                $errors[] = 'Git: ' . $e->getMessage();
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
        $cpEnabled = $this->config->getCustomPushEnabled();
        $maps  = $this->config->getJobGitMap();

        // 同样按模式隔离：只收集当前模式相关 provider 的 job_name，防止跨 provider 误判重复
        $names = [];
        foreach ($maps as $m) {
            $bp = $m['build_provider'] ?? AppConfig::PROVIDER_JENKINS;
            if ($buildMode !== AppConfig::BUILD_MODE_BOTH && $bp !== $buildMode) {
                // custom_push 记录在 custom_push_enabled 时始终纳入去重
                if (!($cpEnabled && $bp === AppConfig::PROVIDER_CUSTOM_PUSH)) {
                    continue;
                }
            }
            if (!empty($m['job_name'])) {
                $names[] = $m['job_name'];
            }
        }

        foreach ($discovered as $item) {
            $e = $item['entry'] ?? null;
            if (!$e || empty($e['job_name']) || in_array($e['job_name'], $names)) {
                continue;
            }
            // 新发现全部设为 pending，用户手动启用后才变 active
            $e['status'] = AppConfig::STATUS_PENDING;
            $maps[] = $e;
            $saved++;
        }
        if ($saved > 0) {
            $this->config->saveJobGitMap($maps);
        }
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
                if ($rKey && in_array($rKey, $activeRemotes)) {
                    continue;
                }
                // 本 provider 内同一仓库不重复显示
                if ($rKey && in_array($rKey, $seen)) {
                    continue;
                }
                if (in_array($jobName, $existingNames)) {
                    continue;
                }
                $platform = $this->detectPlatform($remote);
                if ($rKey) {
                    $seen[] = $rKey;
                }

                $found[] = ['entry' => [
                    'job_name'       => $jobName,
                    'build_provider' => AppConfig::PROVIDER_JENKINS,
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
        if (empty($base) || !$this->gitlabClient) {
            return $found;
        }

        try {
            // 快速验证认证
            $test = $this->gitlabClient->get("{$base}/api/v4/user");
            if ($test->getStatusCode() === 401) {
                throw new \RuntimeException('GitLab Token 无效，请检查 GITLAB_TOKEN');
            }
            $page = 1;
            $seen = [];  // 归一化 key，仅本 provider 内去重
            while ($page <= 10) {
                $resp = $this->gitlabClient->get("{$base}/api/v4/projects?per_page=100&page={$page}&membership=true&order_by=last_activity_at");
                $data = json_decode($resp->getBody(), true);
                if (!is_array($data) || empty($data)) {
                    break;
                }

                foreach ($data as $p) {
                    $path = $p['path_with_namespace'] ?? '';
                    $pid  = $p['id'] ?? 0;
                    $remote = $p['http_url_to_repo'] ?? '';
                    $rKey   = $remote ? $this->normalizeRemote($remote) : '';
                    // 该仓库已被当前模式的已有 active 记录映射（归一化 key 比对，跨协议生效）
                    if ($rKey && in_array($rKey, $activeRemotes)) {
                        continue;
                    }
                    // 本 provider 内同一仓库不重复显示
                    if ($rKey && in_array($rKey, $seen)) {
                        continue;
                    }
                    if (in_array($path, $existingNames)) {
                        continue;
                    }
                    if ($rKey) {
                        $seen[] = $rKey;
                    }

                    $found[] = ['entry' => [
                        'job_name'       => $path,
                        'build_provider' => AppConfig::PROVIDER_GITLAB_CI,
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

    // ── Git 平台扫描（custom_push 模式专用） ──

    /**
     * 扫描已配置的 Git 平台项目，build_provider 统一设为 custom_push。
     * 目前支持 GitLab（通过已有 gitlabClient）；其他平台可后续扩展。
     */
    private function scanGitPlatforms(array $activeRemotes, array $existingNames): array
    {
        $found = [];

        // GitLab
        $glCfg = $this->config->getGitlabConfig();
        $base  = rtrim($glCfg['base_url'] ?? '', '/');
        if (!empty($base) && $this->gitlabClient) {
            try {
                $test = $this->gitlabClient->get("{$base}/api/v4/user");
                if ($test->getStatusCode() !== 401) {
                    $page = 1;
                    $seen = [];
                    while ($page <= 10) {
                        $resp = $this->gitlabClient->get("{$base}/api/v4/projects?per_page=100&page={$page}&membership=true&order_by=last_activity_at");
                        $data = json_decode($resp->getBody(), true);
                        if (!is_array($data) || empty($data)) {
                            break;
                        }

                        foreach ($data as $p) {
                            $path = $p['path_with_namespace'] ?? '';
                            $remote = $p['http_url_to_repo'] ?? '';
                            $rKey   = $remote ? $this->normalizeRemote($remote) : '';
                            if ($rKey && in_array($rKey, $activeRemotes)) {
                                continue;
                            }
                            if ($rKey && in_array($rKey, $seen)) {
                                continue;
                            }
                            if (in_array($path, $existingNames)) {
                                continue;
                            }
                            if ($rKey) {
                                $seen[] = $rKey;
                            }

                            $found[] = ['entry' => [
                                'job_name'       => $path,
                                'build_provider' => AppConfig::PROVIDER_CUSTOM_PUSH,
                                'git_platform'   => 'gitlab',
                                'git_remote'     => $remote,
                                'current_path'   => $path,
                                'project_id'     => $p['id'] ?? null,
                                'web_url'        => $p['web_url'] ?? '',
                                'harbor_repository' => '',
                            ], 'source' => 'gitlab'];
                        }
                        $page++;
                    }
                }
            } catch (\Exception $e) {
                $this->logger?->warning('AutoDiscover Git 平台扫描失败 (GitLab)', ['error' => $e->getMessage()]);
            }
        }

        // TODO: GitHub / Gitee / Gitea 项目列表 API 对接（按需扩展）

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
        if (empty($r)) {
            return '';
        }

        // 处理 ssh://user@host:port/path 格式（带端口）
        if (preg_match('#^ssh://#i', $r)) {
            $parts = parse_url($r);
            if (isset($parts['path'])) {
                $path = ltrim($parts['path'], '/');
                $path = preg_replace('#\.git$#i', '', $path);
                return strtolower($path);
            }
            return '';
        }

        // 去掉其他协议前缀 (http, https, git)
        $r = preg_replace('#^(https?|git)://#i', '', $r);

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
        if (empty($remote)) {
            return $this->config->getDefaultGitPlatform();
        }
        try {
            return $this->gitRegistry->detect($remote);
        } catch (\Exception $e) {
            return $this->config->getDefaultGitPlatform();
        }
    }

    /**
     * 提取仓库展示路径（保留 GitLab 子群组层级）。
     * 注意与 normalizeRemote() 区分：那个产出的是小写去 host 的 canonical 去重键，
     * 这里保持原始大小写，用于展示与回传平台 API。
     */
    private function extractPath(string $remote, string $jobName): string
    {
        return \App\Helper\GitRemote::extractPath($remote) ?? $jobName;
    }
}
