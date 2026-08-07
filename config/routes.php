<?php
use Slim\Routing\RouteCollectorProxy;
use App\Controller\GitController;
use App\Controller\MainController;
use App\Controller\HarborController;
use App\Controller\AdminController;
use App\Controller\BuildController;
use App\Service\I18nService;

// 管理页面
$app->get('/admin', function ($request, $response) {
    $htmlFile = __DIR__ . '/../templates/admin.html';
    $response->getBody()->write(file_exists($htmlFile)
        ? file_get_contents($htmlFile)
        : '<h1>Page Not Found / 页面丢失</h1>');
    return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
});

$app->group('/api', function (RouteCollectorProxy $api) use ($app) {

    // 健康检查
    $api->map(['GET'], '/health', [MainController::class, 'health']);

    // 国际化：获取指定语言的语言包（供前端使用）
    $api->get('/i18n/{locale}', function ($request, $response, $args) use ($app) {
        $locale = $args['locale'] ?? 'zh_CN';
        $i18n = $app->getContainer()->get(I18nService::class);
        $messages = $i18n->getAll($locale);
        $response->getBody()->write(json_encode($messages, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // 简单鉴权 helper（闭包内复用）
    $checkAuth = function ($request) {
        $user = $_ENV['ADMIN_USER'] ?? 'admin';
        $pass = $_ENV['ADMIN_PASSWORD'] ?? '';
        if (empty($pass)) return true; // 未设密码则放行
        $token = $request->getQueryParams()['token'] ?? '';
        if (empty($token)) {
            $header = $request->getHeaderLine('Authorization');
            if (preg_match('/^Bearer\s+(.+)$/i', $header, $m)) $token = $m[1];
        }
        if (empty($token)) return false;

        // 验证 cache 中的随机 token（与 AdminController 一致）
        try {
            $pdo = \App\Service\Database::getPdo();
            $row = $pdo->prepare("SELECT value FROM " . \App\Config\AppConfig::TABLE_CACHE . " WHERE cache_key = ? AND expires_at > ?");
            $row->execute([\App\Config\AppConfig::CACHE_KEY_ADMIN_TOKEN_PREFIX . $token, time()]);
            if ($row->fetch()) return true;
        } catch (\Exception $e) {}

        // 兼容旧版 base64 token
        $expected = base64_encode($user . ':' . $pass);
        return hash_equals($expected, $token);
    };

    // API 文档 (Swagger UI) —— 需登录
    $api->get('/docs', function ($request, $response) use ($checkAuth) {
        $htmlFile = __DIR__ . '/../templates/swagger.html';
        $swaggerHtml = file_exists($htmlFile) ? file_get_contents($htmlFile) : '<h1>Swagger file missing / 文档文件丢失</h1>';

        if ($checkAuth($request)) {
            $response->getBody()->write($swaggerHtml);
            return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
        }

        // 未登录 → 登录页
        $loginFile = __DIR__ . '/../templates/swagger-auth.html';
        $response->getBody()->write(file_exists($loginFile) ? file_get_contents($loginFile) : '<h1>Login page missing / 登录页丢失</h1>');
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    });

    // OpenAPI 规范 —— 需登录
    $api->get('/openapi.json', function ($request, $response) use ($checkAuth, $app) {
        if (!$checkAuth($request)) {
            $i18n = $app->getContainer()->get(I18nService::class);
            $response->getBody()->write(json_encode(['code' => 401, 'message' => $i18n->trans('auth.please_login_first')]));
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
        $specFile = __DIR__ . '/../templates/openapi' . ($suffix ? '.' . $suffix : '') . '.json';
        if (!file_exists($specFile)) {
            $specFile = __DIR__ . '/../templates/openapi.json';
        }
        $spec = file_exists($specFile)
            ? json_decode(file_get_contents($specFile), true)
            : ['openapi' => '3.0.3', 'info' => ['title' => 'Devops-Glue API'], 'paths' => []];

        $uri  = $request->getUri();
        $port = $uri->getPort();
        $isDefault = ($uri->getScheme() === 'http'  && $port === 80)
                  || ($uri->getScheme() === 'https' && $port === 443);

        // 优先使用配置的 API_BASE_URL，未设则自动推导
        $config = $app->getContainer()->get(\App\Config\AppConfig::class);
        $apiBaseUrl = $config->getApiBaseUrl();
        if (empty($apiBaseUrl)) {
            $apiBaseUrl = $uri->getScheme() . '://' . $uri->getHost()
                        . (($port && !$isDefault) ? ':' . $port : '');
        }

        $i18n  = $app->getContainer()->get(I18nService::class);
        $locale = $i18n->detectLocale($request);
        $spec['servers'] = [[
            'url'         => $apiBaseUrl,
            'description' => $i18n->trans('admin.current_env', [], $locale),
        ]];

        $response->getBody()->write(json_encode($spec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        return $response->withHeader('Content-Type', 'application/json');
    });

    $api->group('/main', function (RouteCollectorProxy $main) {
        $main->map(['GET', 'POST'], '/jobs/list', [MainController::class, 'jobsList']);
        $main->map(['GET', 'POST'], '/map/list', [MainController::class, 'mapList']);
        $main->map(['GET', 'POST'], '/git/platforms', [MainController::class, 'gitPlatforms']);
        $main->map(['GET', 'POST'], '/git/discovery', [MainController::class, 'gitDiscovery']);
    });

    $api->group('/admin', function (RouteCollectorProxy $admin) {
        $admin->map(['POST'], '/login', [AdminController::class, 'login']);
        $admin->map(['POST'], '/logout', [AdminController::class, 'logout']);
        $admin->map(['PUT'], '/password', [AdminController::class, 'changePassword']);
        $admin->map(['GET'], '/job_git_map', [AdminController::class, 'jobGitMapList']);
        $admin->map(['POST'], '/job_git_map', [AdminController::class, 'jobGitMapSave']);
        $admin->map(['PUT'], '/job_git_map', [AdminController::class, 'jobGitMapUpdate']);
        $admin->map(['DELETE'], '/job_git_map', [AdminController::class, 'jobGitMapDelete']);
        $admin->map(['GET'], '/platform_versions', [AdminController::class, 'platformVersionsList']);
        $admin->map(['PUT'], '/platform_versions', [AdminController::class, 'platformVersionsUpdate']);
        $admin->map(['POST'], '/discover', [AdminController::class, 'discover']);
        $admin->map(['GET'], '/security_checks', [AdminController::class, 'securityChecksList']);
        $admin->map(['GET'], '/build_mode', [AdminController::class, 'getBuildMode']);
        $admin->map(['PUT'], '/build_mode', [AdminController::class, 'updateBuildMode']);
        $admin->map(['GET'], '/users', [AdminController::class, 'userList']);
        $admin->map(['POST'], '/users', [AdminController::class, 'userCreate']);
        $admin->map(['PUT'], '/users/{username}', [AdminController::class, 'userUpdate']);
        $admin->map(['DELETE'], '/users/{username}', [AdminController::class, 'userDelete']);
        $admin->map(['GET'], '/roles', [AdminController::class, 'roleList']);
        $admin->map(['POST'], '/roles', [AdminController::class, 'roleCreate']);
        $admin->map(['PUT'], '/roles/{id}', [AdminController::class, 'roleUpdate']);
        $admin->map(['DELETE'], '/roles/{id}', [AdminController::class, 'roleDelete']);
        $admin->map(['GET'], '/permissions', [AdminController::class, 'permissionList']);
        $admin->map(['POST'], '/permissions', [AdminController::class, 'permissionRegister']);
        $admin->map(['DELETE'], '/permissions/{perm_key}', [AdminController::class, 'permissionDelete']);
        $admin->map(['POST'], '/implied_rules', [AdminController::class, 'impliedRuleCreate']);
        $admin->map(['DELETE'], '/implied_rules', [AdminController::class, 'impliedRuleDelete']);
        $admin->map(['GET'], '/me/permissions', [AdminController::class, 'mePermissions']);
    });

    $api->group('/build', function (RouteCollectorProxy $build) {
        $build->map(['GET', 'POST'], '/jobs/list', [BuildController::class, 'jobsList']);
        $build->map(['GET'], '/config-mode', [BuildController::class, 'configMode']);
        $build->map(['GET', 'POST'], '/{path:.+}/pipelines', [BuildController::class, 'pipelines']);
        $build->map(['GET', 'POST'], '/{path:.+}/pipelines/{id:\d+}', [BuildController::class, 'pipelineDetail']);
        $build->map(['POST'], '/{path:.+}/pipelines/{id:\d+}/retry', [BuildController::class, 'retry']);
        $build->map(['POST'], '/{path:.+}/pipelines/{id:\d+}/cancel', [BuildController::class, 'cancel']);
        $build->map(['GET', 'POST'], '/{path:.+}/logs/{id:\d+}', [BuildController::class, 'logs']);
        $build->map(['GET', 'POST'], '/{path:.+}/trigger', [BuildController::class, 'trigger']);
        $build->map(['GET', 'POST'], '/{path:.+}/variables', [BuildController::class, 'variables']);
        $build->map(['GET', 'POST'], '/{path:.+}/branches', [BuildController::class, 'branches']);
        $build->map(['POST'], '/{path:.+}/scan-sync', [BuildController::class, 'scanSync']);
        $build->map(['POST'], '/{path:.+}/commit-status', [BuildController::class, 'commitStatus']);
        $build->map(['GET', 'POST'], '/{path:.+}/tag', [BuildController::class, 'tagQuery']);
    });

    $api->group('/git', function (RouteCollectorProxy $git) {
        $git->map(['GET', 'POST'], '/{path:.+}/branches', [GitController::class, 'branches']);
    });

    $api->group('/harbor', function (RouteCollectorProxy $harbor) {
        $harbor->map(['GET', 'POST'], '/projects', [HarborController::class, 'getProjectsList']);
        $harbor->map(['GET', 'POST'], '/{project}/repositories', [HarborController::class, 'getRepositoriesList']);
        $harbor->map(['GET', 'POST'], '/{project}/repositories/{repository}/tags', [HarborController::class, 'getTagsList']);
        $harbor->map(['POST'], '/{project}/repositories/{repository}/tags/{tag}/scan', [HarborController::class, 'scanTrigger']);
        $harbor->map(['GET'], '/{project}/repositories/{repository}/tags/{tag}/scan', [HarborController::class, 'getScanReport']);
    });
});