<?php
namespace App\Service;

use App\Service\Git\ProviderRegistry;
use App\Config\AppConfig;

class AutoDiscover
{
    private JenkinsService $jenkins;
    private ProviderRegistry $gitRegistry;
    private AppConfig $config;
    private ?Logger $logger;

    public function __construct(JenkinsService $jenkins, ProviderRegistry $gitRegistry, AppConfig $config, ?Logger $logger = null)
    {
        $this->jenkins    = $jenkins;
        $this->gitRegistry = $gitRegistry;
        $this->config     = $config;
        $this->logger     = $logger;
    }

    /**
     * 扫描 Jenkins + GitLab CI，返回发现的条目，已存在的跳过
     */
    public function discover(): array
    {
        $existing = $this->existingJobNames();
        $found = [];

        // ── Jenkins ──
        try {
            foreach ($this->jenkins->getAllJobs() as $jobName) {
                if (in_array($jobName, $existing)) continue;
                $remotes = $this->jenkins->getGitRemotes($jobName);
                $remote  = $remotes[0] ?? '';
                $platform = $this->detectPlatform($remote);

                $entry = [
                    'job_name'           => $jobName,
                    'build_provider'     => 'jenkins',
                    'git_platform'       => $platform,
                    'git_remote'         => $remote,
                    'current_path'       => $this->extractPath($remote, $jobName),
                    'project_id'         => null,
                    'web_url'            => '',
                    'harbor_repository'  => '',
                ];
                $found[] = ['entry' => $entry, 'source' => 'jenkins'];
                $existing[] = $jobName;
            }
        } catch (\Exception $e) {
            $this->logger?->warning('AutoDiscover Jenkins 扫描失败', ['error' => $e->getMessage()]);
        }

        return $found;
    }

    /**
     * 把发现结果写入数据库（跳过已存在的 job_name）
     */
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
        if ($saved > 0) {
            $this->config->saveJobGitMap($maps);
        }
        return $saved;
    }

    private function existingJobNames(): array
    {
        return array_column($this->config->getJobGitMap(), 'job_name');
    }

    private function detectPlatform(string $remote): string
    {
        if (empty($remote)) return $this->config->getDefaultGitPlatform();
        try {
            return $this->gitRegistry->detect($remote);
        } catch (\Exception $e) {
            return $this->config->getDefaultGitPlatform();
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
