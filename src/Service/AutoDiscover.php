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
        $existingRemotes = $this->existingRemotes();
        $errors    = [];
        $found     = [];

        if (in_array($buildMode, ['jenkins', 'both'])) {
            try {
                $found = array_merge($found, $this->scanJenkins($existingRemotes));
            } catch (\Exception $e) {
                $errors[] = 'Jenkins: ' . $e->getMessage();
            }
        }

        if (in_array($buildMode, ['gitlab_ci', 'both'])) {
            try {
                $found = array_merge($found, $this->scanGitlabCi($existingRemotes));
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
        $maps  = $this->config->getJobGitMap();
        // dedup key: "job_name|build_provider" — 同仓库可被 Jenkins 和 GitLab CI 同时管理
        $existingKeys = [];
        foreach ($maps as $m) {
            $existingKeys[] = ($m['job_name'] ?? '') . '|' . ($m['build_provider'] ?? 'jenkins');
        }
        foreach ($discovered as $item) {
            $e = $item['entry'];
            $key = ($e['job_name'] ?? '') . '|' . ($e['build_provider'] ?? 'jenkins');
            if (in_array($key, $existingKeys)) continue;
            $maps[] = $e;
            $existingKeys[] = $key;
            $saved++;
        }
        if ($saved > 0) $this->config->saveJobGitMap($maps);
        return $saved;
    }

    // ── Jenkins ──

    private function scanJenkins(array &$existingRemotes): array
    {
        $found = [];
        try {
            foreach ($this->jenkins->getAllJobs() as $jobName) {
                $remotes  = $this->jenkins->getGitRemotes($jobName);
                $remote   = $remotes[0] ?? '';
                $dedupKey = $remote ? ('jenkins|' . $remote) : '';
                if ($remote && in_array($dedupKey, $existingRemotes)) continue;
                $platform = $this->detectPlatform($remote);

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
                if ($remote) $existingRemotes[] = $dedupKey;
            }
        } catch (\Exception $e) {
            $this->logger?->warning('AutoDiscover Jenkins 扫描失败', ['error' => $e->getMessage()]);
        }
        return $found;
    }

    // ── GitLab CI ──

    private function scanGitlabCi(array &$existingRemotes): array
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
            while ($page <= 10) {
                $resp = $client->get("{$base}/api/v4/projects?per_page=100&page={$page}&membership=true&order_by=last_activity_at");
                $data = json_decode($resp->getBody(), true);
                if (!is_array($data) || empty($data)) break;

                foreach ($data as $p) {
                    $path = $p['path_with_namespace'] ?? '';
                    $pid  = $p['id'] ?? 0;
                    $remote = $p['http_url_to_repo'] ?? '';
                    $dedupKey = $remote ? ('gitlab_ci|' . $remote) : '';
                    if ($remote && in_array($dedupKey, $existingRemotes)) continue;
                    // 检查是否真的在用 CI：有 pipeline 记录才加入
                    if ($pid && !$this->hasPipelines($client, $base, $pid)) continue;
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
                    if ($remote) $existingRemotes[] = $dedupKey;
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
     * 已存在的 git_remote 集合（去重依据：同一 git 仓库只保留一条映射）
     */
    private function existingRemotes(): array
    {
        // key: "provider|remote" — 同一个 git 仓库可被 Jenkins 和 GitLab CI 同时管理
        $keys = [];
        foreach ($this->config->getJobGitMap() as $m) {
            $provider = $m['build_provider'] ?? 'jenkins';
            $remote = $m['git_remote'] ?? '';
            if ($remote) $keys[] = $provider . '|' . $remote;
        }
        return $keys;
    }

    private function detectPlatform(string $remote): string
    {
        if (empty($remote)) return $this->config->getDefaultGitPlatform();
        try { return $this->gitRegistry->detect($remote); }
        catch (\Exception $e) { return $this->config->getDefaultGitPlatform(); }
    }

    private function hasPipelines(Client $client, string $base, int $projectId): bool
    {
        try {
            $resp = $client->get("{$base}/api/v4/projects/{$projectId}/pipelines?per_page=1");
            $data = json_decode($resp->getBody(), true);
            return is_array($data) && !empty($data);
        } catch (\Exception $e) {
            return false;
        }
    }

    private function extractPath(string $remote, string $jobName): string
    {
        if (preg_match('#[:/]([^/]+/[^/]+?)(\.git)?$#', $remote, $m)) {
            return $m[1];
        }
        return $jobName;
    }
}
