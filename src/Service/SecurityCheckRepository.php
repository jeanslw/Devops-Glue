<?php

declare(strict_types=1);

namespace App\Service;

use App\Config\AppConfig;

/**
 * ci_security_checks 的列表查询与写入。
 */
class SecurityCheckRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * 分页查询安全扫描结果（project/check_type/state/writeback/exclude 筛选）。
     *
     * @param array{project:string, check_type:string, state:string, writeback:string, exclude:string} $filters
     * @return array<string,mixed> 完整响应负载（checks/total/page/per_page/total_pages/filter_opts）
     */
    public function search(array $filters, int $page, int $perPage): array
    {
        $where = [];
        $bind  = [];
        if ($filters['project'] !== '') {
            $where[] = 'project LIKE ?';
            $bind[]  = "%{$filters['project']}%";
        }
        if ($filters['check_type'] !== '') {
            $where[] = 'check_type = ?';
            $bind[]  = $filters['check_type'];
        }
        if ($filters['state'] !== '') {
            $where[] = 'state = ?';
            $bind[]  = $filters['state'];
        }
        if ($filters['writeback'] !== '') {
            $where[] = 'writeback_status = ?';
            $bind[]  = $filters['writeback'];
        }
        if ($filters['exclude'] !== '') {
            $excluded = array_filter(explode(',', $filters['exclude']), fn($s) => $s !== '');
            if (!empty($excluded)) {
                $placeholders = implode(',', array_fill(0, count($excluded), '?'));
                $where[] = "state NOT IN ({$placeholders})";
                $bind = array_merge($bind, array_values($excluded));
            }
        }
        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $countStmt = $this->pdo->prepare("SELECT count(*) FROM " . AppConfig::TABLE_SECURITY_CHECKS . " {$whereClause}");
        $countStmt->execute($bind);
        $total = (int)$countStmt->fetchColumn();

        $totalPages = max(1, (int)ceil($total / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        $rows = $this->pdo->prepare(
            "SELECT * FROM " . AppConfig::TABLE_SECURITY_CHECKS . " {$whereClause} ORDER BY created_at DESC LIMIT {$perPage} OFFSET {$offset}"
        );
        $rows->execute($bind);
        $checks = $rows->fetchAll();

        $types = $this->distinctCheckTypes();

        return [
            'checks'      => $checks,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => $totalPages,
            'filter_opts' => [
                'check_types'       => $types,
                'states'            => ['success', 'failed', 'pending', 'error'],
                'writeback_statuses' => ['success', 'failed', 'skipped'],
            ],
        ];
    }

    /**
     * 仓库扫描类型去重列表（集合稳定，缓存 300 秒，避免每次检索都全表扫描）。
     *
     * @return list<string>
     */
    private function distinctCheckTypes(): array
    {
        $cached = $this->cacheGet('security:distinct_check_types');
        if (is_array($cached)) {
            return array_values(array_map('strval', $cached));
        }
        $types = $this->pdo->query(
            "SELECT DISTINCT check_type FROM " . AppConfig::TABLE_SECURITY_CHECKS . " ORDER BY check_type"
        )->fetchAll(\PDO::FETCH_COLUMN);
        $this->cacheSet('security:distinct_check_types', $types, 300);
        return array_values($types);
    }

    /** @return array<string,mixed>|null */
    private function cacheGet(string $key): ?array
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT value FROM " . AppConfig::TABLE_CACHE . " WHERE cache_key = ? AND expires_at > ?"
            );
            $stmt->execute([$key, time()]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row === false) {
                return null;
            }
            $data = json_decode((string)$row['value'], true);
            return is_array($data) ? $data : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * @param array<mixed> $value
     */
    private function cacheSet(string $key, array $value, int $ttlSeconds): void
    {
        $sql = Database::sqlUpsert(
            AppConfig::TABLE_CACHE,
            'cache_key, value, expires_at',
            '?, ?, ?'
        );
        $this->pdo->prepare($sql)->execute([$key, json_encode($value), time() + $ttlSeconds]);
    }

    /** 写一条安全扫描/回写审计记录（upsert），失败仅记日志不影响主流程。pipeline_id 用于按构建维度投影 tag。 */
    public function record(string $project, string $sha, string $state, string $context, string $description, string $checkType, string $tag, string $writebackStatus = '', string $writebackMessage = '', string $pipelineId = ''): void
    {
        try {
            $sql = Database::sqlUpsert(
                AppConfig::TABLE_SECURITY_CHECKS,
                'project, sha, check_type, state, context, description, tag, writeback_status, writeback_message, pipeline_id, created_at',
                '?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ' . Database::sqlNow()
            );
            $this->pdo->prepare($sql)->execute([$project, $sha, $checkType, $state, $context, $description, $tag, $writebackStatus, $writebackMessage, $pipelineId]);
        } catch (\Exception $e) {
            \App\Helper\Log::exception($e);
        }
    }

    /**
     * 按 (project, pipeline_id) 查询「回写成功」的 tag 映射（check_type in harbor-scan/registry-sync 且 writeback_status=success）。
     * 不再按 sha 聚合，避免同一 commit 多次构建塌缩为单一 tag。
     *
     * @param list<string|int> $pipelineIds
     * @return array<string,string> pipeline_id => tag
     */
    public function tagsByPipelineKeys(string $project, array $pipelineIds): array
    {
        if ($pipelineIds === []) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($pipelineIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT pipeline_id, tag FROM " . AppConfig::TABLE_SECURITY_CHECKS
            . " WHERE check_type IN ('harbor-scan', 'registry-sync') AND writeback_status = 'success'"
            . " AND pipeline_id IN ({$ph}) AND project = ?"
        );
        $stmt->execute(array_merge($pipelineIds, [$project]));
        $map = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $pid = (string) ($row['pipeline_id'] ?? '');
            if ($pid !== '' && ($row['tag'] ?? '') !== '') {
                $map[$pid] = (string) $row['tag'];
            }
        }
        return $map;
    }
}
