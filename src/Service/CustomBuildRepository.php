<?php

declare(strict_types=1);

namespace App\Service;

use App\Config\AppConfig;

/**
 * ci_custom_builds 的列表查询（join pipeline artifact 取镜像 tag）。
 */
class CustomBuildRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /** @return array<string,mixed> custom_build 分页列表（records/total/page/per_page/total_pages） */
    public function list(int $page, int $perPage): array
    {
        $total = (int) $this->pdo->query("SELECT count(*) FROM " . AppConfig::TABLE_CUSTOM_BUILDS)->fetchColumn();

        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        $stmt = $this->pdo->prepare(
            'SELECT b.job_name, b.pipeline_iid, b.status, b.sha, b.variables_json, b.log_url, b.web_url, b.finished_at,
                    t.tag
             FROM ' . AppConfig::TABLE_CUSTOM_BUILDS . ' b
             LEFT JOIN ' . AppConfig::TABLE_PIPELINE_ARTIFACTS . ' t
               ON t.project_key = b.job_name AND t.pipeline_iid = b.pipeline_iid AND t.provider = ?
             ORDER BY b.finished_at DESC, b.pipeline_iid DESC
             LIMIT ' . $perPage . ' OFFSET ' . $offset
        );
        $stmt->execute([AppConfig::PROVIDER_CUSTOM_PUSH]);
        $records = array_map(function (array $r): array {
            return [
                'job_name'       => $r['job_name'] ?? '',
                'pipeline_iid'   => (int) ($r['pipeline_iid'] ?? 0),
                'status'         => $r['status'] ?? '',
                'tag'            => $r['tag'] ?? '',
                'sha'            => $r['sha'] ?? '',
                'variables_json' => $r['variables_json'] ?? '',
                'log_url'        => $r['log_url'] ?? '',
                'web_url'        => $r['web_url'] ?? '',
                'finished_at'    => $r['finished_at'] ?? '',
            ];
        }, $stmt->fetchAll());

        return [
            'records'     => $records,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => $totalPages,
        ];
    }
}
