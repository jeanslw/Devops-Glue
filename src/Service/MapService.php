<?php
namespace App\Service;

use GuzzleHttp\Client;
use App\Service\Git\ProviderRegistry;
use App\Exceptions\ApiException;

class MapService
{
    private JenkinsService $jenkins;
    private ProviderRegistry $registry;
    private array $manualMap;
    private array $gitlabConfig;
    private string $cacheFile;
    private string $defaultPlatform;
    private array $autoCache = [];
    private ?Logger $logger = null;

    public function __construct(
        JenkinsService $jenkins,
        ProviderRegistry $registry,
        array $manualConfig = [],
        array $gitlabConfig = [],
        string $cacheFile = '',
        string $defaultPlatform = 'gitlab'
    ) {
        $this->jenkins = $jenkins;
        $this->registry = $registry;
        $this->gitlabConfig = $gitlabConfig;
        $this->cacheFile = $cacheFile;
        $this->defaultPlatform = $defaultPlatform;

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
            // Jenkins 找不到 → 尝试手动映射兜底（GitLab CI 项目）
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
                    'harbor_repository'=> $m['harbor_repository'] ?? '',
                ], $m);
            }
            return null;
        }

        $remotes = $this->jenkins->getGitRemotes($resolved['fullName']);
        $remote = $remotes[0] ?? '';
        $detection = $this->detectPlatform($remote);
        $platform = $detection['platform'];
        $detectionMethod = $detection['method'];

        // 基础自动数据（可以设置一些默认字段）
        $base = [
            'job_name'         => $jobName,
            'git_platform'     => $platform,
            'platform_source'  => 'auto',          // auto | manual（是否由 job_git_map 手动指定）
            'detection_method' => $detectionMethod, // exact | fallback（URL 匹配结果，仅 auto 时有意义）
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
                if ($key !== 'job_name' && $value !== null && $value !== '') {
                    $base[$key] = $value;
                }
            }
            // 如果手动指定了 git_platform，标记为手动选择（不再兜底）
            if (!empty($this->manualMap[$jobName]['git_platform'])) {
                $base['platform_source']  = 'manual';
                $base['detection_method'] = 'manual';
            }
        }

        // GitLab API v4 需要 project_id（数字），其他平台用 owner/repo 路径即可
        // 如果手动未填写且是 GitLab，则自动通过 API 查询
        if (empty($base['project_id']) && $platform === 'gitlab') {
            $base['project_id'] = $this->getGitlabIdWithCache($jobName, $remote);
        }

        return $base;
    }

    public function setLogger(Logger $logger): void
    {
        $this->logger = $logger;
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
                'url'              => $url,
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
        if (!$this->cacheFile) {
            return;
        }
        $content = "<?php\nreturn " . var_export($this->autoCache, true) . ";\n";
        file_put_contents($this->cacheFile, $content);
    }
}
