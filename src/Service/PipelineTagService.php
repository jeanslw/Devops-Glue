<?php

namespace App\Service;

use App\Config\AppConfig;
use App\Helper\Log;
use App\Service\Registry\RegistryProviderInterface;

/**
 * Pipeline artifact 的镜像仓库校验与清理服务。
 *
 * ci_pipeline_artifacts 是唯一权威数据来源。
 */
class PipelineTagService
{
    private \PDO $pdo;
    private ?RegistryProviderInterface $registry;

    public function __construct(\PDO $pdo, ?RegistryProviderInterface $registry = null)
    {
        $this->pdo = $pdo;
        $this->registry = $registry;
    }

    /**
     * 全表清理：删除镜像仓库中已不存在的 artifact/tag 记录。
     *
     * @return array{deleted:int,checked:int,unreachable:int,unverifiable:int}
     */
    public function cleanupStaleTags(): array
    {
        $stat = ['deleted' => 0, 'checked' => 0, 'unreachable' => 0, 'unverifiable' => 0];

        if (!$this->registry) {
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
            $registryRepo = (string) ($r['repository'] ?? '');
            $tag          = (string) ($r['tag'] ?? '');
            if ($registryRepo === '' || $tag === '') {
                $stat['unverifiable']++;
                continue;
            }

            if (!array_key_exists($registryRepo, $repoCache)) {
                try {
                    $tags = $this->registry->getTags($registryRepo);
                    $repoCache[$registryRepo] = !isset($tags['error']) ? $tags : null;
                } catch (\Throwable $e) {
                    Log::exception($e);
                    $repoCache[$registryRepo] = null;
                }
            }

            $validTags = $repoCache[$registryRepo];
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
     * 回填：把 ci_build_tag_cache 中日志推导出的 tag（source='log'）经镜像仓库校验存在后，
     * 提升写入 ci_pipeline_artifacts；仅当该 (provider, project_id, pipeline_iid)
     * 尚无 tag 时才写，不覆盖 registry-sync 的结果。
     *
     * 信任边界：只有镜像仓库明确返回该 tag 存在才会写入，故回填数据与 registry-sync 同信任级。
     *
     * @return array{promoted:int,checked:int,unreachable:int,unverifiable:int,skipped:int}
     */
    public function backfillTagsFromBuildLog(): array
    {
        $stat = ['promoted' => 0, 'checked' => 0, 'unreachable' => 0, 'unverifiable' => 0, 'skipped' => 0];

        if (!$this->registry) {
            return $stat;
        }

        try {
            $rows = $this->pdo->query(
                'SELECT project_key, provider, project_id, pipeline_id, tag, repository '
                . 'FROM ' . AppConfig::TABLE_BUILD_TAG_CACHE
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


            $existing = $artifactSvc->find($identity);
            if ($existing && trim((string) ($existing['tag'] ?? '')) !== '') {
                $stat['skipped']++;
                continue;
            }


            if (!array_key_exists($repo, $repoCache)) {
                try {
                    $tags = $this->registry->getTags($repo);
                    $repoCache[$repo] = !isset($tags['error']) ? $tags : null;
                } catch (\Throwable $e) {
                    Log::exception($e);
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
                continue;
            }


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
