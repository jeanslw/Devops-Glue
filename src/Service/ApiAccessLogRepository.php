<?php

namespace App\Service;

use App\Config\AppConfig;
use App\Helper\Log;

/**
 * API token 调用日志仓储（ci_api_access_logs，append-only）。
 *
 * 安全边界：
 *  - 只存 token 展示名（api_tokens.name），绝不存 token 原文/hash；
 *  - 不存请求 body / query / Authorization 头；
 *  - route 存路由模板（如 /api/build/{path}/pipelines/{id}），避免高基数与敏感路径直出。
 *
 * 写入必须静默降级：record() 内任何异常都不得影响主请求。
 */
class ApiAccessLogRepository
{
    private const DEFAULT_RANGE_DAYS = 30; // 未指定日期时默认只查近 30 天

    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * 写入一条调用日志。失败仅 Log::error，不抛出。
     *
     * 期望键（均可选，缺失按 null/0 处理，仓储做长度截断）：
     *   username/token_name/scopes/method/route/result/error_reason/ip（string|null）、
     *   status_code/duration_ms（int）。
     *
     * @param array<string, mixed> $entry
     */
    public function record(array $entry): void
    {
        try {
            $sql = 'INSERT INTO ' . AppConfig::TABLE_API_ACCESS_LOGS
                . ' (username, token_name, scopes, method, route, status_code, result, error_reason, ip, duration_ms, created_at)'
                . ' VALUES (?,?,?,?,?,?,?,?,?,?, ' . Database::sqlNow() . ')';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                $this->trimOrNull($entry['username'] ?? null, 255),
                $this->trimOrNull($entry['token_name'] ?? null, 255),
                $this->trimOrNull($entry['scopes'] ?? null, 500),
                $this->trimOrNull($entry['method'] ?? null, 10),
                $this->trimOrNull($entry['route'] ?? null, 500),
                (int)($entry['status_code'] ?? 0),
                $this->trimOrNull($entry['result'] ?? null, 20),
                $this->trimOrNull($entry['error_reason'] ?? null, 500),
                $this->trimOrNull($entry['ip'] ?? null, 45),
                (int)($entry['duration_ms'] ?? 0),
            ]);
        } catch (\Throwable $e) {
            Log::error('[API调用日志] 写入失败', ['error' => $e->getMessage()]);
        }
    }

    private function trimOrNull(mixed $value, int $maxLen): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }
        return mb_substr($value, 0, $maxLen);
    }

    /**
     * 分页查询：result / method / token_name（模糊）/ route（模糊）/ date_from / date_to（含边界）。
     * 未传日期默认近 30 天。
     *
     * @param array<string,mixed> $filters
     * @return array{total:int,page:int,per_page:int,total_pages:int,items:list<array<string,mixed>>}
     */
    public function list(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $perPage = min(100, max(1, $perPage));

        $where = [];
        $bind  = [];
        if (!empty($filters['result'])) {
            $where[] = 'result = ?';
            $bind[]  = $filters['result'];
        }
        if (!empty($filters['method'])) {
            $where[] = 'method = ?';
            $bind[]  = strtoupper($filters['method']);
        }
        if (!empty($filters['token_name'])) {
            $where[] = 'token_name LIKE ?';
            $bind[]  = '%' . $filters['token_name'] . '%';
        }
        if (!empty($filters['route'])) {
            $where[] = 'route LIKE ?';
            $bind[]  = '%' . $filters['route'] . '%';
        }
        $hasDateFrom = !empty($filters['date_from']);
        $hasDateTo   = !empty($filters['date_to']);
        if ($hasDateFrom) {
            $where[] = 'created_at >= ?';
            $bind[]  = $filters['date_from'] . ' 00:00:00';
        }
        if ($hasDateTo) {
            $where[] = 'created_at <= ?';
            $bind[]  = $filters['date_to'] . ' 23:59:59';
        }
        if (!$hasDateFrom && !$hasDateTo) {
            $where[] = 'created_at >= ?';
            $bind[]  = date('Y-m-d H:i:s', time() - self::DEFAULT_RANGE_DAYS * 86400);
        }
        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        try {
            $countStmt = $this->pdo->prepare(
                'SELECT count(*) FROM ' . AppConfig::TABLE_API_ACCESS_LOGS . " {$whereClause}"
            );
            $countStmt->execute($bind);
            $total = (int)$countStmt->fetchColumn();

            $totalPages = max(1, (int)ceil($total / $perPage));
            $page = min(max(1, $page), $totalPages);
            $offset = ($page - 1) * $perPage;

            $listStmt = $this->pdo->prepare(
                'SELECT id, username, token_name, scopes, method, route, status_code, result, error_reason, ip, duration_ms, created_at'
                . ' FROM ' . AppConfig::TABLE_API_ACCESS_LOGS
                . " {$whereClause}"
                . " ORDER BY id DESC LIMIT {$perPage} OFFSET {$offset}"
            );
            $listStmt->execute($bind);
            $items = $listStmt->fetchAll();

            return [
                'total'       => $total,
                'page'        => $page,
                'per_page'    => $perPage,
                'total_pages' => $totalPages,
                'items'       => $items,
            ];
        } catch (\Throwable $e) {
            Log::error('[API调用日志] 查询失败', ['error' => $e->getMessage()]);
            return ['total' => 0, 'page' => $page, 'per_page' => $perPage, 'total_pages' => 1, 'items' => []];
        }
    }

    /**
     * 按写入级别判定该结果是否应落库（纯函数，便于单测）。
     * 严重度：success(0) < denied(1) < failure(2，未知结果也按 2 兜底——宁可多记不可漏记)。
     * 级别阈值：all=0 / warning=1 / error=2 / off=99。
     */
    public static function shouldRecord(string $result, string $level): bool
    {
        $rank = match ($result) {
            'success' => 0,
            'denied'  => 1,
            default   => 2,
        };
        $threshold = match ($level) {
            'all'     => 0,
            'warning' => 1,
            'error'   => 2,
            default   => 99, // off 及任何非法值：不写（fail-closed 由调用方保证 level 已白名单化）
        };
        return $rank >= $threshold;
    }

    /**
     * 清理早于保留期的日志，返回删除行数。供 cli/cleanup-api-access-logs.php 调度。
     */
    public function purge(int $retainDays): int
    {
        $retainDays = max(1, $retainDays);
        try {
            $stmt = $this->pdo->prepare(
                'DELETE FROM ' . AppConfig::TABLE_API_ACCESS_LOGS . ' WHERE created_at < ?'
            );
            $stmt->execute([date('Y-m-d H:i:s', time() - $retainDays * 86400)]);
            return $stmt->rowCount();
        } catch (\Throwable $e) {
            Log::error('[API调用日志] 清理失败', ['error' => $e->getMessage()]);
            return 0;
        }
    }
}
