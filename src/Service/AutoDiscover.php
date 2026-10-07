<?php

namespace App\Service;

use App\Service\Git\ProviderRegistry;
use App\Config\AppConfig;
use GuzzleHttp\Client;

/**
 * @phpstan-type DiscoveredItem array{entry?: array<string,mixed>, source: string}
 */
class AutoDiscover
{
    private JenkinsService $jenkins;
    private ProviderRegistry $gitRegistry;
    private Settings $config;
    private MappingManager $mapping;
    private ?Logger $logger;
    private ?Client $gitlabClient = null;
    private ?Client $giteaClient = null;
    /** @var list<string> 本次 discover 实际发起网络扫描的源（已启用且已配置），
     * 供前端区分「扫描全部失败 / 扫描部分失败 / 扫描成功」 */
    private array $scanSources = [];

    public function __construct(JenkinsService $jenkins, ProviderRegistry $gitRegistry, Settings $config, MappingManager $mapping, ?Logger $logger = null, ?Client $gitlabClient = null, ?Client $giteaClient = null)
    {
        $this->jenkins      = $jenkins;
        $this->gitRegistry  = $gitRegistry;
        $this->config       = $config;
        $this->mapping      = $mapping;
        $this->logger       = $logger;
        $this->gitlabClient = $gitlabClient;
        $this->giteaClient  = $giteaClient;
    }

