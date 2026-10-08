<?php

declare(strict_types=1);

namespace App\Test\Unit;

use App\Service\Logger;
use PHPUnit\Framework\TestCase;

/**
 * 应用层日志轮转回归测试：Logger 写 app-*.log 时按保留天数清理过期文件。
 * 裸机部署没有 Docker 日志驱动 / logrotate，全靠 Logger 自清理，不能退化回「无限累积」。
 */
class LoggerRotationTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/logger-rot-' . uniqid();
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function testPurgeOldLogsDeletesOnlyExpiredFiles(): void
    {
        $old    = $this->dir . '/app-2020-01-01.log';
        $recent = $this->dir . '/app-' . date('Y-m-d') . '.log';
        file_put_contents($old, "{\"level\":\"INFO\"}\n");
        file_put_contents($recent, "{\"level\":\"INFO\"}\n");
        touch($old, time() - 40 * 86400); // 40 天前
        touch($recent, time());

        (new Logger($this->dir, 'info', 30))->purgeOldLogs();

        $this->assertFileDoesNotExist($old, '超过保留天数的旧日志应被删除');
        $this->assertFileExists($recent, '保留期内的日志不应被删');
    }

    public function testRetainDaysZeroDisablesCleanup(): void
    {
        $old = $this->dir . '/app-2020-01-01.log';
        file_put_contents($old, 'x');
        touch($old, time() - 40 * 86400);

        (new Logger($this->dir, 'info', 0))->purgeOldLogs();

        $this->assertFileExists($old, 'retainDays=0 时应关闭清理，旧文件保留');
    }

    public function testPurgeOnlyTouchesAppLogPattern(): void
    {
        $other = $this->dir . '/php-error.log';
        file_put_contents($other, 'x');
        touch($other, time() - 40 * 86400);

        (new Logger($this->dir, 'info', 30))->purgeOldLogs();

        $this->assertFileExists($other, '非 app-*.log 文件不应被清理');
    }
}
