<?php
use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use App\Config\AppConfig;
use App\Controller\GitController;
use App\Controller\HealthzController;
use App\Controller\MainController;
use App\Controller\HarborController;
use App\Controller\RegistryController;
use App\Controller\AdminController;
use App\Controller\Admin\AuthController;
use App\Controller\Admin\UserController;
use App\Controller\Admin\RoleController;
use App\Controller\Admin\PermissionController;
use App\Controller\Admin\ApiTokenController;
use App\Controller\ServiceContractController;
use App\Controller\IntegratedServiceController;
use App\Controller\RbacController;
use App\Controller\BuildController;
use App\Controller\OAuthController;
use App\Middleware\AuthMiddleware;


if (!isset($app) || !$app instanceof App) {
    throw new \RuntimeException('routes.php 必须由应用入口在 $app（Slim\App）就绪后加载');
}

// 管理页面
$app->get('/admin', function ($request, $response) {
    $htmlFile = __DIR__ . '/../templates/admin.html';
    $html = file_exists($htmlFile) ? (string) file_get_contents($htmlFile) : '<h1>Page Not Found / 页面丢失</h1>';
    $response->getBody()->write(str_replace('{{APP_VERSION}}', AppConfig::APP_VERSION, $html));
    return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
});

// 存活探针
$app->get('/healthz', [HealthzController::class, '__invoke']);

