<?php

namespace App\Service;

use GuzzleHttp\Client;
use App\Service\Git\ProviderRegistry;
use App\Exceptions\ApiException;

class GitRemoteResolver
{
    private JenkinsService $jenkins;
    private ProviderRegistry $registry;
    /** @var array<string,array<string,mixed>> */
    private array $manualMap;
    /** @var array<string,mixed> */
    private array $gitlabConfig;
    private string $cacheFile;
    private string $defaultPlatform;
    /** @var array<string,int|null> */
    private array $autoCache = [];
    private ?Logger $logger = null;
    private ?Client $gitlabClient = null;

    /**
     * @param array<array-key,array<string,mixed>> $manualConfig
     * @param array<string,mixed>                  $gitlabConfig
     */
    public function __construct(
        JenkinsService $jenkins,
        ProviderRegistry $registry,
        array $manualConfig = [],
        array $gitlabConfig = [],
        string $cacheFile = '',
        string $defaultPlatform = 'gitlab',
        ?Logger $logger = null,
        ?Client $gitlabClient = null
    ) {
        $this->jenkins = $jenkins;
        $this->registry = $registry;
        $this->gitlabConfig = $gitlabConfig;
        $this->cacheFile = $cacheFile;
        $this->defaultPlatform = $defaultPlatform;
        $this->logger = $logger;
        $this->gitlabClient = $gitlabClient;


        $this->manualMap = [];
        foreach ($manualConfig as $item) {
            $this->manualMap[$item['job_name']] = $item;
        }


        $this->autoCache = [];
        if ($cacheFile && file_exists($cacheFile)) {
            $loaded = include $cacheFile;
            if (is_array($loaded)) {
                $this->autoCache = $loaded;
            }
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function getAllMaps(): array
    {
        $jobs = $this->jenkins->getAllJobs();
        $maps = [];
        foreach ($jobs as $jobName) {
            $map = $this->getByJobName($jobName);
            if ($map !== null) {
                $maps[] = $map;
            }
        }
        return $maps;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function getByJobName(string $jobName): ?array
    {
        try {
            $resolved = $this->jenkins->resolvePath($jobName);
        } catch (\RuntimeException $e) {
            $this->logger?->warning('Jenkins 不可达，resolvePath 降级走手动映射兜底', [
                'job'   => $jobName,
                'error' => $e->getMessage(),
            ]);
            $resolved = null;
        }
        if (!$resolved || $resolved['type'] !== 'job') {
            if (isset($this->manualMap[$jobName])) {
                $m = $this->manualMap[$jobName];
                $platform = $m['git_platform'] ?? $this->defaultPlatform;
                $remote   = $m['git_remote'] ?? '';
                return array_merge([
                    'job_name'         => $jobName,
                    'git_platform'     => $platform,
                    'platform_source'  => 'manual',
                    'detection_method' => 'manual',
                    'git_remote'       => $remote,
                    'status'           => 'synced',
                    'message'          => '',
                    'project_id'       => $m['project_id'] ?? null,
                    'web_url'          => $m['web_url'] ?? '',
                    'current_path'     => $m['current_path'] ?? '',
                    'registry_repository' => $m['registry_repository'] ?? '',
                ], $m);
            }
            return null;
        }

        $remotes = $this->jenkins->getGitRemotes($resolved['fullName']);
        $remote = $remotes[0] ?? '';
        $detection = $this->detectPlatform($remote);
        $platform = $detection['platform'];
        $detectionMethod = $detection['method'];


        $base = [
            'job_name'         => $jobName,
            'git_platform'     => $platform,
            'platform_source'  => 'auto',
            'detection_method' => $detectionMethod,
            'git_remote'   => $remote,
            'status'       => 'synced',
            'message'      => '',
            'debug'        => [
                'step1_try_jobname' => $jobName,
                'step1_result'      => 'SUCCESS',
            ],

            'project_id'   => null,
            'web_url'      => '',
            'current_path' => '',
            'registry_repository' => '',
        ];


        if (isset($this->manualMap[$jobName])) {
            foreach ($this->manualMap[$jobName] as $key => $value) {
                if ($key !== 'job_name' && $value !== null && $value !== '') {
                    $base[$key] = $value;
                }
            }

            if (!empty($this->manualMap[$jobName]['git_platform'])) {
                $base['platform_source']  = 'manual';
                $base['detection_method'] = 'manual';
            }
        }



        if (empty($base['project_id']) && $platform === 'gitlab') {
            $base['project_id'] = $this->getGitlabIdWithCache($jobName, $remote);
        }

        return $base;
    }

    /**
     * 根据 Git remote URL 检测所属平台（委托给 ProviderRegistry）
     *
     * @param string $url Git remote URL
     * @return array{platform: string, method: string} 平台名 + 检测方式（exact | fallback）
     */
    private function detectPlatform(string $url): array
    {
        try {
            return ['platform' => $this->registry->detect($url), 'method' => 'exact'];
        } catch (ApiException $e) {
            $this->logger?->warning('Git 平台检测失败，回退默认平台', [
                'url'              => \App\Helper\GitRemote::maskCredentials($url),
                'default_platform' => $this->defaultPlatform,
                'error'            => $e->getMessage(),
            ]);
            return ['platform' => $this->defaultPlatform, 'method' => 'fallback'];
        }
    }

    private function getGitlabIdWithCache(string $jobName, string $remote): ?int
    {
        if (array_key_exists($jobName, $this->autoCache)) {
            return $this->autoCache[$jobName];
        }

        $projectPath = $this->extractProjectPath($remote);
        if (!$projectPath) {
            return null;
        }

        $id = $this->fetchGitlabProjectId($projectPath);
        if ($id !== null) {
            $this->autoCache[$jobName] = $id;
            $this->saveAutoCache();
        }
        return $id;
    }

    /** 提取项目路径（保留 GitLab 子群组层级，供 fetchGitlabProjectId 编码后查询） */
    private function extractProjectPath(string $remote): ?string
    {
        return \App\Helper\GitRemote::extractPath($remote);
    }

    private function fetchGitlabProjectId(string $projectPath): ?int
    {
        $baseUrl = rtrim($this->gitlabConfig['base_url'] ?? '', '/');
        if (empty($baseUrl) || !$this->gitlabClient) {
            return null;
        }
        try {
            $resp = $this->gitlabClient->get($baseUrl . '/api/v4/projects/' . urlencode($projectPath));
            $data = json_decode($resp->getBody(), true);
            return $data['id'] ?? null;
        } catch (\Exception $e) {
            $this->logger?->warning("GitLab ID fetch failed", ['project' => $projectPath, 'error' => $e->getMessage()]);
            return null;
        }
    }

    private function saveAutoCache(): void
    {
        if (!$this->cacheFile) {
            return;
        }
        $content = "<?php\nreturn " . var_export($this->autoCache, true) . ";\n";
        file_put_contents($this->cacheFile, $content);
    }
}
