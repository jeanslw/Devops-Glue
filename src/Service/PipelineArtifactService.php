<?php

declare(strict_types=1);

namespace App\Service;

use App\Config\AppConfig;

/**
 * Pipeline → Primary Artifact（当前阶段保持 1:1）的规范读写层。
 *
 * ci_pipeline_artifacts 是唯一 canonical source。
 */
final class PipelineArtifactService
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * 写入/更新主 artifact。
     * source_updated_at 非空时采用单调更新：旧事件不能覆盖更新事件。
     *
     * @return array{written:bool,ignored:bool}
     */
    public function record(
        PipelineIdentity $identity,
        string $projectKey,
        string $tag,
        string $repository,
        string $status = '',
        ?string $sourceUpdatedAt = null,
        ?string $createdAt = null,
    ): array {
        if ($tag === '' || $repository === '') {
            return ['written' => false, 'ignored' => false];
        }

        $existing = $this->find($identity);
        if ($existing && $sourceUpdatedAt !== null && $sourceUpdatedAt !== '') {
            $old = (string) ($existing['source_updated_at'] ?? '');
            if ($old !== '' && $this->compareTimes($sourceUpdatedAt, $old) < 0) {
                return ['written' => false, 'ignored' => true];
            }
        }

        $now = $createdAt ?: date('Y-m-d H:i:s');
        if ($existing) {
            $stmt = $this->pdo->prepare(
                'UPDATE ' . AppConfig::TABLE_PIPELINE_ARTIFACTS . '
                 SET project_key = ?, tag = ?, repository = ?, status = ?,
                     source_updated_at = ?, created_at = ?, updated_at = ' . Database::sqlNow() . '
                 WHERE provider = ? AND project_id = ? AND pipeline_iid = ?'
            );
            $stmt->execute([
                $projectKey, $tag, $repository, $status, $sourceUpdatedAt, $createdAt ?: $existing['created_at'],
                $identity->provider, $identity->projectId, $identity->pipelineIid,
            ]);
        } else {
            $sql = Database::sqlInsertIgnore(
                AppConfig::TABLE_PIPELINE_ARTIFACTS,
                'provider, project_id, pipeline_iid, project_key, repository, tag, status, source_updated_at, created_at, updated_at',
                '?, ?, ?, ?, ?, ?, ?, ?, ?, ' . Database::sqlNow()
            );
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                $identity->provider, $identity->projectId, $identity->pipelineIid,
                $projectKey, $repository, $tag, $status, $sourceUpdatedAt, $now,
            ]);
        }

        return ['written' => true, 'ignored' => false];
    }

    public function find(PipelineIdentity $identity): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ' . AppConfig::TABLE_PIPELINE_ARTIFACTS . '
             WHERE provider = ? AND project_id = ? AND pipeline_iid = ?'
        );
        $stmt->execute([$identity->provider, $identity->projectId, $identity->pipelineIid]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @return array<int,array<string,mixed>> */
    public function all(): array
    {
        return $this->pdo->query(
            'SELECT provider, project_id, pipeline_iid, project_key, repository, tag, status,
                    source_updated_at, created_at
             FROM ' . AppConfig::TABLE_PIPELINE_ARTIFACTS . ' ORDER BY created_at DESC'
        )->fetchAll();
    }

    private function compareTimes(string $a, string $b): int
    {
        $ta = strtotime($a);
        $tb = strtotime($b);
        if ($ta !== false && $tb !== false) {
            return $ta <=> $tb;
        }
        return strcmp($a, $b);
    }
}
