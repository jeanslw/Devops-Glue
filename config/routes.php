<?php
use Slim\Routing\RouteCollectorProxy;
use App\Controller\GitController;
use App\Controller\MainController;
use App\Controller\HarborController;
use App\Controller\AdminController;
use App\Controller\BuildController;

// 管理页面
$app->get('/admin', function ($request, $response) {
    $htmlFile = __DIR__ . '/../templates/admin.html';
    $response->getBody()->write(file_exists($htmlFile)
        ? file_get_contents($htmlFile)
        : '<h1>管理页面丢失</h1>');
    return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
});

$app->group('/api', function (RouteCollectorProxy $api) {

    // 健康检查
    $api->map(['GET'], '/health', [MainController::class, 'health']);

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
        $expected = base64_encode($user . ':' . $pass);
        return $token && hash_equals($expected, $token);
    };

    // API 文档 (Swagger UI) —— 需登录
    $api->get('/docs', function ($request, $response) use ($checkAuth) {
        $htmlFile = __DIR__ . '/../templates/swagger.html';
        $swaggerHtml = file_exists($htmlFile) ? file_get_contents($htmlFile) : '<h1>文档文件丢失</h1>';

        if ($checkAuth($request)) {
            $response->getBody()->write($swaggerHtml);
            return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
        }

        // 未登录 → 登录页
        $loginFile = __DIR__ . '/../templates/swagger-auth.html';
        $response->getBody()->write(file_exists($loginFile) ? file_get_contents($loginFile) : '<h1>登录页丢失</h1>');
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    });

    // OpenAPI 规范 —— 需登录
    $api->get('/openapi.json', function ($request, $response) use ($checkAuth) {
        if (!$checkAuth($request)) {
            $response->getBody()->write(json_encode(['code' => 401, 'message' => '请先登录 API 文档']));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }

        $specFile = __DIR__ . '/../templates/openapi.json';
        $spec = file_exists($specFile)
            ? json_decode(file_get_contents($specFile), true)
            : ['openapi' => '3.0.3', 'info' => ['title' => 'Devops-Glue API'], 'paths' => []];

        $uri  = $request->getUri();
        $port = $uri->getPort();
        $isDefault = ($uri->getScheme() === 'http'  && $port === 80)
                  || ($uri->getScheme() === 'https' && $port === 443);

        // 优先使用 .env 显式地址，未设则自动推导
        $apiBaseUrl = $_ENV['API_BASE_URL'] ?? '';
        if (empty($apiBaseUrl)) {
            $apiBaseUrl = $uri->getScheme() . '://' . $uri->getHost()
                        . (($port && !$isDefault) ? ':' . $port : '');
        }

        $spec['servers'] = [[
            'url'         => $apiBaseUrl,
            'description' => '当前环境',
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
        $admin->map(['PUT'], '/password', [AdminController::class, 'changePassword']);
        $admin->map(['GET'], '/job_git_map', [AdminController::class, 'jobGitMapList']);
        $admin->map(['POST'], '/job_git_map', [AdminController::class, 'jobGitMapSave']);
        $admin->map(['PUT'], '/job_git_map', [AdminController::class, 'jobGitMapUpdate']);
        $admin->map(['DELETE'], '/job_git_map', [AdminController::class, 'jobGitMapDelete']);
        $admin->map(['GET'], '/platform_versions', [AdminController::class, 'platformVersionsList']);
        $admin->map(['PUT'], '/platform_versions', [AdminController::class, 'platformVersionsUpdate']);
        $admin->map(['POST'], '/discover', [AdminController::class, 'discover']);
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
        $build->map(['POST'], '/{path:.+}/scan-sync', [BuildController::class, 'scanSync']);
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