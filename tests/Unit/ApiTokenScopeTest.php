<?php
declare(strict_types=1);

namespace App\Test\Unit;

use App\Config\AppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * API Token scope 目录与路由解析单元测试
 *
 * 验证 resolveRequiredScope() 把「HTTP 方法 + 路径」正确映射到 scope，
 * 并保证 API_SCOPES / API_SCOPE_PERMS 常量内部一致（不依赖数据库）。
 */
class ApiTokenScopeTest extends TestCase
{
    public function testApiScopesCatalogHasSevenScopes(): void
    {
        $this->assertCount(7, AppConfig::API_SCOPES);
        $expected = [
            AppConfig::API_SCOPE_MAIN,
            AppConfig::API_SCOPE_GIT,
            AppConfig::API_SCOPE_HARBOR_READ,
            AppConfig::API_SCOPE_HARBOR_SCAN,
            AppConfig::API_SCOPE_BUILD_READ,
            AppConfig::API_SCOPE_BUILD_WRITE,
            AppConfig::API_SCOPE_BUILD_REPORT,
        ];
        foreach ($expected as $scope) {
            $this->assertArrayHasKey($scope, AppConfig::API_SCOPES, "scope '{$scope}' 必须在 API_SCOPES 目录中");
        }
    }

    public function testScopePermMappingTargetsAreValidPermissionKeys(): void
    {
        $validKeys = array_keys(AppConfig::DEFAULT_PERMISSIONS);
        foreach (AppConfig::API_SCOPE_PERMS as $scope => $perms) {
            $this->assertArrayHasKey($scope, AppConfig::API_SCOPES, "API_SCOPE_PERMS 的 scope '{$scope}' 必须在 API_SCOPES 中");
            foreach ($perms as $perm) {
                $this->assertContains($perm, $validKeys, "scope '{$scope}' 映射的权限 '{$perm}' 必须是合法权限 key");
            }
        }
    }

    public static function routeProvider(): array
    {
        return [
            'health 任意 token'        => ['GET', '/api/health', '*'],
            'admin 禁止'               => ['GET', '/api/admin/users', null],
            'admin me 也禁止'          => ['GET', '/api/admin/me/permissions', null],
            'main 只读'               => ['GET', '/api/main/map/list', AppConfig::API_SCOPE_MAIN],
            'main post 也只读'         => ['POST', '/api/main/jobs/list', AppConfig::API_SCOPE_MAIN],
            'git 只读'                => ['GET', '/api/git/group/proj/branches', AppConfig::API_SCOPE_GIT],
            'harbor 项目列表读'        => ['GET', '/api/harbor/projects', AppConfig::API_SCOPE_HARBOR_READ],
            'harbor 扫描触发写'        => ['POST', '/api/harbor/p/repositories/r/tags/v/scan', AppConfig::API_SCOPE_HARBOR_SCAN],
            'harbor 扫描报告读'        => ['GET', '/api/harbor/p/repositories/r/tags/v/scan', AppConfig::API_SCOPE_HARBOR_READ],
            'build jobs 读'           => ['GET', '/api/build/jobs/list', AppConfig::API_SCOPE_BUILD_READ],
            'build trigger 写'        => ['POST', '/api/build/static/trigger', AppConfig::API_SCOPE_BUILD_WRITE],
            'build retry 写'          => ['POST', '/api/build/static/pipelines/123/retry', AppConfig::API_SCOPE_BUILD_WRITE],
            'build cancel 写'         => ['POST', '/api/build/group/proj/pipelines/9/cancel', AppConfig::API_SCOPE_BUILD_WRITE],
            'build scan-sync 回写'     => ['POST', '/api/build/static/scan-sync', AppConfig::API_SCOPE_BUILD_REPORT],
            'build commit-status 回写' => ['POST', '/api/build/static/commit-status', AppConfig::API_SCOPE_BUILD_REPORT],
            'build pipelines 读'      => ['GET', '/api/build/static/pipelines', AppConfig::API_SCOPE_BUILD_READ],
            'build logs 读'           => ['GET', '/api/build/static/logs/1', AppConfig::API_SCOPE_BUILD_READ],
            'build tag 读'            => ['GET', '/api/build/static/tag', AppConfig::API_SCOPE_BUILD_READ],
            '未知路径 fail-closed'     => ['GET', '/api/unknown/thing', null],
        ];
    }

    #[DataProvider('routeProvider')]
    public function testResolveRequiredScope(string $method, string $path, ?string $expected): void
    {
        $this->assertSame($expected, AppConfig::resolveRequiredScope($method, $path));
    }

    public function testResolveStripsQueryString(): void
    {
        $this->assertSame(
            AppConfig::API_SCOPE_BUILD_WRITE,
            AppConfig::resolveRequiredScope('POST', '/api/build/static/trigger?format=json')
        );
    }

    public function testResolveNormalizesTrailingSlash(): void
    {
        $this->assertSame(AppConfig::API_SCOPE_MAIN, AppConfig::resolveRequiredScope('GET', '/api/main/map/list/'));
    }
}
