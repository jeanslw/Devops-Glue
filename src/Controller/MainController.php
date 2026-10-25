<?php

namespace App\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Service\JenkinsService;
use App\Service\HarborService;
use App\Service\MappingManager;
use App\Service\AppSettingRepository;
use App\Service\I18nService;
use App\Service\Settings;
use App\Service\TokenService;
use App\Config\AppConfig;

class MainController extends BaseController
{
    private JenkinsService $jenkins;
    private Settings $config;
    private MappingManager $mapping;
    private AppSettingRepository $appSettings;
    private ?HarborService $harbor;
    private \PDO $pdo;
    private ?TokenService $tokenService = null;

    public function __construct(I18nService $i18n, JenkinsService $jenkins, Settings $config, MappingManager $mapping, AppSettingRepository $appSettings, \PDO $pdo, ?HarborService $harbor = null, ?TokenService $tokenService = null)
    {
        parent::__construct($i18n);
        $this->jenkins = $jenkins;
        $this->config = $config;
        $this->mapping = $mapping;
        $this->appSettings = $appSettings;
        $this->pdo = $pdo;
        $this->harbor = $harbor;
        $this->tokenService = $tokenService;
    }

    /**
     * 获取所有 Job 列表
     */
    public function jobsList(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_BUILD_RECORDS_PULL)) {
            return $resp;
        }

        $modes = $this->appSettings->getBuildModes();
        if (!in_array(AppConfig::PROVIDER_JENKINS, $modes, true)) {
            return $this->output($response, $this->mapping->activeJobNames(), $request);
        }
        try {
            $names = $this->jenkins->getAllJobs();
        } catch (\Exception $e) {
            return $this->output($response, $this->mapping->activeJobNames(), $request);
        }
        $others = array_values(array_diff($modes, [AppConfig::PROVIDER_JENKINS]));
        if ($others) {
            foreach ($this->mapping->activeMaps() as $m) {
                if (in_array($m['build_provider'] ?? '', $others, true)) {
                    $names[] = $m['job_name'] ?? '';
                }
            }
            $names = array_values(array_unique(array_filter($names, fn($n) => $n !== '')));
        }
        return $this->output($response, $names, $request);
    }

    /**
     * 获取三方映射关系（按项目分组），带 30s 缓存
     */
    public function mapList(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_BUILD_RECORDS_PULL)) {
            return $resp;
        }

        $buildModes = $this->appSettings->getBuildModes();
        $cacheKey = AppConfig::CACHE_KEY_MAP_LIST_PREFIX . implode(',', $buildModes);

        try {
            $pdo = $this->pdo;
            $cached = $pdo->prepare("SELECT value FROM " . AppConfig::TABLE_CACHE . " WHERE cache_key = ? AND expires_at > ?");
            $cached->execute([$cacheKey, time()]);
            $row = $cached->fetch();
            if ($row) {
                $data = json_decode($row['value'], true);
                if (is_array($data)) {
                    return $this->output($response, $data, $request);
                }
            }
        } catch (\Exception $e) {
            \App\Helper\Log::exception($e);
        }
        try {
            $maps = $this->mapping->activeMaps();
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
                    'build_provider'    => (string) ($map['build_provider'] ?? ''),
                    'git_remote'        => $map['git_remote'],
                    'project_id'        => $map['project_id'] ?? null,
                    'web_url'           => $map['web_url'] ?? '',
                    'registry_repository' => $map['registry_repository'] ?? '',
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

        $harborRaw = $this->config->getHarborConfig()['url'] ?? '';
        $result = [
            'projects'    => $grouped,
            'jenkins_url' => rtrim($this->config->getJenkinsConfig()['url'] ?? '', '/'),
            'harbor_url'  => rtrim($harborRaw, '/'),
        ];

        try {
            $pdo = $this->pdo;
            $sql = \App\Service\Database::sqlUpsert(AppConfig::TABLE_CACHE, 'cache_key, value, expires_at', '?, ?, ?');
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$cacheKey, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), time() + 30]);
        } catch (\Exception $e) {
        }

        return $this->output($response, $result, $request);
    }

    /**
     * 获取已接入的 Git 平台列表（静态配置）
     */
    public function gitPlatforms(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_BUILD_RECORDS_PULL)) {
            return $resp;
        }

        $harborRaw = $this->config->getHarborConfig()['url'] ?? '';
        $data = [
            'git_platforms' => $this->config->getGitPlatformsConfig(),
            'harbor'        => $this->config->getHarborApiInfo(),
            'harbor_url'    => rtrim($harborRaw, '/'),
            'jenkins_url'   => rtrim($this->config->getJenkinsConfig()['url'] ?? '', '/'),
        ];
        return $this->output($response, $data, $request);
    }

    /**
     * 发现实际使用的 Git 平台与配置差异
     */
    public function gitDiscovery(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_DISCOVER)) {
            return $resp;
        }

        $usedPlatforms = $this->mapping->usedGitPlatforms();

        $configured = [];
        $unconfigured = [];
        foreach ($usedPlatforms as $name) {
            if ($this->config->isPlatformConfigured($name)) {
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
                foreach ($this->mapping->allMaps() as $map) {
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
        $result = [
            'status'      => 'ok',
            'app_env'     => $this->config->getAppEnv(),
            'app_version' => AppConfig::APP_VERSION,
            'time'        => time(),
        ];

        return $this->output($response, $result, $request);
    }

    /**
     * 详细健康检查（需要鉴权）
     */
    public function healthDetail(Request $request, Response $response): Response
    {
        $checks = [
            'jenkins'         => false,
            'jenkins_version' => null,
            'git'             => [],
            'harbor'          => false,
            'harbor_version'  => null,
        ];

        if (in_array(AppConfig::PROVIDER_JENKINS, $this->appSettings->getBuildModes(), true)) {
            $jk = $this->config->getJenkinsConfig();
            $jenkinsUrl = rtrim((string)($jk['url'] ?? ''), '/');
            try {
                $probe = new \GuzzleHttp\Client([
                    'timeout'         => 5,
                    'connect_timeout' => 3,
                    'auth'            => [$jk['user'], $jk['token']],
                    'http_errors'     => false,
                ]);
                $this->probeWithRetry(function () use ($probe, $jenkinsUrl) {
                    $probe->get($jenkinsUrl . '/api/json');
                });
                $checks['jenkins'] = true;
                $checks['jenkins_version'] = $this->jenkins->getVersion();
            } catch (\Exception $e) {
                \App\Helper\Log::error('[健康检查] Jenkins 连接失败', ['platform' => 'jenkins', 'url' => $jenkinsUrl, 'error' => $e->getMessage()]);
                $checks['jenkins'] = false;
            }
        } else {
            $checks['jenkins'] = null;
        }

        $usedPlatforms = $this->mapping->usedGitPlatforms();
        $configuredPlatforms = [];
        $platformVersions    = [];
        foreach ($this->config->getGitPlatformsConfig() as $p) {
            $configuredPlatforms[$p['name']] = $p['api_base_url'];
            $platformVersions[$p['name']]    = $p['api_version'] ?? '';
        }

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
                        $this->probeWithRetry(function () use ($client, $apiUrl, &$reachable) {
                            $resp = $client->head($apiUrl);
                            $httpCode = $resp->getStatusCode();
                            $reachable = $httpCode > 0 && $httpCode < 500;
                            if (!$reachable) {
                                throw new \RuntimeException("HTTP {$httpCode}");
                            }
                        });
                    } catch (\Exception $e) {
                        \App\Helper\Log::error('[健康检查] ' . $name . ' 连接失败', ['platform' => $name, 'url' => (string)$apiUrl, 'error' => $e->getMessage()]);
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

        $harbor = $this->harbor;
        if ($harbor !== null && trim((string) ($this->config->getHarborConfig()['url'] ?? '')) !== '') {
            $harborUrl = rtrim((string)($this->config->getHarborConfig()['url'] ?? ''), '/');
            $probeLogged = false;
            try {
                $qClient = new \GuzzleHttp\Client([
                    'base_uri' => $harborUrl,
                    'auth'     => [$this->config->getHarborConfig()['username'] ?? 'admin', $this->config->getHarborConfig()['password'] ?? ''],
                    'timeout'  => 5,
                    'connect_timeout' => 3,
                    'http_errors' => false,
                ]);

                if ($this->config->getRegistryType() === 'registry') {
                    $registryReachable = false;
                    $this->probeWithRetry(function () use ($qClient, &$registryReachable) {
                        $resp = $qClient->get('/v2/');
                        $code = $resp->getStatusCode();
                        $registryReachable = $code >= 200 && $code < 500;
                        if (!$registryReachable) {
                            throw new \RuntimeException("HTTP {$code}");
                        }
                    });
                    $checks['harbor'] = $registryReachable;
                    $checks['harbor_version'] = 'registry';
                    $checks['harbor_components'] = ['registry' => $registryReachable];
                } else {
                    $components = [
                        'core'       => '/api/v2.0/projects',
                        'jobservice' => '/api/v2.0/jobservice/ping',
                        'registry'   => '/api/v2.0/registries',
                    ];
                    $componentResults = [];
                    foreach ($components as $name => $path) {
                        try {
                            $this->probeWithRetry(function () use ($qClient, $path, $name, &$componentResults) {
                                $resp = $qClient->get($path);
                                $code = $resp->getStatusCode();
                                if ($code === 404) {
                                    $componentResults[$name] = true;
                                } elseif ($code >= 200 && $code < 500) {
                                    $componentResults[$name] = true;
                                } else {
                                    $componentResults[$name] = false;
                                    throw new \RuntimeException("HTTP {$code}");
                                }
                            });
                        } catch (\Throwable $e) {
                            $componentResults[$name] = false;
                            if (!$probeLogged) {
                                \App\Helper\Log::error('[健康检查] 镜像仓库连接失败', ['platform' => 'registry', 'url' => $harborUrl, 'error' => $e->getMessage()]);
                                $probeLogged = true;
                            }
                        }
                    }
                    $checks['harbor'] = !in_array(false, $componentResults, true);
                    $checks['harbor_version'] = $harbor->getHarborVersion() ?? 'v2';
                    $checks['harbor_components'] = $componentResults;
                }
            } catch (\Exception $e) {
                if (!$probeLogged) {
                    \App\Helper\Log::error('[健康检查] 镜像仓库连接失败', ['platform' => 'registry', 'url' => $harborUrl, 'error' => $e->getMessage()]);
                }
                $checks['harbor'] = false;
                $checks['harbor_version'] = $this->config->getRegistryType() === 'registry' ? 'registry' : ($harbor->getHarborVersion() ?? 'v2');
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

        $data = $this->buildHealthStaticData();
        $data['status'] = $status;
        $data['checks'] = $checks;

        $response->getBody()->write((string) json_encode($data));
        $httpCode = $allOk ? 200 : 503;
        return $response->withStatus($httpCode)->withHeader('Content-Type', 'application/json');
    }

    /**
     * 探测连通性：失败重试一次（间隔 0.5s），防止网络瞬时抖动导致误报不可达。
     * 回调内部做探测，失败/非预期结果请抛异常；重试耗尽仍失败才向上抛出。
     */
    private function probeWithRetry(callable $probe, int $retries = 1): void
    {
        $attempts = $retries + 1;
        for ($i = 0; $i < $attempts; $i++) {
            try {
                $probe();
                return;
            } catch (\Throwable $e) {
                if ($i >= $attempts - 1) {
                    throw $e;
                }
                usleep(500000);
            }
        }
    }

    /**
     * 快速健康信息（不依赖外部探测）。
     * 仅返回数据概览 + 系统信息 + Custom_Push，不探测 Jenkins / Git / Harbor，
     * 毫秒级返回，供前端先渲染非探测卡片，避免被外部探测拖慢整个页面。
     */
    public function healthStatic(Request $request, Response $response): Response
    {
        $data = $this->buildHealthStaticData();
        $data['status'] = 'ok';
        return $this->output($response, $data, $request);
    }

    /**
     * 汇总不依赖外部网络探测的健康信息（统计卡片 + 系统监测 + Custom_Push）。
     *
     * @return array<string,mixed>
     */
    private function buildHealthStaticData(): array
    {
        $stats = ['total_maps' => 0, 'active_maps' => 0, 'git_platforms' => 0, 'harbor_repos' => 0];
        try {
            $pdo = $this->pdo;
            $stats['total_maps'] = (int)$pdo->query("SELECT count(*) FROM " . AppConfig::TABLE_JOB_GIT_MAP)->fetchColumn();
            $stmt = $pdo->prepare("SELECT count(*) FROM " . AppConfig::TABLE_JOB_GIT_MAP . " WHERE status = ?");
            $stmt->execute([AppConfig::STATUS_ACTIVE]);
            $stats['active_maps'] = (int)$stmt->fetchColumn();
            $stats['git_platforms'] = (int)$pdo->query("SELECT count(DISTINCT git_platform) FROM " . AppConfig::TABLE_JOB_GIT_MAP . " WHERE git_platform IS NOT NULL AND git_platform != ''")->fetchColumn();
            $stats['harbor_repos'] = (int)$pdo->query("SELECT count(DISTINCT registry_repository) FROM " . AppConfig::TABLE_JOB_GIT_MAP . " WHERE registry_repository IS NOT NULL AND registry_repository != ''")->fetchColumn();
        } catch (\Exception $e) {
        }

        return [
            'stats'                 => $stats,
            'build_mode'            => $this->appSettings->getBuildMode(),
            'build_modes'           => $this->appSettings->getBuildModes(),
            'build_mode_source'     => $this->appSettings->getBuildModeSource(),
            'ci_sources'            => $this->buildCiSources(),
            'custom_push_enabled'  => $this->appSettings->getCustomPushEnabled(),
            'custom_push_providers' => array_column($this->config->getCustomBuildProviders(), 'name'),
            'db_driver'             => \App\Service\Database::driver(),
            'app_version'           => AppConfig::APP_VERSION,
            'app_env'               => $this->config->getAppEnv(),
            'time'                  => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * 构建平台卡片数据：git 平台派生的 CI 能力（二元拆分）+ 独立 CI（Jenkins / custom_push）。
     * 每项给出 enabled（build_mode 里是否启用）与 configured（底层平台是否已配置、能否建 provider），
     * 前端据此渲染 启用 / 未配置 两态，显式暴露「开了 CI 但平台没配好」这类错配。
     *
     * @return list<array{ci:string, platform:?string, kind:string, enabled:bool, configured:bool}>
     */
    private function buildCiSources(): array
    {
        $enabled = $this->appSettings->getBuildModes();
        $sources = [];

        foreach (AppConfig::GIT_PLATFORM_CI_CAPABILITIES as $platform => $ci) {
            $cfg = $this->config->getGitPlatformConfig($platform);
            $sources[] = [
                'ci'         => $ci,
                'platform'   => $platform,
                'kind'       => 'git',
                'enabled'    => in_array($ci, $enabled, true),
                'configured' => !empty($cfg['base_url'] ?? '') && !empty($cfg['token'] ?? ''),
            ];
        }

        $jenkins = $this->config->getJenkinsConfig();
        $sources[] = [
            'ci'         => AppConfig::PROVIDER_JENKINS,
            'platform'   => null,
            'kind'       => 'standalone',
            'enabled'    => in_array(AppConfig::PROVIDER_JENKINS, $enabled, true),
            'configured' => !empty($jenkins['url'] ?? ''),
        ];

        $sources[] = [
            'ci'         => AppConfig::PROVIDER_CUSTOM_PUSH,
            'platform'   => null,
            'kind'       => 'standalone',
            'enabled'    => $this->appSettings->getCustomPushEnabled(),
            'configured' => $this->config->getCustomBuildProviders() !== [],
        ];

        return $sources;
    }

    /**
     * 从远程 URL 提取项目路径（保留 GitLab 子群组层级）
     */
    private function extractProjectPath(string $remote): string
    {
        return \App\Helper\GitRemote::extractPath($remote) ?? '';
    }

    // ────────────────────────── i18n / docs / openapi ──────────────────────────

    /**
     * GET /api/i18n/{locale} — 获取指定语言的语言包
     *
     * @param array<string,string> $args 路由参数
     */
    public function i18n(Request $request, Response $response, array $args): Response
    {
        $locale = $args['locale'] ?? 'zh_CN';
        $messages = $this->i18n->getAll($locale);
        $response->getBody()->write((string) json_encode($messages, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * GET /api/docs — Swagger UI 页面（未登录时同页内嵌登录框）
     *
     * 页面本身（纯 UI）直接放行，不含任何业务数据；OpenAPI 规范(/api/openapi.json)
     * 与 Try-it-out 请求仍由服务端鉴权。为让会话 token 不再出现在 URL（防 access log 与
     * 浏览器历史泄露），登录态由同源 sessionStorage 承载，仅在页面 JS 侧可感知，因此
     * 服务端无法据此改跳登录页；登录层直接内嵌本页，JS 检测无凭证时同页显示
     * 登录框（URL 保持 /api/docs），登录成功写 sessionStorage 后就地初始化 SwaggerUI。
     */
    public function docs(Request $request, Response $response): Response
    {
        $htmlFile = __DIR__ . '/../../templates/swagger.html';
        $html = file_exists($htmlFile) ? file_get_contents($htmlFile) : '<h1>Swagger file missing / 文档文件丢失</h1>';
        $html = str_replace('{{APP_VERSION}}', AppConfig::APP_VERSION, $html === false ? '' : $html);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * GET /api/openapi.json — OpenAPI 规范（需登录）
     */
    public function openapiJson(Request $request, Response $response): Response
    {
        if (!$this->checkDocsAuth($request)) {
            $response->getBody()->write((string) json_encode(['code' => 401, 'message' => $this->__('auth.please_login_first')]));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }

        $lang = $request->getQueryParams()['lang'] ?? '';
        $map = ['zh-CN' => 'zh', 'zh' => 'zh', 'en' => 'en'];
        $suffix = $map[$lang] ?? '';
        if (!$suffix) {
            $accept = $request->getHeaderLine('Accept-Language');
            if (stripos($accept, 'zh') !== false) {
                $suffix = 'zh';
            } elseif (stripos($accept, 'en') !== false) {
                $suffix = 'en';
            }
        }
        $specFile = __DIR__ . '/../../templates/openapi' . ($suffix ? '.' . $suffix : '') . '.json';
        if (!file_exists($specFile)) {
            $specFile = __DIR__ . '/../../templates/openapi.json';
        }
        if (!file_exists($specFile)) {
            $spec = ['openapi' => '3.0.3', 'info' => ['title' => 'Devops-Glue API'], 'paths' => []];
        } else {
            $json = file_get_contents($specFile);
            if ($json === false) {
                $response->getBody()->write((string) json_encode([
                    'code'    => 500,
                    'message' => 'Unable to read OpenAPI spec file',
                ]));
                return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
            }
            $spec = json_decode($json, true);
        }






        $locale = $this->i18n->detectLocale($request);
        $spec['servers'] = [[
            'url'         => '/',
            'description' => $this->i18n->trans('admin.current_env', [], $locale),
        ]];
        $apiBaseUrl = $this->config->getApiBaseUrl();
        if ($apiBaseUrl !== '') {
            $spec['servers'][] = [
                'url'         => $apiBaseUrl,
                'description' => $this->i18n->trans('admin.configured_api_base', [], $locale),
            ];
        }

        $response->getBody()->write((string) json_encode($spec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * 文档鉴权：只认 Authorization: Bearer（兼容旧版 base64 token）
     * 返回 true/false，由调用方决定显示内容或拒绝
     */
    private function checkDocsAuth(Request $request): bool
    {
        $cred = $this->config->getAdminCredentials();
        if (empty($cred['password'])) {
            return $this->tokenService?->isFirstInit() ?? false;
        }

        $token = '';
        $header = $request->getHeaderLine('Authorization');
        if (preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
            $token = $m[1];
        }
        if (empty($token)) {
            return false;
        }

        if ($this->tokenService?->validate($token) !== null) {
            return true;
        }
        return $this->tokenService?->validateLegacy($token) ?? false;
    }
}
