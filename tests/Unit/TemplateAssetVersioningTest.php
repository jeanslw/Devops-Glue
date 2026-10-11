<?php

declare(strict_types=1);

namespace App\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 守卫不变量：HTML 模板里的静态资源 URL 必须带 `?v={{APP_VERSION}}`，
 * 且 3 个渲染入口都必须把该占位符替换为当前版本号。
 *
 * 回归背景（v2.8.9）：资源文件名**不带内容哈希**，而 nginx 曾对 `/assets/*.js|css`
 * 下发 `expires 30d` + `Cache-Control: public, immutable` —— 升级镜像后浏览器继续用旧 JS/CSS
 * （`admin.html` 与接口已是新版），表现为「新增的前端功能永远不出现」：
 * 典型症状是后台「数据管理 → 系统信息」的「迁移模式」行恒为内联占位符 `-`
 * （旧 `system.js` 里没有那段渲染逻辑）。
 *
 * 因此新增 `<link>` / `<script>` 引用时必须照抄 `?v={{APP_VERSION}}` 写法；
 * 而「改了模板却忘了给渲染入口加替换」更糟：占位符会原样进浏览器，资源直接 404。
 * 两个方向都由本测试守住。
 */
class TemplateAssetVersioningTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    /**
     * 模板 → 渲染该模板的入口文件（入口必须替换 {{APP_VERSION}}）。
     *
     * @var array<string, list<string>>
     */
    private const TEMPLATES = [
        'admin.html'   => ['config/routes.php'],                  // GET /admin
        'index.html'   => ['public/index.php'],                   // GET /
        'swagger.html' => ['src/Controller/MainController.php'],  // GET /api/docs
    ];

    public function testEveryTemplateAssetReferenceCarriesAppVersionQuery(): void
    {
        foreach (array_keys(self::TEMPLATES) as $template) {
            $html = (string) file_get_contents(self::ROOT . '/templates/' . $template);

            preg_match_all('#/assets/[^"\'\s>]+\.(?:js|css)(?:\?[^"\'\s>]*)?#i', $html, $matches);
            $this->assertNotEmpty($matches[0], 'templates/' . $template . ' 应至少引用一个 /assets 下的 js/css');

            foreach ($matches[0] as $url) {
                $this->assertStringContainsString(
                    '?v={{APP_VERSION}}',
                    $url,
                    'templates/' . $template . ' 的资源引用必须带 ?v={{APP_VERSION}}（无内容哈希，否则升级后浏览器沿用旧文件）：' . $url
                );
            }
        }
    }

    public function testEveryRendererReplacesTheAppVersionPlaceholder(): void
    {
        foreach (self::TEMPLATES as $template => $renderers) {
            foreach ($renderers as $renderer) {
                $this->assertStringContainsString(
                    "str_replace('{{APP_VERSION}}'",
                    (string) file_get_contents(self::ROOT . '/' . $renderer),
                    $renderer . ' 必须把 templates/' . $template . ' 的 {{APP_VERSION}} 替换为 AppConfig::APP_VERSION（否则资源 URL 原样带占位符 → 404）'
                );
            }
        }
    }
}
