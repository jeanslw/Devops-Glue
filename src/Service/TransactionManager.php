<?php

declare(strict_types=1);

namespace App\Service;

/**
 * 跨多个仓储/服务共享同一 PDO 连接的事务边界，供控制器原子提交多个写操作。
 */
final class TransactionManager
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function begin(): void
    {
        $this->pdo->beginTransaction();
    }

    public function commit(): void
    {
        $this->pdo->commit();
    }

    public function rollBack(): void
    {
        $this->pdo->rollBack();
    }

    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }
}
