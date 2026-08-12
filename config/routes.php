<?php
use Slim\Routing\RouteCollectorProxy;
use App\Controller\GitController;
use App\Controller\MainController;
use App\Controller\HarborController;
use App\Controller\AdminController;
use App\Controller\BuildController;
use App\Middleware\AuthMiddleware;

// 管理页面
$app->get('/admin', function ($request, $response) {
    $htmlFile = __DIR__ . '/../templates/admin.html';
    $response->getBody()->write(file_exists($htmlFile)
        ? file_get_contents($htmlFile)
        : '<h1>Page Not Found / 页面丢失</h1>');
    return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
});

$app->group('/api', function (RouteCollectorProxy $api) {

    // 健康检查（需要鉴权）：/api/health 返回详细检查信息，受 AuthMiddleware 保护
    $api->map(['GET'], '/health', [MainController::class, 'healthDetail'])->add(AuthMiddleware::class);

    // 国际化：获取指定语言的语言包（供前端使用）
    $api->get('/i18n/{locale}', [MainController::class, 'i18n']);

    // API 文档 (Swagger UI) —— 需登录
    $api->get('/docs', [MainController::class, 'docs']);

    // OpenAPI 规范 —— 需登录
    $api->get('/openapi.json', [MainController::class, 'openapiJson']);

    $api->group('/main', function (RouteCollectorProxy $main) {
        $main->map(['GET', 'POST'], '/jobs/list', [MainController::class, 'jobsList']);
        $main->map(['GET', 'POST'], '/map/list', [MainController::class, 'mapList']);
        $main->map(['GET', 'POST'], '/git/platforms', [MainController::class, 'gitPlatforms']);
        $main->map(['GET', 'POST'], '/git/discovery', [MainController::class, 'gitDiscovery']);
    })->add(AuthMiddleware::class);

    $api->group('/admin', function (RouteCollectorProxy $admin) {
        // 公开路由（不需要鉴权）
        $admin->map(['POST'], '/login', [AdminController::class, 'login']);
        $admin->map(['POST'], '/logout', [AdminController::class, 'logout']);

        // 需要鉴权的路由
        $admin->group('', function (RouteCollectorProxy $auth) {
            $auth->map(['PUT'], '/password', [AdminController::class, 'changePassword']);
            $auth->map(['GET'], '/job_git_map', [AdminController::class, 'jobGitMapList']);
            $auth->map(['POST'], '/job_git_map', [AdminController::class, 'jobGitMapSave']);
            $auth->map(['PUT'], '/job_git_map', [AdminController::class, 'jobGitMapUpdate']);
            $auth->map(['DELETE'], '/job_git_map', [AdminController::class, 'jobGitMapDelete']);
            $auth->map(['GET'], '/platform_versions', [AdminController::class, 'platformVersionsList']);
            $auth->map(['PUT'], '/platform_versions', [AdminController::class, 'platformVersionsUpdate']);
            $auth->map(['POST'], '/discover', [AdminController::class, 'discover']);
            $auth->map(['GET'], '/security_checks', [AdminController::class, 'securityChecksList']);
            $auth->map(['GET'], '/build_mode', [AdminController::class, 'getBuildMode']);
            $auth->map(['PUT'], '/build_mode', [AdminController::class, 'updateBuildMode']);
            $auth->map(['GET'], '/users', [AdminController::class, 'userList']);
            $auth->map(['POST'], '/users', [AdminController::class, 'userCreate']);
            $auth->map(['PUT'], '/users/{username}', [AdminController::class, 'userUpdate']);
            $auth->map(['DELETE'], '/users/{username}', [AdminController::class, 'userDelete']);
            $auth->map(['GET'], '/roles', [AdminController::class, 'roleList']);
            $auth->map(['POST'], '/roles', [AdminController::class, 'roleCreate']);
            $auth->map(['PUT'], '/roles/{id}', [AdminController::class, 'roleUpdate']);
            $auth->map(['DELETE'], '/roles/{id}', [AdminController::class, 'roleDelete']);
            $auth->map(['GET'], '/permissions', [AdminController::class, 'permissionList']);
            $auth->map(['POST'], '/permissions', [AdminController::class, 'permissionRegister']);
            $auth->map(['DELETE'], '/permissions/{perm_key}', [AdminController::class, 'permissionDelete']);
            $auth->map(['POST'], '/implied_rules', [AdminController::class, 'impliedRuleCreate']);
            $auth->map(['DELETE'], '/implied_rules', [AdminController::class, 'impliedRuleDelete']);
            $auth->map(['GET'], '/me/permissions', [AdminController::class, 'mePermissions']);
        })->add(AuthMiddleware::class);
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
    })->add(AuthMiddleware::class);

    $api->group('/git', function (RouteCollectorProxy $git) {
        $git->map(['GET', 'POST'], '/{path:.+}/branches', [GitController::class, 'branches']);
    })->add(AuthMiddleware::class);

    $api->group('/harbor', function (RouteCollectorProxy $harbor) {
        $harbor->map(['GET', 'POST'], '/projects', [HarborController::class, 'getProjectsList']);
        $harbor->map(['GET', 'POST'], '/{project}/repositories', [HarborController::class, 'getRepositoriesList']);
        $harbor->map(['GET', 'POST'], '/{project}/repositories/{repository}/tags', [HarborController::class, 'getTagsList']);
        $harbor->map(['POST'], '/{project}/repositories/{repository}/tags/{tag}/scan', [HarborController::class, 'scanTrigger']);
        $harbor->map(['GET'], '/{project}/repositories/{repository}/tags/{tag}/scan', [HarborController::class, 'getScanReport']);
    })->add(AuthMiddleware::class);
});