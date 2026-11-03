<?php

declare(strict_types=1);

namespace App\Service;

/**
 * 由原 Database 拆分出的「DistributedLock」职责（门面转调，行为不变）。
 */
class DistributedLock
{
    private \PDO $pdo;
    private string $driver;

    public function __construct(\PDO $pdo, string $driver)
    {
        $this->pdo = $pdo;
        $this->driver = $driver;
    }

    public function ensureCacheTable(): void
    {
        $pdo = $this->pdo;
        try {
            $pdo->query('SELECT 1 FROM cache LIMIT 1');
            return;
        } catch (\Throwable $e) {
        }
        $isMySQL = $this->driver === 'mysql';
        $ddl = $isMySQL
        ? 'CREATE TABLE IF NOT EXISTS ' . \App\Config\AppConfig::TABLE_CACHE . ' (cache_key VARCHAR(255) PRIMARY KEY, `value` MEDIUMTEXT NOT NULL, expires_at INTEGER) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        : 'CREATE TABLE IF NOT EXISTS ' . \App\Config\AppConfig::TABLE_CACHE . ' (cache_key TEXT PRIMARY KEY, value TEXT NOT NULL, expires_at INTEGER)';
        try {
            Database::buildTable($pdo, \App\Config\AppConfig::TABLE_CACHE, $ddl);
        } catch (\Throwable $e) {
        }
    }

    public function runExclusively(string $waitKey, \Closure $critical): void
    {
        $token = $this->tryAcquireLock(Database::MIGRATION_LOCK_KEY, Database::MIGRATION_LOCK_TTL);
        if ($token !== null) {
            try {
                $critical();
            } finally {
                $this->releaseLock(Database::MIGRATION_LOCK_KEY, $token);
            }
            return;
        }
        if (!$this->lockTableAvailable()) {
            $critical();
            return;
        }
        $timeout  = Database::$migrationWaitOverride ?? Database::MIGRATION_WAIT_TIMEOUT_SECONDS;
        $interval = Database::$migrationPollOverride ?? Database::MIGRATION_POLL_INTERVAL_SECONDS;
        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            usleep((int) round($interval * 1000000));
            if (Database::isVersionCurrent(Database::recordedVersion($this->pdo, $waitKey))) {
                return;
            }
            $token = $this->tryAcquireLock(Database::MIGRATION_LOCK_KEY, Database::MIGRATION_LOCK_TTL);
            if ($token !== null) {
                try {
                    $critical();
                } finally {
                    $this->releaseLock(Database::MIGRATION_LOCK_KEY, $token);
                }
                return;
            }
        }
        // 等待超时仍未取得锁：不再无条件执行 critical，避免并发迁移竞态（fail-closed）
        $token = $this->tryAcquireLock(Database::MIGRATION_LOCK_KEY, Database::MIGRATION_LOCK_TTL);
        if ($token !== null) {
            try {
                $critical();
            } finally {
                $this->releaseLock(Database::MIGRATION_LOCK_KEY, $token);
            }
            return;
        }
        throw new \RuntimeException('数据库迁移锁等待超时，另一进程可能正在迁移，已中止以避免并发竞态');
    }

    public function lockTableAvailable(): bool
    {
        try {
            $this->pdo->query('SELECT 1 FROM ' . \App\Config\AppConfig::TABLE_CACHE . ' LIMIT 1');
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function tryAcquireLock(string $name, int $ttlSeconds = 600): ?string
    {
        try {
            $pdo   = $this->pdo;
            $key   = 'lock:' . $name;
            $now   = time();
            $token = gethostname() . ':' . getmypid() . ':' . bin2hex(random_bytes(4));
            $pdo->prepare("DELETE FROM " . \App\Config\AppConfig::TABLE_CACHE . " WHERE cache_key = ? AND expires_at <= ?")
                ->execute([$key, $now]);
            $pdo->prepare("INSERT INTO " . \App\Config\AppConfig::TABLE_CACHE . " (cache_key, value, expires_at) VALUES (?, ?, ?)")
                ->execute([$key, $token, $now + $ttlSeconds]);
            return $token;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function releaseLock(string $name, string $token): void
    {
        try {
            $pdo = $this->pdo;
            $pdo->prepare("DELETE FROM " . \App\Config\AppConfig::TABLE_CACHE . " WHERE cache_key = ? AND value = ?")
                ->execute(['lock:' . $name, $token]);
        } catch (\Throwable $e) {
        }
    }
}
