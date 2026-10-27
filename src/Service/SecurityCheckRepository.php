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

        $types = $this->pdo->query("SELECT DISTINCT check_type FROM " . AppConfig::TABLE_SECURITY_CHECKS . " ORDER BY check_type")->fetchAll(\PDO::FETCH_COLUMN);

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

    /** 写一条安全扫描/回写审计记录（upsert），失败仅记日志不影响主流程。 */
    public function record(string $project, string $sha, string $state, string $context, string $description, string $checkType, string $tag, string $writebackStatus = '', string $writebackMessage = ''): void
    {
        try {
            $sql = Database::sqlUpsert(
                AppConfig::TABLE_SECURITY_CHECKS,
                'project, sha, check_type, state, context, description, tag, writeback_status, writeback_message, created_at',
                '?, ?, ?, ?, ?, ?, ?, ?, ?, ' . Database::sqlNow()
            );
            $this->pdo->prepare($sql)->execute([$project, $sha, $checkType, $state, $context, $description, $tag, $writebackStatus, $writebackMessage]);
        } catch (\Exception $e) {
            \App\Helper\Log::exception($e);
        }
    }

    /**
     * 按项目别名查询「回写成功」的 sha→tag 映射（check_type in harbor-scan/registry-sync 且 writeback_status=success）。
     *
     * @param list<string> $projects
     * @return array<string,string> sha => tag
     */
    public function tagsByShaForProjects(array $projects): array
    {
        $ph = implode(',', array_fill(0, count($projects), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT sha, tag FROM " . AppConfig::TABLE_SECURITY_CHECKS
            . " WHERE check_type IN ('harbor-scan', 'registry-sync') AND writeback_status = 'success'"
            . " AND sha IS NOT NULL AND sha != '' AND project IN ({$ph})"
        );
        $stmt->execute($projects);
        $map = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $sha = (string) ($row['sha'] ?? '');
            if ($sha !== '' && ($row['tag'] ?? '') !== '') {
                $map[$sha] = $row['tag'];
            }
        }
        return $map;
    }
}
