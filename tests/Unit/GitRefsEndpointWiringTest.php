<?php

declare(strict_types=1);

namespace App\Test\Unit;

use App\Config\AppConfig;
use App\Support\ApiScopeResolver;
use PHPUnit\Framework\TestCase;

/**
 * v2.9.0「CD 构建页可用 tag 作构建 ref」的跨层接线测试（需求 5 + 需求 2 的凭据契约）。
 *
 * 这条链路横跨 7 层，任一层漏做都不会被别的单测发现：
 *   1) 路由      —— 有控制器没路由 = 404；
 *   2) 控制器    —— 有路由没实现 = 500；
 *   3) Service   —— 必须有真的平台调用（假实现会让端点永远回空数组）；
 *   4) scope     —— /api/git/* 判 git，AuthMiddleware 对未知 scope fail-closed；
 *   5) 凭据契约  —— CD_SERVICE_TOKEN_SCOPES 漏 git，CD 新签发的 token 依然 403（最隐蔽）；
 *   6) OpenAPI   —— 漏记则对接方（CD/第三方）不知道端点存在；
 *   7) 自愈接线  —— 存量 cd-service 凭据的 scope 补齐必须挂在 bootstrap 上。
 */
class GitRefsEndpointWiringTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    public function testRoutesRegisterTagsAndRefsAlongsideBranches(): void
    {
        $routes = $this->read('config/routes.php');

        // {path:.+} 是 CD 传 group/sub/repo 形式 job 名的前提，缺了就只能匹配单段路径
        $this->assertStringContainsString("'/{path:.+}/branches', [GitController::class, 'branches']", $routes);
        $this->assertStringContainsString("'/{path:.+}/tags', [GitController::class, 'tags']", $routes);
        $this->assertStringContainsString("'/{path:.+}/refs', [GitController::class, 'refs']", $routes);
    }

    public function testControllerExposesTagsAndRefsActions(): void
    {
        $ctrl = $this->read('src/Controller/GitController.php');

        $this->assertMatchesRegularExpression('/public function tags\(/', $ctrl);
        $this->assertMatchesRegularExpression('/public function refs\(/', $ctrl);
        $this->assertStringContainsString('getTagsForJob', $ctrl);
        $this->assertStringContainsString('getRefsForJob', $ctrl);
        // 三个只读动作复用同一权限点：新增端点不引入新权限键（否则要同步 RBAC 目录与文档）
        $this->assertSame(
            3,
            substr_count($ctrl, 'AppConfig::PERM_CI_BUILD_RECORDS_PULL'),
            'branches/tags/refs 应各自 requirePermission(ci.build-records.pull)'
        );
    }

    public function testServiceActuallyCallsProviderGetTags(): void
    {
        $svc = $this->read('src/Service/GitService.php');

        $this->assertMatchesRegularExpression('/public function getTagsForJob\(/', $svc);
        $this->assertMatchesRegularExpression('/public function getRefsForJob\(/', $svc);
        $this->assertStringContainsString(
            '$provider->getTags($repo)',
            $svc,
            '必须真的调用平台 provider 的 getTags，否则端点永远回空数组'
        );
    }

    public function testScopeResolverTreatsWholeGitGroupAsGitScope(): void
    {
        $paths = [
            '/api/git/php/myapp/tags',
            '/api/git/php/myapp/refs',
            '/api/git/group/sub/proj/tags',
        ];
        foreach ($paths as $path) {
            $this->assertSame(AppConfig::API_SCOPE_GIT, ApiScopeResolver::resolve('GET', $path), "{$path} 应判 git scope");
        }
    }

    public function testCdServiceTokenScopesIncludeGit(): void
    {
        $this->assertContains(
            AppConfig::API_SCOPE_GIT,
            AppConfig::CD_SERVICE_TOKEN_SCOPES,
            'CD 服务凭据必须持有 git：否则 CD 调新端点会被 AuthMiddleware 判 403'
        );
        // 原有三件套不得因本次调整被顶掉（数组字面量手改最容易漏）
        $keep = [AppConfig::API_SCOPE_BUILD_READ, AppConfig::API_SCOPE_BUILD_WRITE, AppConfig::API_SCOPE_RBAC_USER_WRITE];
        foreach ($keep as $scope) {
            $this->assertContains($scope, AppConfig::CD_SERVICE_TOKEN_SCOPES, "CD 凭据仍应保留 {$scope}");
        }
        // git 是只读 scope：不得顺势把写/扫描类 scope 塞进契约
        $this->assertNotContains(AppConfig::API_SCOPE_BUILD_REPORT, AppConfig::CD_SERVICE_TOKEN_SCOPES);
        $this->assertNotContains(AppConfig::API_SCOPE_HARBOR_SCAN, AppConfig::CD_SERVICE_TOKEN_SCOPES);
    }

    public function testGitScopeCapabilitiesListTheThreeReadOnlyEndpoints(): void
    {
        $this->assertSame(
            ['git.branches', 'git.tags', 'git.refs'],
            AppConfig::API_SCOPE_CAPABILITIES[AppConfig::API_SCOPE_GIT]
        );
    }

    public function testOpenApiDocumentsTagsAndRefs(): void
    {
        $expectedSchemas = [
            '/api/git/{path}/tags' => 'array',
            '/api/git/{path}/refs' => 'object',
        ];

        // 中英双份 OpenAPI 必须同步：漏一份 = 对应语言的对接方（含 CD）看不到端点
        foreach (['templates/openapi.json', 'templates/openapi.en.json'] as $file) {
            $doc = json_decode($this->read($file), true);
            $this->assertIsArray($doc, "{$file} 必须是合法 JSON");

            foreach ($expectedSchemas as $path => $type) {
                $this->assertArrayHasKey($path, $doc['paths'], "{$file} 缺少 {$path}");
                $get = $doc['paths'][$path]['get'];
                $this->assertSame(['Git'], $get['tags']);
                $this->assertArrayHasKey('bearerAuth', $get['security'][0], "{$file} {$path} 必须声明 bearer 鉴权");
                $this->assertSame(
                    $type,
                    $get['responses']['200']['content']['application/json']['schema']['type'],
                    "{$file} {$path} 的 200 响应结构应声明为 {$type}"
                );
            }
        }
    }

    public function testLegacyCdCredentialHealIsWiredIntoBootstrap(): void
    {
        $db = $this->read('src/Service/Database.php');

        // v2.9.0：自愈从 ensureTables 提升到 bootstrap 公共段（手动建库模式升级同样生效），
        // migrateNow（后台"同步库结构"/cli/migrate.php）保持同一接线
        $this->assertSame(
            2,
            substr_count($db, 'self::healCdServiceTokenScopes(self::pdo())'),
            'bootstrap() 与 migrateNow() 两处都必须调用自愈：否则存量 CD 凭据缺 git scope，升级后一路 403'
        );
        $this->assertStringContainsString('public function addScopes(', $this->read('src/Service/ApiTokenService.php'));
        $this->assertStringContainsString(
            'AppConfig::CD_SERVICE_TOKEN_SCOPES',
            $this->read('src/Controller/IntegratedServiceController.php'),
            '冻结后恢复同一枚凭据时也要补齐 scope（运维动作「禁用再启用」不能补出残缺凭据）'
        );
    }

    public function testGitQueryFailedMessageExistsInBothLocales(): void
    {
        // 新端点复用既有错误键，不得引入只有单语种的新文案
        foreach (['lang/zh_CN/messages.php', 'lang/en/messages.php'] as $file) {
            $this->assertStringContainsString("'git.query_failed'", $this->read($file), "{$file} 缺少 git.query_failed");
        }
    }

    private function read(string $relative): string
    {
        $file = self::ROOT . '/' . $relative;
        $this->assertFileExists($file);
        return (string) file_get_contents($file);
    }
}