    /** @return array{found: list<DiscoveredItem>, errors: list<string>, sources: list<string>} */
    public function discover(): array
    {
        $this->scanSources = [];
        $enabled = $this->mapping->activeBuildProviders();

        // ⚠️ 关键安全约束：已有映射的去重范围
        // - 内置拉取式 provider（jenkins/gitlab_ci/gitea_ci）的记录始终纳入去重：
        //   Git 平台扫描按原生 CI 标记（与 BUILD_MODE 无关），已映射项目不应因对应 CI 未启用而被重复建议
        // - custom_push_enabled 开启时：custom_push 记录也纳入去重（正交维度）
        $cpEnabled = $this->mapping->hasCustomPush();
        $activeRemotes = [];   // 归一化后的 key：host/path（统一格式，跨协议去重）
        $existingNames = [];
        foreach ($this->mapping->allMaps() as $m) {
            $bp = $m['build_provider'] ?? AppConfig::PROVIDER_JENKINS;

            // 未知/非内置 provider 的记录不参与去重
            if (!in_array($bp, AppConfig::BUILTIN_PULL_PROVIDERS, true)) {
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

        // 先扫描 Git 平台原生 CI（GitLab CI / Gitea Actions），构建「仓库路径 → 平台」提示表，
        // 供 Jenkins 扫描纠正自建平台的误识别——自建 GitLab/Gitea 可能同 host 仅端口不同
        //（且 SSH 端口与 Web 端口无配置对应关系），URL 子串匹配无法区分，会回退默认平台。
        $platformHints = [];  // strtolower(仓库路径) => git_platform

        if (in_array(AppConfig::PROVIDER_GITLAB_CI, $enabled, true)) {
            try {
                $batch = $this->scanGitlabCi($activeRemotes, $existingNames);
                $this->collectPlatformHints($platformHints, $batch);
                $found = array_merge($found, $batch);
            } catch (\Exception $e) {
                $errors[] = 'GitLab CI: ' . $e->getMessage();
            }
        }

        if (in_array(AppConfig::PROVIDER_GITEA_CI, $enabled, true)) {
            try {
                $batch = $this->scanGiteaCi($activeRemotes, $existingNames);
                $this->collectPlatformHints($platformHints, $batch);
                $found = array_merge($found, $batch);
            } catch (\Exception $e) {
                $errors[] = 'Gitea Actions: ' . $e->getMessage();
            }
        }

        if (in_array(AppConfig::PROVIDER_JENKINS, $enabled, true)) {
            $this->scanSources[] = 'Jenkins';
            try {
                $found = array_merge($found, $this->scanJenkins($activeRemotes, $existingNames, $platformHints));
            } catch (\Exception $e) {
                $errors[] = 'Jenkins: ' . $e->getMessage();
            }
        }

        // Git 平台扫描：与 BUILD_MODE/custom_push 开关无关——只要平台已配置就发现其项目，
        // build_provider 按平台原生 CI 标记（GitLab→gitlab_ci、Gitea→gitea_ci；
        // 无原生拉取式 provider 的平台如 Bitbucket 才用 custom_push）。
        // 原生 CI 已启用的平台由其专属扫描覆盖（如 scanGitlabCi），scanGitPlatforms 内部跳过。
        try {
            // 跨扫描去重：已被本轮前面扫描（Jenkins/GitLab CI/Gitea）发现的项目不再重复导入
            $foundRemotes = $activeRemotes;
            foreach ($found as $item) {
                $r = $item['entry']['git_remote'] ?? '';
                if (is_string($r) && $r !== '') {
                    $k = $this->normalizeRemote($r);
                    if ($k !== '') {
                        $foundRemotes[] = $k;
                    }
                }
            }
            $found = array_merge($found, $this->scanGitPlatforms($foundRemotes, $existingNames, $enabled));
        } catch (\Exception $e) {
            $errors[] = 'Git: ' . $e->getMessage();
        }

        return [
            'found'   => $found,
            'errors'  => $errors,
            'sources' => $this->scanSources,
        ];
    }

    /** @param list<DiscoveredItem> $discovered */
    public function saveDiscovered(array $discovered): int
    {
        $saved = 0;
        $cpEnabled = $this->mapping->hasCustomPush();
        $maps  = $this->mapping->allMaps();

        // 与 discover() 同口径：内置 provider 记录始终纳入，custom_push 记录在开关开启时纳入，
        // 防止跨 provider 误判重复
        $names = [];
        foreach ($maps as $m) {
            $bp = $m['build_provider'] ?? AppConfig::PROVIDER_JENKINS;
            if (!in_array($bp, AppConfig::BUILTIN_PULL_PROVIDERS, true)) {
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
            $this->mapping->saveMaps($maps);
        }
        return $saved;
    }

    // ── Jenkins ──

    /**
     * @param list<string> $activeRemotes
     * @param list<string> $existingNames
     * @param array<string,string> $platformHints 同轮平台扫描构建的「strtolower(仓库路径) => git_platform」提示表
     * @return list<DiscoveredItem>
     */
    private function scanJenkins(array $activeRemotes, array $existingNames, array $platformHints = []): array
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
                // 平台识别：优先用同轮平台扫描的「仓库路径→平台」提示表纠正
                //（自建平台同 host 时 URL 无法区分）；查不到再走 URL 匹配/默认平台
                $platform = '';
                $pathKey  = $remote !== '' ? mb_strtolower($this->extractPath($remote, $jobName)) : '';
                if ($pathKey !== '' && isset($platformHints[$pathKey])) {
                    $platform = $platformHints[$pathKey];
                } else {
                    $platform = $this->detectPlatform($remote);
                }
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
            // 上抛给 discover() 聚合进 __errors__——否则 Jenkins 宕机会被前端误判为「没有可发现的项目」
            throw $e;
        }
        return $found;
    }

    // ── GitLab CI ──

    /**
     * @param list<string> $activeRemotes
     * @param list<string> $existingNames
     * @return list<DiscoveredItem>
     */
    private function scanGitlabCi(array $activeRemotes, array $existingNames): array
    {
        $found = [];
        $glCfg = $this->config->getGitlabConfig();
        $base  = rtrim($glCfg['base_url'] ?? '', '/');
        if (empty($base) || !$this->gitlabClient) {
            return $found;
        }
        // 已配置才计入扫描源（未配置的源不算「不可达」）
        $this->scanSources[] = 'GitLab CI';

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
            // 上抛给 discover() 聚合进 __errors__——否则 GitLab 宕机/Token 失效会被误判为「空列表」
            throw $e;
        }
        return $found;
    }

    // ── Gitea Actions ──