$app->group('/api', function (RouteCollectorProxy $api) {

    
    $api->map(['GET'], '/health', [MainController::class, 'healthDetail'])->add(AuthMiddleware::class);
    $api->map(['GET'], '/health/static', [MainController::class, 'healthStatic'])->add(AuthMiddleware::class);
    $api->get('/i18n/{locale}', [MainController::class, 'i18n']);

    
    $api->get('/docs', [MainController::class, 'docs']);

    
    $api->get('/openapi.json', [MainController::class, 'openapiJson']);

    $api->group('/main', function (RouteCollectorProxy $main) {
        $main->map(['GET', 'POST'], '/jobs/list', [MainController::class, 'jobsList']);
        $main->map(['GET', 'POST'], '/map/list', [MainController::class, 'mapList']);
        $main->map(['GET', 'POST'], '/git/platforms', [MainController::class, 'gitPlatforms']);
        $main->map(['GET', 'POST'], '/git/discovery', [MainController::class, 'gitDiscovery']);
    })->add(AuthMiddleware::class);

    $api->group('/admin', function (RouteCollectorProxy $admin) {
        $admin->map(['POST'], '/login', [AuthController::class, 'login']);
        $admin->map(['POST'], '/logout', [AuthController::class, 'logout']);

        
        $admin->group('', function (RouteCollectorProxy $auth) {
            $auth->map(['PUT'], '/password', [AuthController::class, 'changePassword']);
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
            $auth->map(['GET'], '/users', [UserController::class, 'userList']);
            $auth->map(['POST'], '/users', [UserController::class, 'userCreate']);
            $auth->map(['PUT'], '/users/{username}', [UserController::class, 'userUpdate']);
            $auth->map(['PUT'], '/users/{username}/password', [UserController::class, 'userModifyPassword']);
            $auth->map(['PUT'], '/users/{username}/status', [UserController::class, 'userSetStatus']);
            $auth->map(['DELETE'], '/users/{username}', [UserController::class, 'userDelete']);
            $auth->map(['POST'], '/login_locks/clear', [AuthController::class, 'clearLoginLocks']);
            $auth->map(['GET'], '/roles', [RoleController::class, 'roleList']);
            $auth->map(['POST'], '/roles', [RoleController::class, 'roleCreate']);
            $auth->map(['PUT'], '/roles/{id}', [RoleController::class, 'roleUpdate']);
            $auth->map(['DELETE'], '/roles/{id}', [RoleController::class, 'roleDelete']);
            $auth->map(['GET'], '/permissions', [PermissionController::class, 'permissionList']);
            $auth->map(['POST'], '/permissions', [PermissionController::class, 'permissionRegister']);
            $auth->map(['DELETE'], '/permissions/{perm_key}', [PermissionController::class, 'permissionDelete']);
            $auth->map(['POST'], '/implied_rules', [PermissionController::class, 'impliedRuleCreate']);
            $auth->map(['DELETE'], '/implied_rules', [PermissionController::class, 'impliedRuleDelete']);
            $auth->map(['GET'], '/me/permissions', [AuthController::class, 'mePermissions']);
            $auth->map(['GET'], '/operation_logs', [AdminController::class, 'operationLogList']);
            $auth->map(['GET'], '/deploy_logs', [AdminController::class, 'deployLogList']);
            $auth->map(['GET'], '/api_access_logs', [AdminController::class, 'apiAccessLogList']);
            $auth->map(['GET'], '/platform_config', [AdminController::class, 'platformConfig']);
            $auth->map(['PUT'], '/platform_config', [AdminController::class, 'updatePlatformConfig']);
            $auth->map(['GET'], '/oidc_config', [AdminController::class, 'oidcConfig']);
            $auth->map(['PUT'], '/oidc_config', [AdminController::class, 'updateOidcConfig']);
            $auth->map(['GET'], '/platform_connections', [AdminController::class, 'platformConnections']);
            $auth->map(['PUT'], '/platform_connections', [AdminController::class, 'updatePlatformConnections']);
            
            $auth->map(['POST'], '/tag_cleanup', [AdminController::class, 'runTagCleanup']);
            $auth->map(['POST'], '/tag_backfill', [AdminController::class, 'runTagBackfill']);
            $auth->map(['GET'], '/system_info', [AdminController::class, 'systemInfo']);
            $auth->map(['POST'], '/migrate', [AdminController::class, 'migrateSchema']);
            $auth->map(['POST'], '/backup', [AdminController::class, 'backupDatabase']);
            $auth->map(['GET'], '/backups', [AdminController::class, 'listBackups']);
            $auth->map(['GET'], '/api_tokens/scopes', [ApiTokenController::class, 'apiTokenScopes']);
            $auth->map(['GET'], '/api_tokens', [ApiTokenController::class, 'apiTokenList']);
            $auth->map(['POST'], '/api_tokens', [ApiTokenController::class, 'apiTokenCreate']);
            $auth->map(['PUT'], '/api_tokens/{id}/scopes', [ApiTokenController::class, 'apiTokenUpdateScopes']);
            $auth->map(['POST'], '/api_tokens/{id}/revoke', [ApiTokenController::class, 'apiTokenRevoke']);
            $auth->map(['DELETE'], '/api_tokens/{id}', [ApiTokenController::class, 'apiTokenDelete']);
            
            $auth->map(['GET'], '/integrated_services/{service}', [IntegratedServiceController::class, 'status']);
            $auth->map(['POST'], '/integrated_services/{service}/enable', [IntegratedServiceController::class, 'enable']);
            $auth->map(['POST'], '/integrated_services/{service}/disable', [IntegratedServiceController::class, 'disable']);
        })->add(AuthMiddleware::class);
    });

    // 集成服务契约
    $api->group('/integration', function (RouteCollectorProxy $integration) {
        $integration->map(['GET'], '/services/{service}', [ServiceContractController::class, 'show']);
    })->add(AuthMiddleware::class);

    // RBAC 接口
    $api->group('/rbac', function (RouteCollectorProxy $rbac) {
        
        $rbac->map(['POST'], '/users', [RbacController::class, 'userCreate']);
        $rbac->map(['PUT'], '/users/{username}', [RbacController::class, 'userUpdate']);
        $rbac->map(['DELETE'], '/users/{username}', [RbacController::class, 'userDelete']);
        
        $rbac->map(['GET'], '/users', [RbacController::class, 'userList']);
        $rbac->map(['GET'], '/users/{username}', [RbacController::class, 'userGet']);
        $rbac->map(['GET'], '/roles', [RbacController::class, 'roleList']);
        
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
        $build->map(['POST'], '/{path:.+}/registry-sync', [BuildController::class, 'registrySync']);
        $build->map(['POST'], '/{path:.+}/commit-status', [BuildController::class, 'commitStatus']);
        $build->map(['POST'], '/{path:.+}/report', [BuildController::class, 'report']);
        $build->map(['GET', 'POST'], '/{path:.+}/tag', [BuildController::class, 'tagQuery']);
        $build->map(['GET', 'POST'], '/{path:.+}/tags', [BuildController::class, 'tagsList']);
    })->add(AuthMiddleware::class);

    $api->group('/git', function (RouteCollectorProxy $git) {
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

    // 镜像仓库通用列表
    $api->group('/registry', function (RouteCollectorProxy $registry) {
        $registry->map(['GET', 'POST'], '/projects', [RegistryController::class, 'getProjectsList']);
        $registry->map(['GET', 'POST'], '/{project}/repositories', [RegistryController::class, 'getRepositoriesList']);
        $registry->map(['GET', 'POST'], '/{project}/repositories/{repository}/tags', [RegistryController::class, 'getTagsList']);
    })->add(AuthMiddleware::class);
});


$app->group('/oauth', function (RouteCollectorProxy $oauth) {
    $oauth->get('/authorize',  [OAuthController::class, 'authorizeForm']);
    $oauth->post('/authorize', [OAuthController::class, 'authorizeSubmit']);
    $oauth->post('/token',     [OAuthController::class, 'token']);
    $oauth->map(['GET', 'POST'], '/userinfo', [OAuthController::class, 'userinfo']);
    $oauth->get('/userinfo/emails', [OAuthController::class, 'userinfoEmails']);
});

// OIDC Discovery / JWKS
$app->get('/.well-known/openid-configuration', [OAuthController::class, 'discovery']);
$app->get('/.well-known/jwks.json',              [OAuthController::class, 'jwks']);

