<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Config\AppConfig;
use App\Service\Database;
use App\Service\SchemaMigrator;
use App\Service\WritebackQueueRepository;
use PHPUnit\Framework\TestCase;

final class WritebackQueueRepositoryTest extends TestCase
{
    private function memoryPdo(): \PDO
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        return $pdo;
    }

    private function repo(\PDO $pdo): WritebackQueueRepository
    {
        // 只建队列表（不依赖完整 schema）
        $table = AppConfig::TABLE_WRITEBACK_QUEUE;
        $pdo->exec("CREATE TABLE IF NOT EXISTS {$table} (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            git_platform TEXT NOT NULL, repo TEXT NOT NULL, sha TEXT NOT NULL,
            state TEXT NOT NULL, context TEXT NOT NULL, description TEXT NOT NULL,
            target_url TEXT, project_path TEXT NOT NULL DEFAULT '',
            check_type TEXT NOT NULL DEFAULT '', tag TEXT NOT NULL DEFAULT '',
            pipeline_id TEXT NOT NULL DEFAULT '', pipeline_key TEXT NOT NULL DEFAULT '',
            status TEXT NOT NULL DEFAULT 'pending',
            attempts INTEGER NOT NULL DEFAULT 0, last_error TEXT,
            next_retry_at TEXT DEFAULT (datetime('now')),
            created_at TEXT DEFAULT (datetime('now')),
            updated_at TEXT DEFAULT (datetime('now'))
        )");
        return new WritebackQueueRepository($pdo);
    }

    private function sampleRow(): array
    {
        return [
            'git_platform' => 'gitlab', 'repo' => 'group/proj', 'sha' => 'abc1234',
            'state' => 'success', 'context' => 'security-scan', 'description' => 'No vulnerabilities',
            'target_url' => 'https://harbor.example.com', 'project_path' => 'group/proj',
            'check_type' => 'security-scan', 'tag' => 'v1.0.0',
        ];
    }

    public function testEnqueueAndClaimDue(): void
    {
        $pdo = $this->memoryPdo();
        $repo = $this->repo($pdo);
        $id = $repo->enqueue($this->sampleRow());
        $this->assertNotSame('', $id);

        $jobs = $repo->claimDue();
        $this->assertCount(1, $jobs);
        $this->assertSame('gitlab', $jobs[0]['git_platform']);
        $this->assertSame('group/proj', $jobs[0]['repo']);
        $this->assertSame('pending', $jobs[0]['status']);
        $this->assertSame('v1.0.0', $jobs[0]['tag']);
        $this->assertSame('security-scan', $jobs[0]['check_type']);
    }

    public function testMarkProcessingIfPendingIsAtomicAndIdempotent(): void
    {
        $pdo = $this->memoryPdo();
        $repo = $this->repo($pdo);
        $id = $repo->enqueue($this->sampleRow());

        $this->assertTrue($repo->markProcessingIfPending($id), '首次领取应成功');
        $this->assertFalse($repo->markProcessingIfPending($id), '已被领取的任务不能被再次领取（防并发重复消费）');
        $this->assertSame([], $repo->claimDue(), 'processing 任务不应再被取出');
        $repo->markDone($id);
        $this->assertSame(0, (int)$pdo->query("SELECT COUNT(*) FROM " . AppConfig::TABLE_WRITEBACK_QUEUE)->fetchColumn());
    }

    public function testMarkFailedRetriesWithBackoffThenDead(): void
    {
        $pdo = $this->memoryPdo();
        $repo = $this->repo($pdo);
        $id = $repo->enqueue($this->sampleRow());

        $repo->markFailed($id, 'timeout', 3);
        $row = $pdo->query("SELECT * FROM " . AppConfig::TABLE_WRITEBACK_QUEUE)->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('failed', $row['status']);
        $this->assertSame(1, (int)$row['attempts']);
        $this->assertSame('timeout', $row['last_error']);
        $this->assertNotSame('', $row['next_retry_at']);

        $repo->markFailed($id, 'timeout again', 3);
        $repo->markFailed($id, 'timeout final', 3);
        $row = $pdo->query("SELECT * FROM " . AppConfig::TABLE_WRITEBACK_QUEUE)->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('dead', $row['status'], '超过最大重试次数应标记 dead');
        $this->assertSame(3, (int)$row['attempts']);
    }

    public function testRequeueStaleProcessing(): void
    {
        $pdo = $this->memoryPdo();
        $repo = $this->repo($pdo);
        $id = $repo->enqueue($this->sampleRow());
        $repo->markProcessingIfPending($id);
        $pdo->exec("UPDATE " . AppConfig::TABLE_WRITEBACK_QUEUE . " SET updated_at = datetime('now', '-1 day') WHERE id = {$id}");

        $this->assertSame(1, $repo->requeueStale(600));
        $this->assertCount(1, $repo->claimDue(), '僵死任务应重新变为可消费');
    }

    public function testMarkFailedUsesUtcForNextRetry(): void
    {
        $pdo = $this->memoryPdo();
        $repo = $this->repo($pdo);
        $id = $repo->enqueue($this->sampleRow());

        // 强制 PHP 时区为非 UTC，模拟 k8s 的 TZ=Asia/Shanghai：
        // next_retry_at 必须仍与 sqlNow() 的 UTC 同口径，否则重试会延后 8 小时。
        $oldTz = date_default_timezone_get();
        date_default_timezone_set('Asia/Shanghai');
        try {
            $repo->markFailed($id, 'timeout', 5);
        } finally {
            date_default_timezone_set($oldTz);
        }
        $next = $pdo->query("SELECT next_retry_at FROM " . AppConfig::TABLE_WRITEBACK_QUEUE . " WHERE id = {$id}")->fetchColumn();
        // SQLite datetime('now') 是 UTC；用 gmdate 写入的 next_retry_at 应在 UTC 当前时间之后约 30s。
        $delta = strtotime((string)$next) - strtotime(gmdate('Y-m-d H:i:s'));
        $this->assertGreaterThanOrEqual(25, $delta);
        $this->assertLessThanOrEqual(35, $delta);
    }

    public function testStats(): void
    {
        $pdo = $this->memoryPdo();
        $repo = $this->repo($pdo);
        $this->assertSame(
            ['pending' => 0, 'processing' => 0, 'failed' => 0, 'dead' => 0],
            $repo->stats()
        );
        $repo->enqueue($this->sampleRow());
        $stats = $repo->stats();
        $this->assertSame(1, $stats['pending']);
    }

    public function testSearchFiltersAndPaginates(): void
    {
        $pdo = $this->memoryPdo();
        $repo = $this->repo($pdo);
        $repo->enqueue($this->sampleRow());
        $row2 = $this->sampleRow();
        $row2['sha'] = 'feedbee';
        $row2['git_platform'] = 'github';
        $repo->enqueue($row2);

        $all = $repo->search([], 1, 10);
        $this->assertSame(2, $all['total']);
        $this->assertCount(2, $all['jobs']);
        $this->assertSame(2, $all['stats']['pending']);

        $byStatus = $repo->search(['status' => 'pending'], 1, 10);
        $this->assertSame(2, $byStatus['total']);

        $byPlatform = $repo->search(['git_platform' => 'github'], 1, 10);
        $this->assertSame(1, $byPlatform['total']);
        $this->assertSame('github', $byPlatform['jobs'][0]['git_platform']);

        $byQ = $repo->search(['q' => 'feedbee'], 1, 10);
        $this->assertSame(1, $byQ['total']);

        $paged = $repo->search([], 1, 1);
        $this->assertCount(1, $paged['jobs']);
        $this->assertSame(2, $paged['total_pages']);
    }

    public function testRequeueDeadOnlyTouchesDeadJobs(): void
    {
        $pdo = $this->memoryPdo();
        $repo = $this->repo($pdo);
        $deadId = $repo->enqueue($this->sampleRow());
        $aliveId = $repo->enqueue($this->sampleRow());
        $repo->markFailed($deadId, 'final error', 1); // attempts=1 ≥ max=1 → dead
        $repo->markFailed($aliveId, 'first error', 5);

        $this->assertFalse($repo->requeueDead($aliveId), 'failed 任务不应被 dead 重投接口改动');
        $this->assertTrue($repo->requeueDead($deadId));
        $this->assertFalse($repo->requeueDead($deadId), '重复重投应返回 false');

        $jobs = $repo->claimDue();
        $ids = array_map(fn($j) => (string)$j['id'], $jobs);
        $this->assertContains($deadId, $ids, '重投后应立即可被 worker 领取');
        $row = $pdo->query("SELECT * FROM " . AppConfig::TABLE_WRITEBACK_QUEUE . " WHERE id = {$deadId}")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(0, (int)$row['attempts']);
        $this->assertNull($row['last_error']);
    }
}
