<?php

declare(strict_types=1);

namespace App\Test\Unit;

use App\Controller\BaseController;
use App\Service\I18nService;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * 加解密异常的 API 错误文案必须跟随界面语言。
 *
 * 各 Controller 的 catch 统一用 `$this->__('build.query_failed') . ': ' . $e->getMessage()`
 * 拼接，而 Encryptor 抛的是硬编码中文；翻译收口在 BaseController::jsonError()。
 *
 * 关键：界面语言存在前端 localStorage，通过 X-DG-Lang 请求头传给后端，
 * 由 initAuthFromRequest() 记录；否则 __() 会用默认 zh_CN，出现「界面英文、报错中文」。
 */
class EncryptorErrorMessageTest extends TestCase
{
    private const RAW = 'Query failed: 字段解密失败（密钥不匹配或密文被篡改）';

    /**
     * 用请求头（而非构造参数）驱动语言，复现真实链路：
     * header → detectLocale() → initAuthFromRequest() → __()/jsonError()。
     */
    private function messageFor(?string $uiLang): string
    {
        $i18n = new I18nService(__DIR__ . '/../../lang'); // 默认 zh_CN
        $controller = new class ($i18n) extends BaseController {
            public function init(Request $request): void
            {
                $this->initAuthFromRequest($request);
            }

            public function error(Response $response, string $message): Response
            {
                return $this->jsonError($response, $message, 500);
            }
        };

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/admin/x');
        if ($uiLang !== null) {
            $request = $request->withHeader('X-DG-Lang', $uiLang);
        }
        $controller->init($request);

        $response = $controller->error((new ResponseFactory())->createResponse(), self::RAW);
        $body = json_decode((string) $response->getBody(), true);

        return (string) ($body['message'] ?? '');
    }

    public function testEnglishUiHeaderTranslatesDecryptError(): void
    {
        $message = $this->messageFor('en');

        $this->assertStringStartsWith('Query failed: ', $message, '已翻译的前缀要保留');
        $this->assertStringContainsString('Field decryption failed', $message);
        $this->assertStringNotContainsString('字段解密失败', $message, '不得残留中文');
        $this->assertStringNotContainsString('密钥不匹配或密文被篡改', $message, '括号尾巴也要一起换掉');
    }

    public function testZhUiHeaderKeepsChinese(): void
    {
        $message = $this->messageFor('zh_CN');

        $this->assertStringContainsString('字段解密失败', $message);
        $this->assertStringContainsString('DATA_ENC_KEY', $message);
        $this->assertStringNotContainsString('Field decryption failed', $message);
    }

    /** 无语言头时回退默认语言（老客户端 / 未带头的请求），不得报错。 */
    public function testMissingHeaderFallsBackToDefault(): void
    {
        $message = $this->messageFor(null);

        $this->assertStringContainsString('字段解密失败', $message);
        $this->assertStringNotContainsString('密钥不匹配或密文被篡改', $message, '仍要替换掉硬编码尾巴');
    }

    /** Accept-Language 与界面语言冲突时，界面语言优先。 */
    public function testUiHeaderBeatsAcceptLanguage(): void
    {
        $i18n = new I18nService(__DIR__ . '/../../lang');
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/admin/x')
            ->withHeader('Accept-Language', 'zh-CN,zh;q=0.9')
            ->withHeader('X-DG-Lang', 'en');

        $this->assertSame('en', $i18n->detectLocale($request));
    }

    /** 普通异常文案不含加解密特征，不应被误改。 */
    public function testUnrelatedMessageIsLeftAlone(): void
    {
        $i18n = new I18nService(__DIR__ . '/../../lang');
        $controller = new class ($i18n) extends BaseController {
            public function error(Response $response, string $message): Response
            {
                return $this->jsonError($response, $message, 500);
            }
        };
        $response = $controller->error(
            (new ResponseFactory())->createResponse(),
            'Query failed: connection refused'
        );
        $body = json_decode((string) $response->getBody(), true);

        $this->assertSame('Query failed: connection refused', $body['message']);
    }
}
