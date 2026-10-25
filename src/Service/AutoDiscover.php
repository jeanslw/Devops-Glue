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
    private ?Client $githubClient = null;
    /** @var list<string> 本次实际发起扫描的源（已启用且已配置），供前端区分扫描全部失败与部分失败。 */
    private array $scanSources = [];

    public function __construct(JenkinsService $jenkins, ProviderRegistry $gitRegistry, Settings $config, MappingManager $mapping, ?Logger $logger = null, ?Client $gitlabClient = null, ?Client $giteaClient = null, ?Client $githubClient = null)
    {
        $this->jenkins      = $jenkins;
        $this->gitRegistry  = $gitRegistry;
        $this->config       = $config;
        $this->mapping      = $mapping;
        $this->logger       = $logger;
        $this->gitlabClient = $gitlabClient;
        $this->giteaClient  = $giteaClient;
        $this->githubClient = $githubClient;
    }

    /** @return array{found: list<DiscoveredItem>, errors: list<string>, sources: list<string>} */
    public function discover(): array
    {
        $this->scanSources = [];
        $enabled = $this->mapping->activeBuildProviders();





        $cpEnabled = $this->mapping->hasCustomPush();
        $activeRemotes = [];
        $existingNames = [];
        foreach ($this->mapping->allMaps() as $m) {
            $bp = (string) ($m['build_provider'] ?? '');


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




        $platformHints = [];

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

        if (in_array(AppConfig::PROVIDER_GITHUB_ACTIONS, $enabled, true)) {
            try {
                $batch = $this->scanGithubActions($activeRemotes, $existingNames);
                $this->collectPlatformHints($platformHints, $batch);
                $found = array_merge($found, $batch);
            } catch (\Exception $e) {
                $errors[] = 'GitHub Actions: ' . $e->getMessage();
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





        try {
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



        $names = [];
        foreach ($maps as $m) {
            $bp = (string) ($m['build_provider'] ?? '');
            if (!in_array($bp, AppConfig::BUILTIN_PULL_PROVIDERS, true)) {
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
     * @param array<string,string> $platformHints 同轮平台扫描得到的 仓库路径(小写) => git_platform 提示表
     * @return list<DiscoveredItem>
     */
    private function scanJenkins(array $activeRemotes, array $existingNames, array $platformHints = []): array
    {
        $found = [];
        $seen  = [];
        try {
            foreach ($this->jenkins->getAllJobs() as $jobName) {
                $remotes  = $this->jenkins->getGitRemotes($jobName);
                $remote   = $remotes[0] ?? '';
                $rKey     = $remote ? $this->normalizeRemote($remote) : '';

                if ($rKey && in_array($rKey, $activeRemotes)) {
                    continue;
                }

                if ($rKey && in_array($rKey, $seen)) {
                    continue;
                }
                if (in_array($jobName, $existingNames)) {
                    continue;
                }


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
                    'registry_repository' => '',
                ], 'source' => 'jenkins'];
            }
        } catch (\Exception $e) {
            $this->logger?->warning('AutoDiscover Jenkins 扫描失败', ['error' => $e->getMessage()]);

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

        $this->scanSources[] = 'GitLab CI';

        try {
            $test = $this->gitlabClient->get("{$base}/api/v4/user");
            if ($test->getStatusCode() === 401) {
                throw new \RuntimeException('GitLab Token 无效，请检查后台平台连接里的 GitLab Token');
            }
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
                    $pid  = $p['id'] ?? 0;
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
                        'registry_repository' => '',
                    ], 'source' => 'gitlab_ci'];
                }
                $page++;
            }
        } catch (\Exception $e) {
            $this->logger?->warning('AutoDiscover GitLab CI 扫描失败', ['error' => $e->getMessage()]);

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

        $this->scanSources[] = 'Gitea Actions';

        try {
            $page = 1;
            $seen = [];
            while ($page <= 10) {
                $resp = $this->giteaClient->get("{$base}/api/v1/user/repos?limit=100&page={$page}");
                if ($resp->getStatusCode() === 401) {
                    throw new \RuntimeException('Gitea Token 无效，请检查后台平台连接里的 Gitea Token');
                }
                if ($resp->getStatusCode() >= 500) {
                    throw new \RuntimeException('Gitea 服务暂时不可用（HTTP ' . $resp->getStatusCode() . '）');
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

                    if ($rKey && in_array($rKey, $activeRemotes)) {
                        continue;
                    }

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
                        'registry_repository' => '',
                    ], 'source' => 'gitea_ci'];
                }
                $page++;
            }
        } catch (\Exception $e) {
            $this->logger?->warning('AutoDiscover Gitea Actions 扫描失败', ['error' => $e->getMessage()]);

            throw $e;
        }
        return $found;
    }

    // ── GitHub Actions ──

    /**
     * @param list<string> $activeRemotes
     * @param list<string> $existingNames
     * @return list<DiscoveredItem>
     */
    private function scanGithubActions(array $activeRemotes, array $existingNames): array
    {
        $found = [];
        $githubCfg = $this->config->getGithubConfig();
        $base = rtrim($githubCfg['base_url'] ?? 'https://api.github.com', '/');
        if (empty($base) || !$this->githubClient) {
            return $found;
        }

        $this->scanSources[] = 'GitHub Actions';

        try {
            $page = 1;
            $seen = [];
            while ($page <= 10) {
                $resp = $this->githubClient->get("{$base}/user/repos?per_page=100&page={$page}");
                if ($resp->getStatusCode() === 401) {
                    throw new \RuntimeException('GitHub Token 无效，请检查后台平台连接里的 GitHub Token');
                }
                if ($resp->getStatusCode() >= 500) {
                    throw new \RuntimeException('GitHub 服务暂时不可用（HTTP ' . $resp->getStatusCode() . '）');
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

                    if ($rKey && in_array($rKey, $activeRemotes)) {
                        continue;
                    }

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
                        'build_provider' => AppConfig::PROVIDER_GITHUB_ACTIONS,
                        'git_platform'   => 'github',
                        'git_remote'     => $remote,
                        'current_path'   => $fullName,
                        'project_id'     => null,
                        'web_url'        => $p['html_url'] ?? '',
                        'registry_repository' => '',
                    ], 'source' => 'github_actions'];
                }
                $page++;
            }
        } catch (\Exception $e) {
            $this->logger?->warning('AutoDiscover GitHub Actions 扫描失败', ['error' => $e->getMessage()]);

            throw $e;
        }
        return $found;
    }

    // ── Git 平台扫描（与 BUILD_MODE / custom_push 开关无关） ──

    /**
     * 扫描已配置的 Git 平台项目，build_provider 按平台原生 CI 标记：
     * GitLab 为 gitlab_ci、Gitea 为 gitea_ci；无原生 provider 的平台用 custom_push。
     * 原生 CI 已启用（∈ $enabledProviders）的平台由其专属扫描覆盖，这里跳过避免重复。
     * 目前仅支持 GitLab（复用 gitlabClient），其他平台按需扩展。
     *
     * @param list<string> $activeRemotes 调用方须并入本轮已发现项目的 remote key，避免重复导入
     * @param list<string> $existingNames
     * @param list<string> $enabledProviders 当前 BUILD_MODE 启用的拉取式 provider
     * @return list<DiscoveredItem>
     */
    private function scanGitPlatforms(array $activeRemotes, array $existingNames, array $enabledProviders): array
    {
        $found = [];


        $glCfg = $this->config->getGitlabConfig();
        $base  = rtrim($glCfg['base_url'] ?? '', '/');
        if (!empty($base) && $this->gitlabClient && !in_array(AppConfig::PROVIDER_GITLAB_CI, $enabledProviders, true)) {
            $this->scanSources[] = 'Git';
            try {
                $test = $this->gitlabClient->get("{$base}/api/v4/user");

                if ($test->getStatusCode() === 401) {
                    throw new \RuntimeException('GitLab Token 无效，请检查后台平台连接里的 GitLab Token');
                }
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
                            'registry_repository' => '',
                        ], 'source' => 'gitlab'];
                    }
                    $page++;
                }
            } catch (\Exception $e) {
                $this->logger?->warning('AutoDiscover Git 平台扫描失败 (GitLab)', ['error' => $e->getMessage()]);

                throw $e;
            }
        }



        return $found;
    }

    // ── helpers ──

    /**
     * 归一化 Git remote URL 为 host/org/repo 去重键，用于跨协议去重；委托 GitRemote::normalize()。
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
     * 收集仓库路径到平台的提示表（供 Jenkins 扫描纠正自建平台误识别）。
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
