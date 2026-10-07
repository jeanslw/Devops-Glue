<?php

namespace App\Service;

use App\Config\AppConfig;

/**
 * CD 部署记录只读审计仓储（经 v_glue_deploy_logs 契约视图读取，不直读 cd_deploy_logs）。
 *
 * 设计原则：
 *  - 只读：仅提供分页列表，不提供写入/删除。
 *  - 审计字段：只查契约视图内的审计列，不含 output/stage_times 等大字段。
 *  - 优雅降级：视图缺失或查询异常不抛 500，返回空集 + available=false，由前端展示占位。
 */
class DeployLogRepository
{
    private const DEFAULT_RANGE_DAYS = 30; // 未指定日期时默认只查近 30 天

    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * 契约视图是否可用（CD 是否已建 v_glue_deploy_logs）。
     */
    public function isAvailable(): bool
    {
        try {
            $this->pdo->query('SELECT 1 FROM ' . AppConfig::TABLE_CD_DEPLOY_LOG_VIEW . ' LIMIT 1');
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 分页查询，支持筛选：project（模糊）、status、deploy_type、date_from/date_to（含边界）。
     * 未传日期时默认近 30 天，避免全表扫描。
     *
     * @param array<string,mixed> $filters
     * @return array{available:bool,total:int,page:int,per_page:int,total_pages:int,items:list<array<string,mixed>>}
     */
    public function list(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        if (!$this->isAvailable()) {
            return ['available' => false, 'total' => 0, 'page' => $page, 'per_page' => $perPage, 'total_pages' => 1, 'items' => []];
        }

        try {
            $where = [];
            $bind  = [];
            if (!empty($filters['project'])) {
                $where[] = 'project LIKE ?';
                $bind[]  = '%' . $filters['project'] . '%';
            }
            if (!empty($filters['status'])) {
                $where[] = 'status = ?';
                $bind[]  = $filters['status'];
            }
            if (!empty($filters['deploy_type'])) {
                $where[] = 'deploy_type = ?';
                $bind[]  = $filters['deploy_type'];
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
                // 默认近 30 天（含），防日志表膨胀拖慢查询
                $where[] = 'created_at >= ?';
                $bind[]  = date('Y-m-d H:i:s', time() - self::DEFAULT_RANGE_DAYS * 86400);
            }
            $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

            $columns = 'id, project, tag, image, deploy_type, target, status, triggered_by, deploy_note, created_at';

            $countStmt = $this->pdo->prepare('SELECT count(*) FROM ' . AppConfig::TABLE_CD_DEPLOY_LOG_VIEW . " {$whereClause}");
            $countStmt->execute($bind);
            $total = (int)$countStmt->fetchColumn();

            $totalPages = max(1, (int)ceil($total / $perPage));
            $page = min(max(1, $page), $totalPages);
            $offset = ($page - 1) * $perPage;

            $listStmt = $this->pdo->prepare(
                "SELECT {$columns}"
                . ' FROM ' . AppConfig::TABLE_CD_DEPLOY_LOG_VIEW
                . " {$whereClause}"
                . " ORDER BY id DESC LIMIT {$perPage} OFFSET {$offset}"
            );
            $listStmt->execute($bind);
            $items = $listStmt->fetchAll();

            return [
                'available'   => true,
                'total'       => $total,
                'page'        => $page,
                'per_page'    => $perPage,
                'total_pages' => $totalPages,
                'items'       => $items,
            ];
        } catch (\Throwable $e) {
            \App\Helper\Log::error('[部署日志] 查询失败', ['error' => $e->getMessage()]);
            return ['available' => false, 'total' => 0, 'page' => $page, 'per_page' => $perPage, 'total_pages' => 1, 'items' => []];
        }
    }
}
