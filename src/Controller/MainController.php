<?php
namespace App\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Service\JenkinsService;
use App\Service\MapService;
use App\Service\HarborService;
use App\Config\AppConfig;

class MainController extends BaseController
{
    private JenkinsService $jenkins;
    private MapService $map;
    private AppConfig $config;
    private ?HarborService $harbor;

    public function __construct(JenkinsService $jenkins, MapService $map, AppConfig $config, ?HarborService $harbor = null)
    {
        $this->jenkins = $jenkins;
        $this->map = $map;
        $this->config = $config;
        $this->harbor = $harbor;
    }

    /**
     * 获取所有 Job 列表
     */
    public function jobsList(Request $request, Response $response): Response
    {
        $buildMode = $_ENV['BUILD_MODE'] ?? 'both';
        $jobs = [];
        if ($buildMode === 'gitlab_ci') {
            foreach ($this->config->getJobGitMap() as $m) {
                if (($m['build_provider'] ?? 'jenkins') === 'gitlab_ci') $jobs[] = $m['job_name'];
            }
        } else {
            // jenkins/both：尝试实时获取，失败降级读 DB
            try {
                $jobs = $this->jenkins->getAllJobs();
            } catch (\Exception $e) {
                foreach ($this->config->getJobGitMap() as $m) {
                    if (($m['status'] ?? 'active') === 'disabled') continue;
                    if (($m['build_provider'] ?? 'jenkins') !== 'gitlab_ci') $jobs[] = $m['job_name'];
                }
            }
        }
        return $this->output($response, $jobs, $request);
    }

    /**
     * 获取三方映射关系（按项目分组），带 30s 缓存
     */
    public function mapList(Request $request, Response $response): Response
    {
        $cacheKey = 'map_list';
        $buildMode = $_ENV['BUILD_MODE'] ?? 'both';

        // 有缓存且未过期，直接返回（gitlab_ci 模式跳过缓存，避免 Jenkins 旧数据）
        if ($buildMode !== 'gitlab_ci') {
            try {
                $pdo = \App\Service\Database::getPdo();
                $cached = $pdo->prepare("SELECT value FROM cache WHERE cache_key = ? AND expires_at > ?");
                $cached->execute([$cacheKey, time()]);
                $row = $cached->fetch();
                if ($row) {
                    $data = json_decode($row['value'], true);
                    if (is_array($data)) {
                        return $this->output($response, $data, $request);
                    }
                }
            } catch (\Exception $e) {}
        }
        try {
            $maps = $this->config->getJobGitMap();
            // 过滤禁用 + 模式筛选
            $maps = array_filter($maps, fn($m) => ($m['status'] ?? 'active') === 'active');
            if ($buildMode === 'gitlab_ci') {
                $maps = array_values(array_filter($maps, fn($m) => ($m['build_provider'] ?? 'jenkins') === 'gitlab_ci'));
            } elseif ($buildMode === 'jenkins') {
                $maps = array_values(array_filter($maps, fn($m) => ($m['build_provider'] ?? 'jenkins') !== 'gitlab_ci'));
            }
        } catch (\Exception $e) {
            $maps = [];
        }

        $grouped = [];
        foreach ($maps as $map) {
            $key = $map['current_path'] ?? '';
            if (empty($key)) {
                $key = $this->extractProjectPath($map['git_remote'] ?? '');
            }
            if (empty($key)) {
                $key = $map['job_name'];
            }

            if (!isset($grouped[$key])) {
                $grouped[$key] = [
                    'git_platform'      => $map['git_platform'],
                    'build_provider'    => $map['build_provider'] ?? 'jenkins',
                    'git_remote'        => $map['git_remote'],
                    'project_id'        => $map['project_id'] ?? null,
                    'web_url'           => $map['web_url'] ?? '',
                    'harbor_repository' => $map['harbor_repository'] ?? '',
                    'platform_source'   => $map['platform_source'] ?? 'auto',
                    'detection_method'  => $map['detection_method'] ?? '',
                    'jobs'              => [],
                ];
            }
            $grouped[$key]['jobs'][] = $map['job_name'];
        }

        foreach ($grouped as &$item) {
            $item['jobs'] = array_unique($item['jobs']);
            sort($item['jobs']);
        }

        // 写入缓存（30s TTL）
        try {
            $pdo = \App\Service\Database::getPdo();
            $stmt = $pdo->prepare("INSERT OR REPLACE INTO cache (cache_key, value, expires_at) VALUES (?, ?, ?)");
            $stmt->execute(['map_list', json_encode($grouped, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), time() + 30]);
        } catch (\Exception $e) {
            // 缓存写入失败不影响响应
        }

        return $this->output($response, $grouped, $request);
    }

    /**
     * 获取已接入的 Git 平台列表（静态配置）
     */
    public function gitPlatforms(Request $request, Response $response): Response
    {
        $data = [
            'git_platforms' => $this->config->getGitPlatformsConfig(),
            'harbor'        => $this->config->getHarborApiInfo(),
        ];
        return $this->output($response, $data, $request);
    }

