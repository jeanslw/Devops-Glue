<?php

namespace App\Service;

use App\Config\AppConfig;

/**
 * ci_operation_logs 仓储：后台操作审计日志（append-only，只增不删）。
 *
 * 设计原则：
 *  - 写路径轻量且绝不抛异常：审计日志失败只记应用日志，绝不影响主操作。
 *  - 只提供 append + 只读分页，不提供 update/delete（保证审计记录不可被界面篡改）。
 *  - 与业务事务解耦：由调用方在操作成功/失败后调用，记录事实快照。
 */
class OperationLogRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * 写一条操作日志。任何失败静默吞掉（审计日志绝不影响主流程）。
     *
     * @param string $action 操作类型（login / create_user / delete_role / ...）
     * @param string $target 操作对象（用户名 / 角色名 / 映射名等）
     * @param array  $detail 关键上下文（数组 → JSON 存 detail 列）
     * @param string $result success / failure
     * @param string $operatorType 操作人类型（admin / api_token）
     */
    public function record(string $username, string $action, string $target = '', array $detail = [], string $ip = '', string $result = 'success', string $operatorType = 'admin'): void
    {
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO " . AppConfig::TABLE_OPERATION_LOGS
                . " (username, action, target, detail, ip, operator_type, result, created_at)"
                . " VALUES (?, ?, ?, ?, ?, ?, ?, " . Database::sqlNow() . ")"
            );
            $stmt->execute([
                $username,
                $action,
                $target,
                $detail === [] ? null : json_encode($detail, JSON_UNESCAPED_UNICODE),
                $ip,
                $operatorType,
                $result,
            ]);
        } catch (\Throwable $e) {
            \App\Helper\Log::error('[操作日志] 写入失败', ['action' => $action, 'error' => $e->getMessage()]);
        }
    }

    /**
     * 分页查询，支持筛选：username（模糊）、action、result、date_from/date_to（含边界）。
     */
    public function list(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $where = [];
        $bind  = [];
        if (!empty($filters['username'])) {
            $where[] = 'username LIKE ?';
            $bind[]  = '%' . $filters['username'] . '%';
        }
        if (!empty($filters['action'])) {
            $where[] = 'action = ?';
            $bind[]  = $filters['action'];
        }
        if (!empty($filters['result'])) {
            $where[] = 'result = ?';
            $bind[]  = $filters['result'];
        }
        if (!empty($filters['operator_type'])) {
            $where[] = 'operator_type = ?';
            $bind[]  = $filters['operator_type'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'created_at >= ?';
            $bind[]  = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'created_at <= ?';
            $bind[]  = $filters['date_to'];
        }
        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $countStmt = $this->pdo->prepare("SELECT count(*) FROM " . AppConfig::TABLE_OPERATION_LOGS . " {$whereClause}");
        $countStmt->execute($bind);
        $total = (int)$countStmt->fetchColumn();

        $totalPages = max(1, (int)ceil($total / $perPage));
        $page = min(max(1, $page), $totalPages);
        $offset = ($page - 1) * $perPage;

        $listStmt = $this->pdo->prepare(
            "SELECT id, username, action, target, detail, ip, operator_type, result, created_at"
            . " FROM " . AppConfig::TABLE_OPERATION_LOGS
            . " {$whereClause}"
            . " ORDER BY id DESC LIMIT {$perPage} OFFSET {$offset}"
        );
        $listStmt->execute($bind);
        $items = $listStmt->fetchAll();

        // detail JSON → 数组，便于前端直接展示
        foreach ($items as &$item) {
            if ($item['detail'] !== null && $item['detail'] !== '') {
                $decoded = json_decode($item['detail'], true);
                $item['detail'] = is_array($decoded) ? $decoded : $item['detail'];
            } else {
                $item['detail'] = null;
            }
        }
        unset($item);

        return ['total' => $total, 'page' => $page, 'per_page' => $perPage, 'total_pages' => $totalPages, 'items' => $items];
    }
}
