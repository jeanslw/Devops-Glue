<?php

declare(strict_types=1);

namespace App\Test\Unit;

use App\Service\I18nService;
use PHPUnit\Framework\TestCase;

/**
 * 守卫不变量：「API 管理 → 编辑 Scope」功能必须**四层齐活**（v2.9.0 新增）。
 *
 * 该功能横跨 4 层：路由（config/routes.php）→ 控制器（AdminController）→
 * 服务（ApiTokenService）→ 前端（admin.html 表单 + apiTokens.js 调用 + admin.js 挂 window）。
 * 任一层漏做都是「半接线」故障：最常见的是**接口做好了但页面按钮调不到**
 * （inline onclick 依赖 admin.js 的 Object.assign(window, …)），或**双语翻译只补了一半**
 * ——两者都不会让任何 PHP 单测变红，只会在浏览器里静默失效，故在此显式守住。
 */
class ApiTokenScopeEditWiringTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    /** 编辑 Scope 涉及的双语翻译键：缺一个就会出现「页面显示 api_token.xxx」原文键。 */
    private const I18N_KEYS = [
        'api_token.edit_scopes',
        'api_token.edit_title',
        'api_token.edit_target',
        'api_token.edit_hint',
        'api_token.scopes_required',
        'api_token.scopes_invalid',
        'api_token.scopes_updated',
        'api_token.not_found',
    ];

    public function testRouteIsRegistered(): void
    {
        $routes = $this->read(self::ROOT . '/config/routes.php');

        $this->assertMatchesRegularExpression(
            "/\\\$auth->map\(\\['PUT'\\], '\\/api_tokens\\/\\{id\\}\\/scopes', \\[AdminController::class, 'apiTokenUpdateScopes'\\]\)/",
            $routes,
            'PUT /api/admin/api_tokens/{id}/scopes 必须注册（并走 AuthMiddleware 那一组）'
        );
    }

    public function testControllerDelegatesToServiceAndAudits(): void
    {
        $controller = $this->read(self::ROOT . '/src/Controller/AdminController.php');

        $this->assertStringContainsString('public function apiTokenUpdateScopes(', $controller);
        $this->assertStringContainsString('->updateScopes($id, $payload)', $controller, '控制器应把 scopes 交给服务层校验+落库');
        $this->assertStringContainsString("'update_api_token_scopes'", $controller, '编辑是权限收放动作，必须写操作日志');
        $this->assertStringContainsString("'api_token.not_found'", $controller, 'Token 不存在应回 404 而不是静默成功');
    }

    public function testServiceValidatesAgainstScopeCatalog(): void
    {
        $service = $this->read(self::ROOT . '/src/Service/ApiTokenService.php');

        $this->assertStringContainsString('public function updateScopes(int $id, mixed $scopes): bool', $service);
        $this->assertStringContainsString('AppConfig::API_SCOPES', $service, '编辑必须按 scope 目录白名单校验');
        $this->assertStringContainsString('Unknown scope', $service, '目录外的 scope 必须抛异常（不得静默丢弃）');
    }

    public function testAdminHtmlExposesEditFormAndHandlers(): void
    {
        $html = $this->read(self::ROOT . '/templates/admin.html');

        foreach (['api-token-scope-form', 'api-token-edit-scopes', 'api-token-edit-name', 'api-token-edit-msg'] as $id) {
            $this->assertStringContainsString('id="' . $id . '"', $html, '编辑表单缺少 #' . $id);
        }
        $this->assertStringContainsString('onsubmit="submitApiTokenScopeForm(event)"', $html);
        $this->assertStringContainsString('onclick="hideApiTokenScopeForm()"', $html);
    }

    public function testInlineOnclickHandlersAreExposedOnWindow(): void
    {
        $adminJs = $this->read(self::ROOT . '/public/assets/admin.js');
        $this->assertMatchesRegularExpression(
            '/Object\.assign\(window,\s*\{([\s\S]*?)\n\}\);/',
            $adminJs,
            '未找到 window 全局挂载块——inline onclick 全部依赖它'
        );
        preg_match('/Object\.assign\(window,\s*\{([\s\S]*?)\n\}\);/', $adminJs, $m);
        $globals = $m[1];

        foreach (['showApiTokenScopeForm', 'hideApiTokenScopeForm', 'submitApiTokenScopeForm'] as $fn) {
            $this->assertStringContainsString($fn, $globals, $fn . ' 必须挂到 window，否则按钮 / 表单 onclick 会静默失效');
        }
    }

    public function testModuleCallsThePutEndpointAndRendersEditBoxes(): void
    {
        $js = $this->read(self::ROOT . '/public/assets/modules/apiTokens.js');

        $this->assertStringContainsString('export async function showApiTokenScopeForm(id)', $js);
        $this->assertStringContainsString('export function hideApiTokenScopeForm()', $js);
        $this->assertStringContainsString('export async function submitApiTokenScopeForm(e)', $js);
        $this->assertStringContainsString("'/api/admin/api_tokens/' + _editingTokenId + '/scopes'", $js);
        $this->assertStringContainsString("method: 'PUT'", $js, '编辑必须用 PUT（与路由一致）');
        $this->assertStringContainsString("renderScopeBoxes('api-token-edit-scopes'", $js, '编辑框需复用 scope 勾选框渲染');
        $this->assertStringContainsString('onclick="showApiTokenScopeForm(', $js, '列表行应提供编辑入口');
    }

    public function testEditTranslationsExistInBothLocales(): void
    {
        $i18n = new I18nService(self::ROOT . '/lang', 'zh_CN');
        $zh = $i18n->getAll('zh_CN');
        $en = $i18n->getAll('en');

        foreach (self::I18N_KEYS as $key) {
            $this->assertArrayHasKey($key, $zh, 'zh_CN 缺少翻译 ' . $key);
            $this->assertArrayHasKey($key, $en, 'en 缺少翻译 ' . $key);
            $this->assertNotSame('', trim((string)$zh[$key]), 'zh_CN 翻译为空: ' . $key);
            $this->assertNotSame('', trim((string)$en[$key]), 'en 翻译为空: ' . $key);
        }
    }

    private function read(string $path): string
    {
        $content = file_get_contents($path);
        $this->assertIsString($content, '无法读取 ' . $path);

        return $content;
    }
}
