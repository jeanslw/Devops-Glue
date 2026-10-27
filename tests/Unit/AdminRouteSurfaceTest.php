<?php

declare(strict_types=1);

namespace App\Test\Unit;

use PHPUnit\Framework\TestCase;
use Slim\Factory\AppFactory;

/**
 * /api/admin 路由面守卫：拆分 AdminController 时，URL 与 HTTP 方法必须逐条不变。
 *
 * 运行：vendor/bin/phpunit --filter AdminRouteSurfaceTest
 */
class AdminRouteSurfaceTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    /**
     * /api/admin 前缀下共 56 条：53 条来自 AdminController 拆分，3 条 integrated_services
     * 同组且不迁移。由探针脚本（见方案附录 A）一次性生成后粘入，逐条比对。
     *
     * @var list<string>
     */
    private const EXPECTED_ADMIN_SURFACE = [
        'DELETE /api/admin/api_tokens/{id}',
        'DELETE /api/admin/implied_rules',
        'DELETE /api/admin/job_git_map',
        'DELETE /api/admin/permissions/{perm_key}',
        'DELETE /api/admin/roles/{id}',
        'DELETE /api/admin/users/{username}',
        'GET /api/admin/api_access_logs',
        'GET /api/admin/api_tokens',
        'GET /api/admin/api_tokens/scopes',
        'GET /api/admin/backups',
        'GET /api/admin/build_mode',
        'GET /api/admin/custom_builds',
        'GET /api/admin/deploy_logs',
        'GET /api/admin/integrated_services/{service}',
        'GET /api/admin/job_git_map',
        'GET /api/admin/me/permissions',
        'GET /api/admin/oidc_config',
        'GET /api/admin/operation_logs',
        'GET /api/admin/permissions',
        'GET /api/admin/platform_config',
        'GET /api/admin/platform_connections',
        'GET /api/admin/platform_versions',
        'GET /api/admin/platform_versions/probe',
        'GET /api/admin/roles',
        'GET /api/admin/security_checks',
        'GET /api/admin/system_info',
        'GET /api/admin/users',
        'POST /api/admin/api_tokens',
        'POST /api/admin/api_tokens/{id}/revoke',
        'POST /api/admin/backup',
        'POST /api/admin/discover',
        'POST /api/admin/implied_rules',
        'POST /api/admin/integrated_services/{service}/disable',
        'POST /api/admin/integrated_services/{service}/enable',
        'POST /api/admin/job_git_map',
        'POST /api/admin/login',
        'POST /api/admin/login_locks/clear',
        'POST /api/admin/logout',
        'POST /api/admin/migrate',
        'POST /api/admin/permissions',
        'POST /api/admin/roles',
        'POST /api/admin/tag_backfill',
        'POST /api/admin/tag_cleanup',
        'POST /api/admin/users',
        'PUT /api/admin/api_tokens/{id}/scopes',
        'PUT /api/admin/build_mode',
        'PUT /api/admin/job_git_map',
        'PUT /api/admin/oidc_config',
        'PUT /api/admin/password',
        'PUT /api/admin/platform_config',
        'PUT /api/admin/platform_connections',
        'PUT /api/admin/platform_versions',
        'PUT /api/admin/roles/{id}',
        'PUT /api/admin/users/{username}',
        'PUT /api/admin/users/{username}/password',
        'PUT /api/admin/users/{username}/status',
    ];

    public function testAdminRouteSurfaceIsStable(): void
    {
        $surface = [];
        foreach ($this->routes() as $route) {
            if (str_starts_with($route->getPattern(), '/api/admin')) {
                foreach ($route->getMethods() as $method) {
                    $surface[] = $method . ' ' . $route->getPattern();
                }
            }
        }
        sort($surface);

        $this->assertSame(self::EXPECTED_ADMIN_SURFACE, $surface, '/api/admin 路由的 URL 或方法发生了变化');
    }

    public function testRouteObjectCountIsStable(): void
    {
        // 路由对象数（非按方法展开数）：112
        $this->assertCount(112, $this->routes(), '全仓路由对象数发生变化（拆分不应新增/删除任何路由）');
    }

    /**
     * 引导 Slim 并加载真实路由表。
     *
     * 注意：config/routes.php 首部会校验 $app 变量名，故变量必须叫 $app 且为 Slim\App；
     * 引导用 Slim\Factory\AppFactory::create() 无需数据库或容器。
     *
     * @return array<\Slim\Routing\Route>
     */
    private function routes(): array
    {
        $app = AppFactory::create();
        require self::ROOT . '/config/routes.php';

        return $app->getRouteCollector()->getRoutes();
    }
}
