<?php

namespace App\Service;

use App\Config\AppConfig;
use App\Helper\Log;

/**
 * Pipeline artifact 的「以 Harbor 为准」清理服务。
 *
 * ci_pipeline_artifacts 是唯一 canonical source。
 */
class PipelineTagService
{
    private \PDO $pdo;
    private ?HarborService $harbor;

    public function __construct(\PDO $pdo, ?HarborService $harbor = null)
    {
        $this->pdo = $pdo;
        $this->harbor = $harbor;
    }

    /**
     * 全表清理：删除 Harbor 中已不存在的 artifact/tag 记录。
     *
     * @return array{deleted:int,checked:int,unreachable:int,unverifiable:int}
     */
    public function cleanupStaleTags(): array
    {
        $stat = ['deleted' => 0, 'checked' => 0, 'unreachable' => 0, 'unverifiable' => 0];

        if (!$this->harbor) {
            return $stat;
        }

        try {
            $rows = $this->pdo->query(
                'SELECT provider, project_id, pipeline_iid, tag, repository '
                . 'FROM ' . AppConfig::TABLE_PIPELINE_ARTIFACTS
            )->fetchAll();
        } catch (\Throwable $e) {
            Log::exception($e);
            return $stat;
        }

        if (!$rows) {
            return $stat;
        }

        $repoCache = [];
        $staleKeys = [];

        foreach ($rows as $r) {
            $harborRepo = (string) ($r['repository'] ?? '');
            $tag        = (string) ($r['tag'] ?? '');
            if ($harborRepo === '' || $tag === '') {
                $stat['unverifiable']++;
                continue;
            }

            if (!array_key_exists($harborRepo, $repoCache)) {
                $parts = explode('/', $harborRepo, 2);
                if (count($parts) === 2) {
                    try {
                        $tags = $this->harbor->getTags($parts[0], $parts[1]);
                        $repoCache[$harborRepo] = (is_array($tags) && !isset($tags['error'])) ? $tags : null;
                    } catch (\Throwable $e) {
                        Log::exception($e);
                        $repoCache[$harborRepo] = null;
                    }
                } else {
                    $repoCache[$harborRepo] = null;
                }
            }

            $validTags = $repoCache[$harborRepo];
            if ($validTags === null) {
                $stat['unreachable']++;
                continue;
            }
            $stat['checked']++;
            if (!in_array($tag, $validTags, true)) {
                $staleKeys[] = [
                    'provider'     => (string) $r['provider'],
                    'project_id'   => (string) $r['project_id'],
                    'pipeline_iid' => (int) $r['pipeline_iid'],
                ];
            }
        }

        if (!empty($staleKeys)) {
            try {
                $this->pdo->beginTransaction();
                $artifactStmt = $this->pdo->prepare(
                    'DELETE FROM ' . AppConfig::TABLE_PIPELINE_ARTIFACTS
                    . ' WHERE provider = ? AND project_id = ? AND pipeline_iid = ?'
                );
                foreach ($staleKeys as $key) {
                    $artifactStmt->execute([$key['provider'], $key['project_id'], $key['pipeline_iid']]);
                    $deleted = $artifactStmt->rowCount();
                    if ($deleted > 0) {
                        $stat['deleted'] += $deleted;
                    }
                }
                $this->pdo->commit();
            } catch (\Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                Log::exception($e);
            }
        }

        return $stat;
    }
}
