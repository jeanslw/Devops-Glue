<?php
declare(strict_types=1);

namespace App\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 业务一致性回归锁定：
 * - Build/Tag 路径统一 canonical resolveMapRow，不再自行遍历 allMaps()
 * - worker 使用严格审计写入，失败时保留队列任务
 * - Registry 未配置/未验证时不写权威 artifact
 */
final class BusinessConsistencyRegressionTest extends TestCase
{
    private static function source(string $rel): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $rel);
    }

    public function testBuildTagControllersUseCanonicalResolver(): void
    {
        $query = self::source('src/Controller/Build/PipelineQueryController.php');
        $tag = self::source('src/Controller/Build/PipelineTagController.php');

        $this->assertStringContainsString('resolveMapRow($path)', $query);
        $this->assertStringContainsString('resolveMapRow($path)', $tag);
        $this->assertStringNotContainsString('foreach ($this->mapping->allMaps()', $query);
        $this->assertStringNotContainsString('foreach ($this->mapping->allMaps()', $tag);
    }

    public function testWorkerUsesStrictAuditWriteAndKeepsQueueOnFailure(): void
    {
        $src = self::source('cli/writeback-worker.php');
        $this->assertStringContainsString('recordStrict(', $src);
        $this->assertStringContainsString('$writebackStatus = \'failed\'', $src);
        $this->assertStringContainsString('markFailed(', $src);
    }

    public function testRegistrySyncNeverWritesArtifactWithoutVerification(): void
    {
        $src = self::source('src/Service/RegistrySyncService.php');
        $this->assertStringContainsString('$this->registry !== null && $tagVerified', $src);
        $this->assertStringNotContainsString('$this->registry === null || $tagVerified', $src);
    }
}
