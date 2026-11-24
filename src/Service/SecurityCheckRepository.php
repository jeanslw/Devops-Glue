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

    /**
     * 严格写入审计：失败抛异常，供 worker 判断“Git 已成功但本地审计失败”，
     * 从而保留队列任务重试，不因 best-effort 吞错导致审计永久丢失。
     *
     * @param array{project:string,sha:string,state:string,context:string,description:string,check_type:string,tag:string,writeback_status:string,writeback_message:string,pipeline_id:string,pipeline_key?:string} $row
     */
    public function recordStrict(array $row): void
    {
        $sql = Database::sqlUpsert(
            AppConfig::TABLE_SECURITY_CHECKS,
            'project, sha, check_type, state, context, description, tag, writeback_status, writeback_message, pipeline_id, pipeline_key, created_at',
            '?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ' . Database::sqlNow()
        );
        $this->pdo->prepare($sql)->execute([
            $row['project'], $row['sha'], $row['check_type'], $row['state'], $row['context'],
            $row['description'], $row['tag'], $row['writeback_status'], $row['writeback_message'],
            $row['pipeline_id'], (string)($row['pipeline_key'] ?? ''),
        ]);
    }

    /** 写一条安全扫描/回写审计记录（upsert），失败仅记日志不影响主流程。 */
    public function record(string $project, string $sha, string $state, string $context, string $description, string $checkType, string $tag, string $writebackStatus = '', string $writebackMessage = '', string $pipelineId = '', string $pipelineKey = ''): void
    {
        try {
            $this->recordStrict([
                'project' => $project, 'sha' => $sha, 'state' => $state, 'context' => $context,
                'description' => $description, 'check_type' => $checkType, 'tag' => $tag,
                'writeback_status' => $writebackStatus, 'writeback_message' => $writebackMessage,
                'pipeline_id' => $pipelineId, 'pipeline_key' => $pipelineKey,
            ]);
        } catch (\Throwable $e) {
            \App\Helper\Log::exception($e);
        }
    }

    /**
     * 按 Pipeline 查询「回写成功」的 tag 映射。
     *
     * 优先用 canonical pipeline_key（provider:projectId:iid）匹配，避免不同 provider 的
     * 裸 iid 互相覆盖；历史行只有 pipeline_id 时再按裸 id 兜底，且不覆盖 canonical 结果。
     *
     * @param list<string|int> $pipelineIds
     * @return array<string,string> pipeline_id => tag
     */
    public function tagsByPipelineKeys(string $project, array $pipelineIds, string $provider = '', string $projectId = ''): array
    {
        if ($pipelineIds === []) {
            return [];
        }
        $map = [];
        $baseWhere = " FROM " . AppConfig::TABLE_SECURITY_CHECKS
            . " WHERE check_type IN ('harbor-scan', 'registry-sync') AND writeback_status = 'success'"
            . " AND project = ?";
        if ($provider !== '' && $projectId !== '') {
            // canonical 结果优先：先查，后续 legacy 结果不覆盖。
            $keys = [];
            foreach ($pipelineIds as $pid) {
                $keys[] = (new PipelineIdentity($provider, $projectId, (int)$pid))->storageKey();
            }
            $ph = implode(',', array_fill(0, count($keys), '?'));
            $stmt = $this->pdo->prepare(
                "SELECT pipeline_id, tag, id" . $baseWhere . " AND pipeline_key IN ({$ph}) ORDER BY id DESC"
            );
            $stmt->execute(array_merge([$project], $keys));
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $pid = (string) ($row['pipeline_id'] ?? '');
                if ($pid !== '' && ($row['tag'] ?? '') !== '' && !isset($map[$pid])) {
                    $map[$pid] = (string) $row['tag'];
                }
            }
        }
        $ph = implode(',', array_fill(0, count($pipelineIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT pipeline_id, tag, id" . $baseWhere . " AND pipeline_id IN ({$ph}) ORDER BY id DESC"
        );
        $stmt->execute(array_merge([$project], $pipelineIds));
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $pid = (string) ($row['pipeline_id'] ?? '');
            if ($pid !== '' && ($row['tag'] ?? '') !== '' && !isset($map[$pid])) {
                $map[$pid] = (string) $row['tag'];
            }
        }
        return $map;
    }
}
