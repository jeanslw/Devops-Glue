<?php
declare(strict_types=1);

namespace App\Test\Unit;

use App\Config\AppConfig;
use App\Service\Database;
use App\Service\PipelineArtifactService;
use App\Service\PipelineIdentity;
use PHPUnit\Framework\TestCase;

/**
 * 用两个独立 PDO 连接模拟并发写入同一 Pipeline Artifact。
 *
 * MySQL 双连接的集成回归应在 CI 另配数据库时做；这里用 SQLite 临时文件库验证
 * 「两个写入者都拿到旧快照，但条件 UPDATE 在语句执行时基于当前行裁决」这一核心不变量。
 */
final class PipelineArtifactConcurrencyTest extends TestCase
{
    private string $dbFile;
    private ?\PDO $pdoA = null;
    private ?\PDO $pdoB = null;

    protected function setUp(): void
    {
        parent::setUp();
        Database::init(['driver' => 'sqlite', 'auto_migrate' => false]);
        $this->dbFile = tempnam(sys_get_temp_dir(), 'dg_artifact_');
        $this->assertNotFalse($this->dbFile);

        $schema = 'CREATE TABLE ' . AppConfig::TABLE_PIPELINE_ARTIFACTS . ' (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            provider TEXT NOT NULL,
            project_id TEXT NOT NULL,
            pipeline_iid INTEGER NOT NULL,
            project_key TEXT NOT NULL,
            repository TEXT NOT NULL,
            tag TEXT NOT NULL,
            digest TEXT DEFAULT NULL,
            status TEXT DEFAULT "",
            source_updated_at TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (provider, project_id, pipeline_iid)
        )';
        $this->pdoA = new \PDO('sqlite:' . $this->dbFile);
        $this->pdoA->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdoA->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $this->pdoA->exec($schema);

        $this->pdoB = new \PDO('sqlite:' . $this->dbFile);
        $this->pdoB->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdoB->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
    }

    protected function tearDown(): void
    {
        Database::reset();
        $this->pdoA = null;
        $this->pdoB = null;
        gc_collect_cycles();
        if (is_file($this->dbFile)) {
            @unlink($this->dbFile);
        }
        parent::tearDown();
    }

    public function testOlderEventCannotOverwriteNewerOneWithTwoConnections(): void
    {
        $id = new PipelineIdentity('jenkins', 'race-job', 42);
        $a = new PipelineArtifactService($this->pdoA);
        $b = new PipelineArtifactService($this->pdoB);

        // 初始事件（10:00）
        $a->record($id, 'race-job', 'v1000', 'repo/app', 'success', '2026-11-03 10:00:00');

        // 两个连接都读取到同一旧快照
        $this->assertSame('v1000', $a->find($id)['tag']);
        $this->assertSame('v1000', $b->find($id)['tag']);

        // A 写入更新事件（10:10）
        $newer = $a->record($id, 'race-job', 'v1010', 'repo/app', 'success', '2026-11-03 10:10:00');
        $this->assertTrue($newer['written']);

        // B 基于旧快照写入更旧事件（10:05）：SQL 守卫必须基于当前行拒绝
        $older = $b->record($id, 'race-job', 'v1005', 'repo/app', 'failed', '2026-11-03 10:05:00');
        $this->assertFalse($older['written']);
        $this->assertTrue($older['ignored']);
        $this->assertSame('ignored', $older['outcome']);

        $final = $a->find($id);
        $this->assertSame('v1010', $final['tag']);
        $this->assertSame('success', $final['status']);
        $this->assertSame('2026-11-03 10:10:00', $final['source_updated_at']);
    }
}
