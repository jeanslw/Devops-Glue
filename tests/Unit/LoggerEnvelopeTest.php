<?php

declare(strict_types=1);

namespace App\Test\Unit;

use App\Service\Logger;
use PHPUnit\Framework\TestCase;

/**
 * Logger 信封字段（request_id/path/method/error_code/exception_class/location）单测。
 * 直接实例化 Logger 到临时目录，验证 JSON 行顶层字段与上下文归并。
 */
class LoggerEnvelopeTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/glue_log_' . uniqid('', true);
        mkdir($this->dir, 0777, true);
        Logger::setRequestContext('', '', '');
    }

    protected function tearDown(): void
    {
        Logger::setRequestContext('', '', '');
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function lastEntry(): array
    {
        $files = glob($this->dir . '/app-*.log') ?: [];
        $this->assertNotEmpty($files, '日志文件未生成');
        $lines = file($files[0], FILE_IGNORE_NEW_LINES);
        return json_decode((string) end($lines), true);
    }

    public function testRequestContextAutoAttached(): void
    {
        Logger::setRequestContext('req-1', '/api/build/x/report', 'POST');
        (new Logger($this->dir, 'debug', 0))->error('boom', ['foo' => 'bar']);

        $entry = $this->lastEntry();
        $this->assertSame('req-1', $entry['request_id']);
        $this->assertSame('/api/build/x/report', $entry['path']);
        $this->assertSame('POST', $entry['method']);
        $this->assertSame('bar', $entry['context']['foo']);
    }

    public function testEnvelopeFieldsPromotedToTopLevel(): void
    {
        Logger::setRequestContext('', '', '');
        (new Logger($this->dir, 'debug', 0))->error('boom', [
            'error_code'      => 'E_TEST',
            'exception_class' => 'RuntimeException',
            'location'        => 'x.php:10',
            'foo'             => 'bar',
        ]);

        $entry = $this->lastEntry();
        $this->assertSame('E_TEST', $entry['error_code']);
        $this->assertSame('RuntimeException', $entry['exception_class']);
        $this->assertSame('x.php:10', $entry['location']);
        $this->assertArrayNotHasKey('error_code', $entry['context'] ?? []);
        $this->assertSame('bar', $entry['context']['foo']);
    }

    public function testExplicitFieldOverridesRequestContext(): void
    {
        Logger::setRequestContext('req-ctx', '/ctx', 'GET');
        (new Logger($this->dir, 'debug', 0))->error('boom', ['request_id' => 'explicit']);

        $entry = $this->lastEntry();
        $this->assertSame('explicit', $entry['request_id']);
    }
}
