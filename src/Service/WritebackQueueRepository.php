<?php

declare(strict_types=1);

namespace App\Service;

use App\Config\AppConfig;

/**
 * Git commit status 异步回写队列。
 *
 * CI 回调端点只入队不调 Git 平台，避免同步 HTTP 受调用方超时约束；
 * worker 消费队列时再调 Git Provider。Git 平台按 (sha, context) 覆盖写，
 * 因此失败重投幂等（最后写入者胜）。
 */
final class WritebackQueueRepository
{
    private \PDO $pdo;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * 入队一条回写任务。
     *
     * @param array<string,string> $row 至少含 git_platform/repo/sha/state/context/description
     * @return string 任务 id
     */
    public function enqueue(array $row): string
    {
        $table = AppConfig::TABLE_WRITEBACK_QUEUE;
        $now = Database::sqlNow();
        $stmt = $this->pdo->prepare(
            "INSERT INTO {$table}
                (git_platform, repo, sha, state, context, description, target_url,
                 project_path, check_type, tag, pipeline_id, status, attempts,
                 next_retry_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 0, {$now}, {$now}, {$now})"
        );
        $stmt->execute([
            (string)($row['git_platform'] ?? ''),
            (string)($row['repo'] ?? ''),
            (string)($row['sha'] ?? ''),
            (string)($row['state'] ?? ''),
            (string)($row['context'] ?? ''),
            (string)($row['description'] ?? ''),
            (string)($row['target_url'] ?? ''),
            (string)($row['project_path'] ?? ''),
            (string)($row['check_type'] ?? ''),
            (string)($row['tag'] ?? ''),
            (string)($row['pipeline_id'] ?? ''),
        ]);
        return (string)$this->pdo->lastInsertId();
    }

    /**
     * 取一批待处理任务（含 failed 且已到重试时间的）。
     *
     * @return list<array<string,mixed>>
     */
    public function claimDue(int $limit = 20): array
    {
        $table = AppConfig::TABLE_WRITEBACK_QUEUE;
        $now = Database::sqlNow();
        $stmt = $this->pdo->prepare(
            "SELECT * FROM {$table}
             WHERE status IN ('pending','failed') AND next_retry_at <= {$now}
             ORDER BY id ASC LIMIT " . max(1, min(100, $limit))
        );
        $stmt->execute();
        return array_values($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * 原子领取：仅当任务仍为 pending/failed 且未到下次重试时间之前才转 processing。
     * 返回 false 表示已被其他 worker 领取/处理，本轮跳过（防止 SELECT 与 UPDATE 分离的竞态）。
     */
    public function markProcessingIfPending(string $id): bool
    {
        $table = AppConfig::TABLE_WRITEBACK_QUEUE;
        $now = Database::sqlNow();
        $stmt = $this->pdo->prepare(
            "UPDATE {$table} SET status = 'processing', updated_at = {$now}
             WHERE id = ? AND status IN ('pending','failed') AND next_retry_at <= {$now}"
        );
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    /** 处理成功：删除任务（幂等完成，不占空间）。 */
    public function markDone(string $id): void
    {
        $table = AppConfig::TABLE_WRITEBACK_QUEUE;
        $stmt = $this->pdo->prepare("DELETE FROM {$table} WHERE id = ?");
        $stmt->execute([$id]);
    }

    /**
     * 处理失败：attempts+1，超过 maxAttempts 标记 dead，否则按退避设下次重试时间。
     */
    public function markFailed(string $id, string $error, int $maxAttempts = 5): void
    {
        $table = AppConfig::TABLE_WRITEBACK_QUEUE;
        $row = $this->find($id);
        if ($row === null) {
            return;
        }
        $attempts = (int)($row['attempts'] ?? 0) + 1;
        $dead = $attempts >= $maxAttempts;
        $delay = min(3600, 30 * (2 ** min($attempts - 1, 7))); // 30s,60s,...,最大1h
        // 必须用 gmdate：Database::sqlNow() 是 UTC（MySQL UTC_TIMESTAMP / SQLite datetime('now')），
        // 用 date() 会跟随 PHP 时区（k8s 设 Asia/Shanghai），导致重试延后 8 小时。
        $next = gmdate('Y-m-d H:i:s', time() + $delay);
        $status = $dead ? 'dead' : 'failed';
        $stmt = $this->pdo->prepare(
            "UPDATE {$table} SET status = ?, attempts = ?, last_error = ?, next_retry_at = ?, updated_at = " . Database::sqlNow() . " WHERE id = ?"
        );
        $stmt->execute([$status, $attempts, mb_substr($error, 0, 500), $next, $id]);
    }

    /** 把卡在 processing 的任务重置为 pending（worker 崩溃恢复）。 */
    public function requeueStale(int $olderThanSeconds = 600): int
    {
        $table = AppConfig::TABLE_WRITEBACK_QUEUE;
        // 同样用 gmdate 与 sqlNow() 的 UTC 口径一致，否则 TZ=Asia/Shanghai 时
        // threshold 偏未来 8h，刚 markProcessing 的任务也会被误判为僵死。
        $threshold = gmdate('Y-m-d H:i:s', time() - max(60, $olderThanSeconds));
        $stmt = $this->pdo->prepare(
            "UPDATE {$table} SET status = 'pending', next_retry_at = " . Database::sqlNow() . "
             WHERE status = 'processing' AND updated_at < ?"
        );
        $stmt->execute([$threshold]);
        return $stmt->rowCount();
    }

    /** 队列概览（供后台/指标使用）。
     * @return array<string, int>
     */
    public function stats(): array
    {
        $table = AppConfig::TABLE_WRITEBACK_QUEUE;
        $stmt = $this->pdo->query("SELECT status, COUNT(*) n FROM {$table} GROUP BY status");
        $out = ['pending' => 0, 'processing' => 0, 'failed' => 0, 'dead' => 0];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[(string)$r['status']] = (int)$r['n'];
        }
        return $out;
    }

    /** @return array<string, mixed>|null */
    private function find(string $id): ?array
    {
        $table = AppConfig::TABLE_WRITEBACK_QUEUE;
        $stmt = $this->pdo->prepare("SELECT * FROM {$table} WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }
}
