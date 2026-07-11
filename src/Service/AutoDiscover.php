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
    private ?Logger $logger;

    public function __construct(JenkinsService $jenkins, ProviderRegistry $gitRegistry, AppConfig $config, ?Logger $logger = null)
    {
        $this->jenkins     = $jenkins;
        $this->gitRegistry = $gitRegistry;
        $this->config      = $config;
        $this->logger      = $logger;
    }

    /**
     * 扫描 Jenkins + GitLab CI，返回发现的条目
     */
    public function discover(): array
    {
        $buildMode = $_ENV['BUILD_MODE'] ?? 'both';
        $existing  = $this->existingJobNames();
        $found     = [];

        if (in_array($buildMode, ['jenkins', 'both'])) {
            $found = array_merge($found, $this->scanJenkins($existing));
        }

        if (in_array($buildMode, ['gitlab_ci', 'both'])) {
            $found = array_merge($found, $this->scanGitlabCi($existing));
        }

        return $found;
    }

    public function saveDiscovered(array $discovered): int
    {
        $saved = 0;
        $maps  = $this->config->getJobGitMap();
        $names = array_column($maps, 'job_name');
        foreach ($discovered as $item) {
            $e = $item['entry'];
            if (in_array($e['job_name'], $names)) continue;
            $maps[] = $e;
            $saved++;
        }
        if ($saved > 0) $this->config->saveJobGitMap($maps);
        return $saved;
    }

    // ── Jenkins ──

    private function scanJenkins(array &$existing): array
    {
        $found = [];
        try {
            foreach ($this->jenkins->getAllJobs() as $jobName) {
                if (in_array($jobName, $existing)) continue;
                $remotes  = $this->jenkins->getGitRemotes($jobName);
                $remote   = $remotes[0] ?? '';
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
                $existing[] = $jobName;
            }
        } catch (\Exception $e) {
            $this->logger?->warning('AutoDiscover Jenkins 扫描失败', ['error' => $e->getMessage()]);
        }
        return $found;
    }

    // ── GitLab CI ──

    private function scanGitlabCi(array &$existing): array
    {
        $found = [];
        $glCfg = $this->config->getGitlabConfig();
        $base  = rtrim($glCfg['base_url'] ?? '', '/');
        $token = $glCfg['token'] ?? '';
        if (empty($base) || empty($token)) return $found;

        try {
            $client = new Client([
                'headers' => ['PRIVATE-TOKEN' => $token],
                'timeout' => 10, 'connect_timeout' => 5,
            ]);
            $page = 1;
            while ($page <= 10) {
                $resp = $client->get("{$base}/api/v4/projects?per_page=100&page={$page}&membership=true&order_by=last_activity_at");
                $data = json_decode($resp->getBody(), true);
                if (!is_array($data) || empty($data)) break;

                foreach ($data as $p) {
                    $path = $p['path_with_namespace'] ?? '';
                    if (in_array($path, $existing)) continue;
                    $found[] = ['entry' => [
                        'job_name'       => $path,
                        'build_provider' => 'gitlab_ci',
                        'git_platform'   => 'gitlab',
                        'git_remote'     => $p['http_url_to_repo'] ?? '',
                        'current_path'   => $path,
                        'project_id'     => $p['id'] ?? null,
                        'web_url'        => $p['web_url'] ?? '',
                        'harbor_repository' => '',
                    ], 'source' => 'gitlab_ci'];
                    $existing[] = $path;
                }
                $page++;
            }
        } catch (\Exception $e) {
            $this->logger?->warning('AutoDiscover GitLab CI 扫描失败', ['error' => $e->getMessage()]);
        }
        return $found;
    }

    // ── helpers ──

    private function existingJobNames(): array
    {
        return array_column($this->config->getJobGitMap(), 'job_name');
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