    /**
     * @param list<string> $activeRemotes
     * @param list<string> $existingNames
     * @return list<DiscoveredItem>
     */
    private function scanGiteaCi(array $activeRemotes, array $existingNames): array
    {
        $found = [];
        $giteaCfg = $this->config->getGiteaConfig();
        $base  = rtrim($giteaCfg['base_url'] ?? '', '/');
        if (empty($base) || !$this->giteaClient) {
            return $found;
        }
        // 已配置才计入扫描源（未配置的源不算「不可达」）
        $this->scanSources[] = 'Gitea Actions';

        try {
            $page = 1;
            $seen = [];  // 归一化 key，仅本 provider 内去重
            while ($page <= 10) {
                $resp = $this->giteaClient->get("{$base}/api/v1/user/repos?limit=100&page={$page}");
                if ($resp->getStatusCode() === 401) {
                    throw new \RuntimeException('Gitea Token 无效，请检查 GITEA_TOKEN');
                }
                if ($resp->getStatusCode() >= 400) {
                    break;
                }
                $data = json_decode($resp->getBody(), true);
                if (!is_array($data) || empty($data)) {
                    break;
                }

                foreach ($data as $p) {
                    $fullName = $p['full_name'] ?? '';
                    $remote   = $p['clone_url'] ?? '';
                    $rKey     = $remote ? $this->normalizeRemote($remote) : '';
                    // 该仓库已被启用集合内的已有 active 记录映射（归一化 key 比对，跨源生效）
                    if ($rKey && in_array($rKey, $activeRemotes)) {
                        continue;
                    }
                    // 本 provider 内同一仓库不重复显示
                    if ($rKey && in_array($rKey, $seen)) {
                        continue;
                    }
                    if (in_array($fullName, $existingNames)) {
                        continue;
                    }
                    if ($rKey) {
                        $seen[] = $rKey;
                    }

                    $found[] = ['entry' => [
                        'job_name'       => $fullName,
                        'build_provider' => AppConfig::PROVIDER_GITEA_CI,
                        'git_platform'   => 'gitea',
                        'git_remote'     => $remote,
                        'current_path'   => $fullName,
                        'project_id'     => null,
                        'web_url'        => $p['html_url'] ?? '',
                        'harbor_repository' => '',
                    ], 'source' => 'gitea_ci'];
                }
                $page++;
            }
        } catch (\Exception $e) {
            $this->logger?->warning('AutoDiscover Gitea Actions 扫描失败', ['error' => $e->getMessage()]);
            // 上抛给 discover() 聚合进 __errors__——否则 Gitea 宕机/Token 失效会被误判为「空列表」
            throw $e;
        }
        return $found;
    }

    // ── Git 平台扫描（与 BUILD_MODE / custom_push 开关无关） ──

    /**
     * 扫描已配置的 Git 平台项目，build_provider 按平台原生 CI 标记：
     * GitLab→gitlab_ci、Gitea→gitea_ci；无原生拉取式 provider 的平台才回落 custom_push。
     * 原生 CI 已启用（∈ $enabledProviders）的平台由其专属扫描覆盖，这里跳过避免重复。
     * 目前支持 GitLab（通过已有 gitlabClient）；其他平台可后续扩展。
     *
     * @param list<string> $activeRemotes 调用方须并入本轮已发现项目的 remote key，避免跨扫描重复导入
     * @param list<string> $existingNames
     * @param list<string> $enabledProviders 当前 BUILD_MODE 启用的拉取式 provider
     * @return list<DiscoveredItem>
     */
    private function scanGitPlatforms(array $activeRemotes, array $existingNames, array $enabledProviders): array
    {
        $found = [];

        // GitLab：原生 CI（gitlab_ci）启用时由 scanGitlabCi() 覆盖，跳过避免重复扫描
        $glCfg = $this->config->getGitlabConfig();
        $base  = rtrim($glCfg['base_url'] ?? '', '/');
        if (!empty($base) && $this->gitlabClient && !in_array(AppConfig::PROVIDER_GITLAB_CI, $enabledProviders, true)) {
            // 已配置才计入扫描源（未配置的源不算「不可达」）
            $this->scanSources[] = 'Git';
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
                                'build_provider' => AppConfig::PROVIDER_GITLAB_CI,
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
                // 上抛给 discover() 聚合进 __errors__——否则 GitLab 宕机会被误判为「空列表」
                throw $e;
            }
        }

        // TODO: GitHub / Gitee / Gitea 项目列表 API 对接（按需扩展）

        return $found;
    }

    // ── helpers ──

    /**
     * 归一化 Git remote URL 为「host/org/repo」去重键，用于跨协议去重。
     * 委托给 App\Helper\GitRemote::normalize()（可单测的公共助手）。
     */
    private function normalizeRemote(string $remote): string
    {
        return \App\Helper\GitRemote::normalize($remote);
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
     * 收集「仓库路径 → 平台」提示表（供 Jenkins 扫描纠正自建平台误识别）。
     * 同路径多平台并存时保留先扫描到的（GitLab CI 先于 Gitea Actions）。
     *
     * @param array<string,string> $hints
     * @param list<DiscoveredItem> $batch
     */
    private function collectPlatformHints(array &$hints, array $batch): void
    {
        foreach ($batch as $item) {
            $path     = $item['entry']['current_path'] ?? '';
            $platform = $item['entry']['git_platform'] ?? '';
            if (is_string($path) && $path !== '' && is_string($platform) && $platform !== '') {
                $hints[mb_strtolower($path)] ??= $platform;
            }
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
