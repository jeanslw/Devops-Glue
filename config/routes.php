<?php
use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use App\Controller\GitController;
use App\Controller\MainController;
use App\Controller\HarborController;
use App\Controller\AdminController;
use App\Controller\RbacController;
use App\Controller\BuildController;
use App\Controller\DashboardController;
use App\Controller\OAuthController;
use App\Middleware\AuthMiddleware;


// 本文件由 public/index.php 在 $app 就绪后直接 require（不走容器）。
// 防御：$app 缺失/类型错误时在启动阶段显式失败，而不是留下 undefined variable 运行时错。
if (!isset($app) || !$app instanceof App) {
    throw new \RuntimeException('routes.php 必须由应用入口在 $app（Slim\App）就绪后加载');
}

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
            $auth->map(['GET'], '/custom_builds', [AdminController::class, 'customBuildList']);
            $auth->map(['GET'], '/users', [AdminController::class, 'userList']);
            $auth->map(['POST'], '/users', [AdminController::class, 'userCreate']);
            $auth->map(['PUT'], '/users/{username}', [AdminController::class, 'userUpdate']);
            $auth->map(['PUT'], '/users/{username}/password', [AdminController::class, 'userModifyPassword']);
            $auth->map(['PUT'], '/users/{username}/status', [AdminController::class, 'userSetStatus']);
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
            $auth->map(['GET'], '/api_tokens/scopes', [AdminController::class, 'apiTokenScopes']);
            $auth->map(['GET'], '/api_tokens', [AdminController::class, 'apiTokenList']);
            $auth->map(['POST'], '/api_tokens', [AdminController::class, 'apiTokenCreate']);
            $auth->map(['POST'], '/api_tokens/{id}/revoke', [AdminController::class, 'apiTokenRevoke']);
            $auth->map(['DELETE'], '/api_tokens/{id}', [AdminController::class, 'apiTokenDelete']);
        })->add(AuthMiddleware::class);
    });

    // RBAC 接口（CD 服务账号专用，仅 API token + rbac.user.write scope）
    $api->group('/rbac', function (RouteCollectorProxy $rbac) {
        // 写
        $rbac->map(['POST'], '/users', [RbacController::class, 'userCreate']);
        $rbac->map(['PUT'], '/users/{username}', [RbacController::class, 'userUpdate']);
        $rbac->map(['DELETE'], '/users/{username}', [RbacController::class, 'userDelete']);
        // 读
        $rbac->map(['GET'], '/users', [RbacController::class, 'userList']);
        $rbac->map(['GET'], '/users/{username}', [RbacController::class, 'userGet']);
        $rbac->map(['POST'], '/users/{username}/verify-password', [RbacController::class, 'userVerifyPassword']);
        $rbac->map(['GET'], '/roles', [RbacController::class, 'roleList']);
    })->add(AuthMiddleware::class);

    $api->group('/build', function (RouteCollectorProxy $build) {
        $build->map(['GET', 'POST'], '/jobs/list', [BuildController::class, 'jobsList']);
        $build->map(['GET'], '/config-mode', [BuildController::class, 'configMode']);
        $build->map(['GET', 'POST'], '/projects', [BuildController::class, 'projectsList']);
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
        $build->map(['POST'], '/{path:.+}/report', [BuildController::class, 'report']);
        $build->map(['GET', 'POST'], '/{path:.+}/tag', [BuildController::class, 'tagQuery']);
        $build->map(['GET', 'POST'], '/{path:.+}/tags', [BuildController::class, 'tagsList']);
    })->add(AuthMiddleware::class);

    // 监控看板只读端点（Grafana Infinity 数据源消费，复用 RBAC）
    $api->group('/dashboard', function (RouteCollectorProxy $dash) {
        $dash->get('/mapping',    [DashboardController::class, 'mapping']);
        $dash->get('/deployment', [DashboardController::class, 'deployment']);
        $dash->get('/build',      [DashboardController::class, 'build']);
        $dash->get('/trends',     [DashboardController::class, 'trends']);
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

// OAuth2 Provider（授权码流程，供 Grafana 等外部系统用 Glue 账号登录）
// 注意：本组路由不挂 AuthMiddleware —— authorize 是浏览器跳转，token/userinfo 各自校验。
$app->group('/oauth', function (RouteCollectorProxy $oauth) {
    $oauth->get('/authorize',  [OAuthController::class, 'authorizeForm']);
    $oauth->post('/authorize', [OAuthController::class, 'authorizeSubmit']);
    $oauth->post('/token',     [OAuthController::class, 'token']);
    // Grafana 等客户端取 email 时可能用 POST 请求 userinfo，GET/POST 都注册
    $oauth->map(['GET', 'POST'], '/userinfo', [OAuthController::class, 'userinfo']);
    // GitHub 风格邮箱子端点（Grafana 兜底请求 /userinfo/emails）
    $oauth->get('/userinfo/emails', [OAuthController::class, 'userinfoEmails']);
});

// OIDC Discovery / JWKS（供 Jenkins oic-auth / Harbor OIDC / GitLab OmniAuth 自动发现）
// 顶层、不挂 AuthMiddleware：公开元数据，仅含公钥，不泄露私钥。
$app->get('/.well-known/openid-configuration', [OAuthController::class, 'discovery']);
$app->get('/.well-known/jwks.json',              [OAuthController::class, 'jwks']);

