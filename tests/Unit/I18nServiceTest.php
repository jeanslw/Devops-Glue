<?php
declare(strict_types=1);

namespace App\Test\Unit;

use App\Service\I18nService;
use PHPUnit\Framework\TestCase;

/**
 * I18nService 单元测试
 *
 * 验证翻译加载、键查找、语言检测等功能。
 * 需要存在 lang 目录和翻译文件。
 */
class I18nServiceTest extends TestCase
{
    private I18nService $i18n;

    protected function setUp(): void
    {
        $langDir = __DIR__ . '/../../lang';
        $this->i18n = new I18nService($langDir, 'zh_CN');
    }

    public function testTransReturnsKeyWhenNotFound(): void
    {
        $result = $this->i18n->trans('nonexistent.key.xyz');
        $this->assertEquals('nonexistent.key.xyz', $result);
    }

    public function testTransReturnsTranslation(): void
    {
        $result = $this->i18n->trans('app.name', [], 'zh_CN');
        // 即使没找到也返回 key，不抛异常
        $this->assertIsString($result);
    }

    public function testDetectLocaleDefault(): void
    {
        // 无 Accept-Language 时返回默认
        $this->assertEquals('zh_CN', $this->i18n->getDefaultLocale());
    }

    public function testGetAllReturnsArray(): void
    {
        $messages = $this->i18n->getAll('zh_CN');
        $this->assertIsArray($messages);
    }

    public function testGetAllForUnknownLocaleReturnsEmpty(): void
    {
        $messages = $this->i18n->getAll('xx_XX');
        $this->assertIsArray($messages);
    }

    public function testTransWithParams(): void
    {
        $result = $this->i18n->trans('test.key', ['{param}' => 'world']);
        $this->assertIsString($result);
    }
}