    /**
     * 发现实际使用的 Git 平台与配置差异
     */
    public function gitDiscovery(Request $request, Response $response): Response
    {
        $maps = $this->config->getJobGitMap();
        $usedPlatforms = [];
        foreach ($maps as $map) {
            $platform = $map['git_platform'] ?? '';
            if ($platform && !in_array($platform, $usedPlatforms)) {
                $usedPlatforms[] = $platform;
            }
        }

        $configured = [];
        $unconfigured = [];
        foreach ($usedPlatforms as $name) {
           if ($this->config->isPlatformConfigured($name)) {
    // 从配置数组中找出该平台的 api_base_url
    $apiBaseUrl = '';
        foreach ($this->config->getGitPlatformsConfig() as $cfg) {
            if (($cfg['name'] ?? '') === $name) {
                $apiBaseUrl = $cfg['api_base_url'] ?? '';
                break;
            }
        }
        $configured[] = [
            'name' => $name,
            'api_base_url' => $apiBaseUrl,
        ];
    } else {
                $exampleRemote = '';
                foreach ($maps as $map) {
                    if (($map['git_platform'] ?? '') === $name && !empty($map['git_remote'])) {
                        $exampleRemote = $map['git_remote'];
                        break;
                    }
                }
                $unconfigured[] = [
                    'name' => $name,
                    'example_remote' => $exampleRemote,
                ];
            }
        }

        $data = [
            'configured'   => $configured,
            'unconfigured' => $unconfigured,
        ];
        return $this->output($response, $data, $request);
    }

    /**
     * 健康检查端点
     */
    public function health(Request $request, Response $response): Response
    {
        $checks = [
            'jenkins'         => false,
            'jenkins_version' => null,
            'git'             => [],
            'harbor'          => false,
            'harbor_version'  => null,
        ];

        $buildMode = $_ENV['BUILD_MODE'] ?? 'both';
        if ($buildMode !== 'gitlab_ci') {
            try {
                $this->jenkins->getAllJobs();
                $checks['jenkins'] = true;
                $checks['jenkins_version'] = $this->jenkins->getVersion();
            } catch (\Exception $e) {
                $checks['jenkins'] = false;
            }
        } else {
            $checks['jenkins'] = null; // gitlab_ci 模式不查 Jenkins
        }

        // Git 平台连通性检查（直接从数据库读取，不调 Jenkins）
        $usedPlatforms = [];
        $maps = $this->config->getJobGitMap();
        foreach ($maps as $m) {
            $p = $m['git_platform'] ?? '';
            if ($p && !in_array($p, $usedPlatforms)) $usedPlatforms[] = $p;
        }
        // 没有映射数据时降级为所有已配置平台
        if (empty($usedPlatforms)) {
            $usedPlatforms = [];
        }

        // 构建已配置平台的索引（URL + 版本号）
        $configuredPlatforms = [];
        $platformVersions    = [];
        foreach ($this->config->getGitPlatformsConfig() as $p) {
            $configuredPlatforms[$p['name']] = $p['api_base_url'];
            $platformVersions[$p['name']]    = $p['api_version'] ?? '';
        }

        // 只检查 job_git_map 中实际引用的平台
        $usedPlatforms = array_values(array_intersect($usedPlatforms, array_keys($configuredPlatforms)));

        if (empty($usedPlatforms)) {
            $checks['git'] = null;
        } else {
            foreach ($usedPlatforms as $name) {
                $apiUrl = $configuredPlatforms[$name] ?? null;
                $reachable = false;
                if ($apiUrl) {
                    try {
                        $ch = curl_init($apiUrl);
                        curl_setopt_array($ch, [
                            CURLOPT_NOBODY           => true,
                            CURLOPT_RETURNTRANSFER   => true,
                            CURLOPT_TIMEOUT          => 3,
                            CURLOPT_CONNECTTIMEOUT   => 2,
                            CURLOPT_DNS_CACHE_TIMEOUT => 10,
                        ]);
                        curl_exec($ch);
                        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        $reachable = $httpCode > 0 && $httpCode < 500;
                        curl_close($ch);
                    } catch (\Exception $e) {
                        $reachable = false;
                    }
                }
                $checks['git'][] = [
                    'name'        => $name,
                    'api_version' => $platformVersions[$name] ?? '',
                    'reachable'   => $reachable,
                ];
            }
        }

        if ($this->harbor) {
            try {
                // 健康检查用短超时 client，不影响正常 Harbor 操作
                $qClient = new \GuzzleHttp\Client([
                    'base_uri' => $this->config->getHarborConfig()['url'] ?? '',
                    'auth'     => [$this->config->getHarborConfig()['username'] ?? 'admin', $this->config->getHarborConfig()['password'] ?? ''],
                    'timeout'  => 5,
                    'connect_timeout' => 3,
                    'http_errors' => false,
                ]);
                $resp = $qClient->get('/api/v2.0/projects');
                $checks['harbor'] = $resp->getStatusCode() < 500;
                $checks['harbor_version'] = 'v2';
            } catch (\Exception $e) {
                $checks['harbor'] = false;
            }
        } else {
            $checks['harbor'] = null;
        }

        $gitOk = $checks['git'] === null || !empty(array_filter($checks['git'], fn($g) => $g['reachable']));
        $allOk = $checks['jenkins'] && $gitOk && ($checks['harbor'] === true || $checks['harbor'] === null);
        $status = $allOk ? 'ok' : 'degraded';

        $data = [
            'status'   => $status,
            'checks'   => $checks,
            'app_env'  => $this->config->getAppEnv(),
            'time'     => date('Y-m-d H:i:s'),
        ];

        $response->getBody()->write(json_encode($data));
        $httpCode = $allOk ? 200 : 503;
        return $response->withStatus($httpCode)->withHeader('Content-Type', 'application/json');
    }

    /**
     * 从远程 URL 提取项目路径
     */
    private function extractProjectPath(string $remote): string
    {
        if (preg_match('#[:/]([^/]+/[^/]+?)(\.git)?$#', $remote, $matches)) {
            return $matches[1];
        }
        return '';
    }
}