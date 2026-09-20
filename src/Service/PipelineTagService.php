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
                        $repoCache[$harborRepo] = !isset($tags['error']) ? $tags : null;
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

    /**
     * 回填：把 ci_pipeline_build_log 中「日志推导」的 tag（source='log'）经 Harbor 校验存在后，
     * 提升写入 canonical ci_pipeline_artifacts —— 仅当该 (provider, project_id, pipeline_iid)
     * 尚无 tag 时才写，绝不覆盖 scanSync 的权威结果。
     *
     * 信任边界：只有 Harbor 明确返回「该 tag 存在」才会落库，故回填数据与 scanSync 同信任级。
     *
     * @return array{promoted:int,checked:int,unreachable:int,unverifiable:int,skipped:int}
     */
    public function backfillTagsFromBuildLog(): array
    {
        $stat = ['promoted' => 0, 'checked' => 0, 'unreachable' => 0, 'unverifiable' => 0, 'skipped' => 0];

        if (!$this->harbor) {
            return $stat;
        }

        try {
            $rows = $this->pdo->query(
                'SELECT project_key, provider, project_id, pipeline_id, tag, repository '
                . 'FROM ' . AppConfig::TABLE_PIPELINE_BUILD_LOG
                . " WHERE source = 'log' AND tag != '' AND provider != '' AND project_id != '' AND repository != ''"
            )->fetchAll();
        } catch (\Throwable $e) {
            Log::exception($e);
            return $stat;
        }

        if (!$rows) {
            return $stat;
        }

        $artifactSvc = new PipelineArtifactService($this->pdo);
        $repoCache = [];

        foreach ($rows as $r) {
            $provider     = (string) $r['provider'];
            $projectId    = (string) $r['project_id'];
            $pipelineIid  = (int) $r['pipeline_id'];
            $projectKey   = (string) ($r['project_key'] ?? '');
            $tag          = (string) ($r['tag'] ?? '');
            $repo         = (string) ($r['repository'] ?? '');

            if ($provider === '' || $projectId === '' || $pipelineIid <= 0 || $tag === '' || $repo === '') {
                $stat['unverifiable']++;
                continue;
            }

            try {
                $identity = new PipelineIdentity($provider, $projectId, $pipelineIid);
            } catch (\InvalidArgumentException $e) {
                $stat['unverifiable']++;
                continue;
            }

            // 已有 canonical tag → 权威优先，回填不覆盖
            $existing = $artifactSvc->find($identity);
            if ($existing && trim((string) ($existing['tag'] ?? '')) !== '') {
                $stat['skipped']++;
                continue;
            }

            // Harbor 校验 tag 存在（按仓库缓存，与 cleanupStaleTags 同思路）
            if (!array_key_exists($repo, $repoCache)) {
                $parts = explode('/', $repo, 2);
                if (count($parts) === 2) {
                    try {
                        $tags = $this->harbor->getTags($parts[0], $parts[1]);
                        $repoCache[$repo] = !isset($tags['error']) ? $tags : null;
                    } catch (\Throwable $e) {
                        Log::exception($e);
                        $repoCache[$repo] = null;
                    }
                } else {
                    $repoCache[$repo] = null;
                }
            }

            $validTags = $repoCache[$repo];
            if ($validTags === null) {
                $stat['unreachable']++;
                continue;
            }
            $stat['checked']++;
            if (!in_array($tag, $validTags, true)) {
                continue; // Harbor 中不存在该 tag，不回填
            }

            // 回填写入 canonical（Harbor 已确认存在）
            try {
                $res = $artifactSvc->record($identity, $projectKey, $tag, $repo, '');
                if ($res['written']) {
                    $stat['promoted']++;
                }
            } catch (\Throwable $e) {
                Log::exception($e);
            }
        }

        return $stat;
    }
}
