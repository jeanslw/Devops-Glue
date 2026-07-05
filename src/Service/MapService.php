<?php
namespace App\Service;

use GuzzleHttp\Client;

class MapService
{
    private JenkinsService $jenkins;
    private array $manualMap;
    private array $gitlabConfig;
    private string $cacheFile;
    private array $autoCache = [];
    private ?Logger $logger = null;

    public function __construct(
        JenkinsService $jenkins,
        array $manualConfig = [],
        array $gitlabConfig = [],
        string $cacheFile = ''
    ) {
        $this->jenkins = $jenkins;
        $this->gitlabConfig = $gitlabConfig;
        $this->cacheFile = $cacheFile;

        // 索引化手动配置
        $this->manualMap = [];
        foreach ($manualConfig as $item) {
            $this->manualMap[$item['job_name']] = $item;
        }

        // 初始化缓存数组
        $this->autoCache = [];
        if ($cacheFile && file_exists($cacheFile)) {
            $loaded = include $cacheFile;
            if (is_array($loaded)) {
                $this->autoCache = $loaded;
            }
        }
    }

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

    public function getByJobName(string $jobName): ?array
    {
        $resolved = $this->jenkins->resolvePath($jobName);
        if (!$resolved || $resolved['type'] !== 'job') {
            return null;
        }

        $remotes = $this->jenkins->getGitRemotes($resolved['fullName']);
        $remote = $remotes[0] ?? '';
        $platform = $this->detectPlatform($remote);

        // 基础自动数据（可以设置一些默认字段）
        $base = [
            'job_name'     => $jobName,
            'git_platform' => $platform,
            'git_remote'   => $remote,
            'status'       => 'synced',
            'message'      => '',
            'debug'        => [
                'step1_try_jobname' => $jobName,
                'step1_result'      => 'SUCCESS',
            ],
            // 常用字段给个默认值，让外面知道有这个键
            'project_id'   => null,
            'web_url'      => '',
            'current_path' => '',
            'harbor_repository' => '',
        ];

        // 动态合并：把手动配置的所有字段（除 job_name）都覆盖进来
        if (isset($this->manualMap[$jobName])) {
            foreach ($this->manualMap[$jobName] as $key => $value) {
                if ($key !== 'job_name') {
                    $base[$key] = $value;
                }
            }
        }

        // 自动获取 GitLab 的 project_id（如果手动没填，且是 GitLab）
        if (empty($base['project_id']) && $platform === 'gitlab') {
            $base['project_id'] = $this->getGitlabIdWithCache($jobName, $remote);
        }

        return $base;
    }
    public function setLogger(Logger $logger): void
    {
        $this->logger = $logger;
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

    private function extractProjectPath(string $remote): ?string
    {
        if (preg_match('#[:/]([^/]+/[^/]+?)(\.git)?$#', $remote, $matches)) {
            return $matches[1];
        }
        return null;
    }

    private function fetchGitlabProjectId(string $projectPath): ?int
    {
        $baseUrl = rtrim($this->gitlabConfig['base_url'] ?? '', '/');
        $token   = $this->gitlabConfig['token'] ?? '';
        if (empty($baseUrl) || empty($token)) {
            return null;
        }
        try {
            $client = new Client([
                'headers' => ['PRIVATE-TOKEN' => $token],
                'timeout' => 10,
            ]);
            $resp = $client->get($baseUrl . '/api/v4/projects/' . urlencode($projectPath));
            $data = json_decode($resp->getBody(), true);
            return $data['id'] ?? null;
        } catch (\Exception $e) {
            $this->logger?->warning("GitLab ID fetch failed", ['project' => $projectPath, 'error' => $e->getMessage()]);
            return null;
        }
    }

    private function saveAutoCache(): void
    {
        if (!$this->cacheFile) return;
        $content = "<?php\nreturn " . var_export($this->autoCache, true) . ";\n";
        file_put_contents($this->cacheFile, $content);
    }

    private function detectPlatform(string $url): string
    {
        if (str_contains($url, 'gitlab') || str_contains($url, '192.168.137.5:8082')) {
            return 'gitlab';
        }
        if (str_contains($url, 'gitee.com') || str_contains($url, 'gitee')) {
            return 'gitee';
        }
        if (str_contains($url, 'github.com') || str_contains($url, 'github')) {
            return 'github';
        }
        return 'gitlab';
    }
}