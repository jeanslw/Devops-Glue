<?php

declare(strict_types=1);

namespace App\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Swagger UI `servers` 的跨层接线测试。
 *
 * 症状：文档页 Try-it-out 恒报 `Failed to fetch`，浏览器控制台提示
 * `URL scheme must be 'http' or 'https' for CORS request`，而同一个 URL 用 curl 返回正常
 * （服务端 CORS 头完全正确）。可复现该症状的写入方式有两种，本测试把两者一起钉住：
 *   1) 模板里写死 `"servers": [{"url": ""}]` —— 空串 server 让 swagger-ui 拼出没有 scheme 的 URL；
 *   2) 运行时把 servers 钉成 API_BASE_URL —— 页面换主机名（localhost / LAN IP / 域名）打开即跨源，
 *      nginx/k8s 的 CSP `connect-src 'self'` 同样会拦掉（`Access-Control-Allow-Origin` 救不了）。
 * 约定：模板不含 servers；运行时第一项固定为相对地址 `/`（swagger-ui 以 spec 来源解析，恒等于页面同源），
 * 配置了 API_BASE_URL 时再追加为可切换的第二项。
 */
class SwaggerServersWiringTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    private function read(string $relative): string
    {
        $content = file_get_contents(self::ROOT . '/' . $relative);
        $this->assertIsString($content, "{$relative} 必须存在");

        return $content;
    }

    public function testSpecTemplatesDoNotPinAnAbsoluteOrEmptyServer(): void
    {
        foreach (['templates/openapi.json', 'templates/openapi.en.json'] as $file) {
            $doc = json_decode($this->read($file), true);
            $this->assertIsArray($doc, "{$file} 必须是合法 JSON");
            
            
            $this->assertSame('3.0.3', $doc['openapi'] ?? null, "{$file} 应声明 openapi 版本");
            $this->assertArrayHasKey('paths', $doc, "{$file} 必须含 paths，否则文档页会空掉");
            $this->assertNotEmpty($doc['paths'], "{$file} 的 paths 不能为空");

            $this->assertArrayNotHasKey(
                'servers',
                $doc,
                "{$file} 不应静态声明 servers：由 MainController 按请求运行时注入，静态值必然对某些访问主机名是错的"
            );
        }
    }

    public function testControllerInjectsRelativeServerFirstThenConfiguredBase(): void
    {
        $src = $this->read('src/Controller/MainController.php');

        $this->assertStringContainsString(
            "'url'         => '/',",
            $src,
            "首个 server 必须是相对地址 '/'，否则换主机名打开文档页即跨源（Failed to fetch）"
        );
        $this->assertStringContainsString('$apiBaseUrl = $this->config->getApiBaseUrl();', $src);
        $this->assertStringContainsString(
            "\$spec['servers'][] = [",
            $src,
            'API_BASE_URL 只能作为追加项，不能顶替同源的默认 server'
        );

        $relativePos = strpos($src, "'url'         => '/',");
        $appendPos   = strpos($src, "\$spec['servers'][] = [");
        $this->assertIsInt($relativePos);
        $this->assertIsInt($appendPos);
        $this->assertLessThan(
            $appendPos,
            $relativePos,
            '顺序有意义：同源的相对 server 必须是默认项（Swagger UI 默认选中第一项）'
        );
    }

    public function testDocsPageLoadsSpecFromSameOrigin(): void
    {
        $tpl = $this->read('templates/swagger.html');

        $this->assertStringContainsString(
            "var specUrl = '/api/openapi.json?lang='",
            $tpl,
            '文档页必须用相对路径取 spec：相对路径 + 相对 server 才能保证 Try-it-out 与页面同源'
        );
        $this->assertStringNotContainsString('http://127.0.0.1', $tpl);
        $this->assertStringNotContainsString('http://localhost', $tpl);
    }

    public function testServerDescriptionsAreTranslatedInBothLocales(): void
    {
        foreach (['lang/zh_CN/messages.php', 'lang/en/messages.php'] as $file) {
            
            $messages = require self::ROOT . '/' . $file;

            foreach (['admin.current_env', 'admin.configured_api_base'] as $key) {
                $this->assertArrayHasKey($key, $messages, "{$file} 缺少 {$key}（界面会直接显示翻译键）");
                $this->assertNotSame('', trim((string) $messages[$key]), "{$file} 的 {$key} 不能为空");
                $this->assertNotSame($key, $messages[$key], "{$file} 的 {$key} 不能等于键名");
            }
        }
    }
}
