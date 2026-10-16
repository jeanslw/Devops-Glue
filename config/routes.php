<?php
use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use App\Config\AppConfig;
use App\Controller\GitController;
use App\Controller\HealthzController;
use App\Controller\MainController;
use App\Controller\HarborController;
use App\Controller\AdminController;
use App\Controller\ServiceContractController;
use App\Controller\IntegratedServiceController;
use App\Controller\RbacController;
use App\Controller\BuildController;
use App\Controller\OAuthController;
use App\Middleware\AuthMiddleware;


// 本文件由 public/index.php 在 $app 就绪后直接 require（不走容器）。
// 防御：$app 缺失/类型错误时在启动阶段显式失败，而不是留下 undefined variable 运行时错。
if (!isset($app) || !$app instanceof App) {
    throw new \RuntimeException('routes.php 必须由应用入口在 $app（Slim\App）就绪后加载');
}

// 管理页面
// admin.html 里的静态资源都写成 /assets/xxx.js?v={{APP_VERSION}}：资源文件名不带内容哈希，
//    若 URL 不随版本变化，升级镜像后浏览器会继续用被强缓存的旧 JS/CSS（HTML 已是新版），
//    表现为新增的前端功能始终不出现。故此处必须与 / 路由一致，把占位符替换为当前版本号。
$app->get('/admin', function ($request, $response) {
    $htmlFile = __DIR__ . '/../templates/admin.html';
    $html = file_exists($htmlFile) ? (string) file_get_contents($htmlFile) : '<h1>Page Not Found / 页面丢失</h1>';
    $response->getBody()->write(str_replace('{{APP_VERSION}}', AppConfig::APP_VERSION, $html));
    return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
});

// 存活/就绪探针（无需鉴权）：供 Uptime Kuma / 云 LB / 容器编排抓取。
// 只探 DB 可达性 + schema 版本，DB 不可访问时返回 503（而非 500）。
// 独立 HealthzController（零构造依赖），避免经 MainController 的 \PDO DI 解析在 DB 宕机时先抛 500。
$app->get('/healthz', [HealthzController::class, '__invoke']);

