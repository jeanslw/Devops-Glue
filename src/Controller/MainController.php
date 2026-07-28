<?php
namespace App\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Service\JenkinsService;
use App\Service\HarborService;
use App\Service\MappingManager;
use App\Config\AppConfig;

class MainController extends BaseController
{
    private JenkinsService $jenkins;
    private AppConfig $config;
    private MappingManager $mapping;
    private ?HarborService $harbor;

    public function __construct(JenkinsService $jenkins, AppConfig $config, MappingManager $mapping, ?HarborService $harbor = null)
    {
        $this->jenkins = $jenkins;
        $this->config = $config;
        $this->mapping = $mapping;
        $this->harbor = $harbor;
    }

    /**
     * 获取所有 Job 列表
     */
    public function jobsList(Request $request, Response $response): Response
    {
        if ($this->mapping->buildMode() === AppConfig::BUILD_MODE_GITLAB_CI) {
            return $this->output($response, $this->mapping->activeJobNames(), $request);
        }
        try {
            return $this->output($response, $this->jenkins->getAllJobs(), $request);
        } catch (\Exception $e) {
            return $this->output($response, $this->mapping->activeJobNames(), $request);
        }
    }

    /**
     * 获取三方映射关系（按项目分组），带 30s 缓存
     */
    public function mapList(Request $request, Response $response): Response
    {
        $buildMode = $this->config->getBuildMode();
        $cacheKey = AppConfig::CACHE_KEY_MAP_LIST_PREFIX . $buildMode;

        // 有缓存且未过期，直接返回（gitlab_ci 模式跳过缓存，避免 Jenkins 旧数据）
        if ($buildMode !== AppConfig::BUILD_MODE_GITLAB_CI) {
            try {
                $pdo = \App\Service\Database::getPdo();
                $cached = $pdo->prepare("SELECT value FROM " . AppConfig::TABLE_CACHE . " WHERE cache_key = ? AND expires_at > ?");
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
            $maps = array_filter($maps, fn($m) => ($m['status'] ?? AppConfig::STATUS_ACTIVE) === AppConfig::STATUS_ACTIVE);
            if ($buildMode === AppConfig::BUILD_MODE_GITLAB_CI) {
                $maps = array_values(array_filter($maps, fn($m) => ($m['build_provider'] ?? AppConfig::PROVIDER_JENKINS) === AppConfig::PROVIDER_GITLAB_CI));
            } elseif ($buildMode === AppConfig::BUILD_MODE_JENKINS) {
                $maps = array_values(array_filter($maps, fn($m) => ($m['build_provider'] ?? AppConfig::PROVIDER_JENKINS) !== AppConfig::PROVIDER_GITLAB_CI));
            }
        } catch (\Exception $e) {
            $maps = [];
        }

        $grouped = [];
        foreach ($maps as $map) {
            $key = $map['job_name'] ?? '';
            if (empty($key)) {
                $key = $map['current_path'] ?? '';
            }
            if (empty($key)) {
                $key = $this->extractProjectPath($map['git_remote'] ?? '');
            }

            if (!isset($grouped[$key])) {
                $grouped[$key] = [
                    'git_platform'      => $map['git_platform'],
                    'build_provider'    => $map['build_provider'] ?? AppConfig::PROVIDER_JENKINS,
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

        // 附加平台 URL，方便前端直接生成链接（搭 Git git_remote 的便车）
        $harborRaw = $this->config->getHarborConfig()['url'] ?? '';
        $result = [
            'projects'    => $grouped,
            'jenkins_url' => rtrim($this->config->getJenkinsConfig()['url'] ?? '', '/'),
            'harbor_url'  => rtrim($harborRaw, '/'),
        ];

        // 写入缓存（30s TTL）
        try {
            $pdo = \App\Service\Database::getPdo();
            $sql = \App\Service\Database::sqlUpsert(AppConfig::TABLE_CACHE, 'cache_key, value, expires_at', '?, ?, ?');
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$cacheKey, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), time() + 30]);
        } catch (\Exception $e) {
            // 缓存写入失败不影响响应
        }

        return $this->output($response, $result, $request);
    }

    /**
     * 获取已接入的 Git 平台列表（静态配置）
     */
    public function gitPlatforms(Request $request, Response $response): Response
    {
        $harborRaw = $this->config->getHarborConfig()['url'] ?? '';
        $data = [
            'git_platforms' => $this->config->getGitPlatformsConfig(),
            'harbor'        => $this->config->getHarborApiInfo(),
            'harbor_url'    => rtrim($harborRaw, '/'),   // 用户可见 URL（不带 /api/v2.0）
            'jenkins_url'   => rtrim($this->config->getJenkinsConfig()['url'] ?? '', '/'),
        ];
        return $this->output($response, $data, $request);
    }

    /**
     * 发现实际使用的 Git 平台与配置差异
     */
    public function gitDiscovery(Request $request, Response $response): Response
    {
        $usedPlatforms = $this->mapping->usedGitPlatforms();

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
                foreach ($this->config->getJobGitMap() as $map) {
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

        $buildMode = $this->config->getBuildMode();
        if ($buildMode !== AppConfig::BUILD_MODE_GITLAB_CI) {
            try {
                // 健康检查用独立短超时 Client，避免 Jenkins 宕机时卡住
                $jk = $this->config->getJenkinsConfig();
                $probe = new \GuzzleHttp\Client([
                    'timeout'         => 5,
                    'connect_timeout' => 3,
                    'auth'            => [$jk['user'], $jk['token']],
                    'http_errors'     => false,
                ]);
                $probe->get(rtrim($jk['url'], '/') . '/api/json');
                $checks['jenkins'] = true;
                // 版本号沿用 JenkinsService 缓存（短超时探测连通性即可）
                $checks['jenkins_version'] = $this->jenkins->getVersion();
            } catch (\Exception $e) {
                $checks['jenkins'] = false;
            }
        } else {
            $checks['jenkins'] = null; // gitlab_ci 模式不查 Jenkins
        }

        // Git 平台连通性检查
        $usedPlatforms = $this->mapping->usedGitPlatforms();

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
                        $client = new \GuzzleHttp\Client([
                            'timeout'         => 3,
                            'connect_timeout' => 2,
                            'http_errors'     => false,
                        ]);
                        $resp = $client->head($apiUrl);
                        $httpCode = $resp->getStatusCode();
                        $reachable = $httpCode > 0 && $httpCode < 500;
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
                // 多组件检查：每个端点独立判断，任一 true=false 则整体不健康
                // 注意 404 不算失败（低版本 Harbor 缺少部分端点，如 v2.0 无 jobservice/ping）
                $components = [
                    'core'       => '/api/v2.0/projects',
                    'jobservice' => '/api/v2.0/jobservice/ping',
                    'registry'   => '/api/v2.0/registries',
                ];
                $componentResults = [];
                foreach ($components as $name => $path) {
                    try {
                        $resp = $qClient->get($path);
                        $code = $resp->getStatusCode();
                        if ($code === 404) {
                            // 该 Harbor 版本不支持此端点（如 v2.0 无 /jobservice/ping），跳过不判为失败
                            $componentResults[$name] = true;
                        } elseif ($code >= 200 && $code < 500) {
                            $componentResults[$name] = true;
                        } else {
                            $componentResults[$name] = false;
                        }
                    } catch (\Throwable $e) {
                        $componentResults[$name] = false;
                    }
                }
                $checks['harbor'] = !in_array(false, $componentResults, true);
                $checks['harbor_version'] = 'v2';
                $checks['harbor_components'] = $componentResults;
            } catch (\Exception $e) {
                $checks['harbor'] = false;
                $checks['harbor_version'] = 'v2';
                $checks['harbor_components'] = null;
            }
        } else {
            $checks['harbor'] = null;
        }

        $gitOk = $checks['git'] === null || !empty(array_filter($checks['git'], fn($g) => $g['reachable']));
        $allOk = ($checks['jenkins'] === true || $checks['jenkins'] === null)
            && $gitOk
            && ($checks['harbor'] === true || $checks['harbor'] === null);
        $status = $allOk ? 'ok' : 'degraded';

        // 统计卡片数据
        $stats = ['total_maps' => 0, 'active_maps' => 0, 'git_platforms' => 0, 'harbor_repos' => 0];
        try {
            $pdo = \App\Service\Database::getPdo();
            $stats['total_maps'] = (int)$pdo->query("SELECT count(*) FROM " . AppConfig::TABLE_JOB_GIT_MAP)->fetchColumn();
            $stmt = $pdo->prepare("SELECT count(*) FROM " . AppConfig::TABLE_JOB_GIT_MAP . " WHERE status = ?");
            $stmt->execute([AppConfig::STATUS_ACTIVE]);
            $stats['active_maps'] = (int)$stmt->fetchColumn();
            $stats['git_platforms'] = (int)$pdo->query("SELECT count(DISTINCT git_platform) FROM " . AppConfig::TABLE_JOB_GIT_MAP . " WHERE git_platform IS NOT NULL AND git_platform != ''")->fetchColumn();
            $stats['harbor_repos'] = (int)$pdo->query("SELECT count(DISTINCT harbor_repository) FROM " . AppConfig::TABLE_JOB_GIT_MAP . " WHERE harbor_repository IS NOT NULL AND harbor_repository != ''")->fetchColumn();
        } catch (\Exception $e) {}

        $data = [
            'status'           => $status,
            'checks'           => $checks,
            'stats'            => $stats,
            'build_mode'       => $this->config->getBuildMode(),
            'build_mode_source'=> $this->config->getBuildModeSource(),
            'db_driver'        => \App\Service\Database::driver(),
            'app_version'      => AppConfig::APP_VERSION,
            'app_env'          => $this->config->getAppEnv(),
            'time'             => date('Y-m-d H:i:s'),
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