$app->group('/api', function (RouteCollectorProxy $api) {

    // 健康检查（需要鉴权）：/api/health 返回详细检查信息，受 AuthMiddleware 保护
    $api->map(['GET'], '/health', [MainController::class, 'healthDetail'])->add(AuthMiddleware::class);
    // 快速健康信息（需要鉴权）：不探测外部平台，毫秒级返回，用于先渲染非探测卡片
    $api->map(['GET'], '/health/static', [MainController::class, 'healthStatic'])->add(AuthMiddleware::class);

    // 国际化：获取指定语言的语言包（供前端使用）
    $api->get('/i18n/{locale}', [MainController::class, 'i18n']);

    // API 文档 (Swagger UI) —— 页面轻量放行，数据鉴权在 openapi.json/各 API；
    // 登录框内嵌页面（swagger.html），无独立登录路由
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
            $auth->map(['GET'], '/platform_versions/probe', [AdminController::class, 'platformVersionsProbe']);
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
            $auth->map(['POST'], '/login_locks/clear', [AdminController::class, 'clearLoginLocks']);
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
            $auth->map(['GET'], '/operation_logs', [AdminController::class, 'operationLogList']);
            $auth->map(['GET'], '/deploy_logs', [AdminController::class, 'deployLogList']);
            $auth->map(['GET'], '/api_access_logs', [AdminController::class, 'apiAccessLogList']);
            $auth->map(['GET'], '/platform_config', [AdminController::class, 'platformConfig']);
            $auth->map(['PUT'], '/platform_config', [AdminController::class, 'updatePlatformConfig']);
            // 手动触发 tag 定时任务（与开关同权限 ci.platform-config，后端仍校验开关必须先开启）
            $auth->map(['POST'], '/tag_cleanup', [AdminController::class, 'runTagCleanup']);
            $auth->map(['POST'], '/tag_backfill', [AdminController::class, 'runTagBackfill']);
            $auth->map(['GET'], '/system_info', [AdminController::class, 'systemInfo']);
            $auth->map(['POST'], '/migrate', [AdminController::class, 'migrateSchema']);
            $auth->map(['POST'], '/backup', [AdminController::class, 'backupDatabase']);
            $auth->map(['GET'], '/backups', [AdminController::class, 'listBackups']);
            $auth->map(['GET'], '/api_tokens/scopes', [AdminController::class, 'apiTokenScopes']);
            $auth->map(['GET'], '/api_tokens', [AdminController::class, 'apiTokenList']);
            $auth->map(['POST'], '/api_tokens', [AdminController::class, 'apiTokenCreate']);
            $auth->map(['PUT'], '/api_tokens/{id}/scopes', [AdminController::class, 'apiTokenUpdateScopes']);
            $auth->map(['POST'], '/api_tokens/{id}/revoke', [AdminController::class, 'apiTokenRevoke']);
            $auth->map(['DELETE'], '/api_tokens/{id}', [AdminController::class, 'apiTokenDelete']);
            // 集成服务启停（integrated_services 契约表；读 ci.system，写仅 super_admin，启用自动签发限定 scope token）
            $auth->map(['GET'], '/integrated_services/{service}', [IntegratedServiceController::class, 'status']);
            $auth->map(['POST'], '/integrated_services/{service}/enable', [IntegratedServiceController::class, 'enable']);
            $auth->map(['POST'], '/integrated_services/{service}/disable', [IntegratedServiceController::class, 'disable']);
        })->add(AuthMiddleware::class);
    });

    // 集成服务契约（窄只读端点，供 CD 等集成服务 HTTP 自检「是否被授权 / 以哪种方式建表」，
    // 免于直读共享库的 integrated_services 表；scope 复用 build.read，与管理面 /api/admin/integrated_services/* 分离）
    $api->group('/integration', function (RouteCollectorProxy $integration) {
        $integration->map(['GET'], '/services/{service}', [ServiceContractController::class, 'show']);
    })->add(AuthMiddleware::class);

    // RBAC 接口（CD 服务账号专用，仅 API token + rbac.user.write scope）
    $api->group('/rbac', function (RouteCollectorProxy $rbac) {
        // 写
        $rbac->map(['POST'], '/users', [RbacController::class, 'userCreate']);
        $rbac->map(['PUT'], '/users/{username}', [RbacController::class, 'userUpdate']);
        $rbac->map(['DELETE'], '/users/{username}', [RbacController::class, 'userDelete']);
        // 读
        $rbac->map(['GET'], '/users', [RbacController::class, 'userList']);
        $rbac->map(['GET'], '/users/{username}', [RbacController::class, 'userGet']);
        $rbac->map(['GET'], '/roles', [RbacController::class, 'roleList']);
        // 角色权限清单（CD 权限映射用；scope 仍由 ApiScopeResolver 按整段 /api/rbac/* 统一判 rbac.user.write）
        $rbac->map(['GET'], '/roles/{name}/permissions', [RbacController::class, 'rolePermissions']);
    })->add(AuthMiddleware::class);

    $api->group('/build', function (RouteCollectorProxy $build) {
        $build->map(['GET', 'POST'], '/jobs/list', [BuildController::class, 'jobsList']);
        $build->map(['GET'], '/config-mode', [BuildController::class, 'configMode']);
        $build->map(['GET', 'POST'], '/projects', [BuildController::class, 'projectsList']);
        $build->map(['GET', 'POST'], '/{path:.+}/pipelines', [BuildController::class, 'pipelines']);
        $build->map(['GET', 'POST'], '/{path:.+}/pipelines/{id:\d+}', [BuildController::class, 'pipelineDetail']);
        $build->map(['POST'], '/{path:.+}/pipelines/{id:\d+}/resolve-tag', [BuildController::class, 'resolveTag']);
        $build->map(['GET', 'POST'], '/{path:.+}/pipelines/{id:\d+}/logs', [BuildController::class, 'pipelineLogs']);
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

    $api->group('/git', function (RouteCollectorProxy $git) {
        // {path:.+} 允许 group/sub/repo 形式的多级 job/仓库标识（内部再做平台侧编码）。
        // tags / refs 供 CD 构建页做 ref 候选值（refs 一次返回分支 + tag，省一次映射解析）。
        $git->map(['GET', 'POST'], '/{path:.+}/branches', [GitController::class, 'branches']);
        $git->map(['GET', 'POST'], '/{path:.+}/tags', [GitController::class, 'tags']);
        $git->map(['GET', 'POST'], '/{path:.+}/refs', [GitController::class, 'refs']);
    })->add(AuthMiddleware::class);

    $api->group('/harbor', function (RouteCollectorProxy $harbor) {
        $harbor->map(['GET', 'POST'], '/projects', [HarborController::class, 'getProjectsList']);
        $harbor->map(['GET', 'POST'], '/{project}/repositories', [HarborController::class, 'getRepositoriesList']);
        $harbor->map(['GET', 'POST'], '/{project}/repositories/{repository}/tags', [HarborController::class, 'getTagsList']);
        $harbor->map(['POST'], '/{project}/repositories/{repository}/tags/{tag}/scan', [HarborController::class, 'scanTrigger']);
        $harbor->map(['GET'], '/{project}/repositories/{repository}/tags/{tag}/scan', [HarborController::class, 'getScanReport']);
    })->add(AuthMiddleware::class);
});

// OAuth2 Provider（授权码流程，供外部系统用 Glue 账号登录）
// 本组路由不挂 AuthMiddleware：authorize 由浏览器直接跳转，token / userinfo 各自校验凭证。
$app->group('/oauth', function (RouteCollectorProxy $oauth) {
    $oauth->get('/authorize',  [OAuthController::class, 'authorizeForm']);
    $oauth->post('/authorize', [OAuthController::class, 'authorizeSubmit']);
    $oauth->post('/token',     [OAuthController::class, 'token']);
    // userinfo 同时接受 GET 与 POST（部分 OAuth2 客户端用 POST 取用户信息）
    $oauth->map(['GET', 'POST'], '/userinfo', [OAuthController::class, 'userinfo']);
    // GitHub 风格邮箱子端点（兼容按 GitHub emails 流程取邮箱的 OAuth2 客户端）
    $oauth->get('/userinfo/emails', [OAuthController::class, 'userinfoEmails']);
});

// OIDC Discovery / JWKS（供 Jenkins oic-auth / Harbor OIDC / GitLab OmniAuth 自动发现）
// 顶层、不挂 AuthMiddleware：公开元数据，仅含公钥，不泄露私钥。
$app->get('/.well-known/openid-configuration', [OAuthController::class, 'discovery']);
$app->get('/.well-known/jwks.json',              [OAuthController::class, 'jwks']);

