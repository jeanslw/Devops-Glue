<?php

declare(strict_types=1);

namespace App\Service;

use App\Config\AppConfig;

/**
 * Pipeline 到 Primary Artifact（当前阶段保持 1:1）的规范读写层。
 *
 * ci_pipeline_artifacts 是唯一权威数据来源。
 * digest 为镜像不可变身份（通常 Docker-Content-Digest / sha256:...），可空以兼容旧数据与未上报场景。
 */
final class PipelineArtifactService
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * 写入/更新主 artifact。
     * source_updated_at 非空时采用单调更新：旧事件不能覆盖更新事件。
     * digest 入参非空时写入/更新；入参为空时不覆盖已有 digest（防止二次上报擦掉已回填值）。
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
        ?string $digest = null,
    ): array {
        if ($tag === '' || $repository === '') {
            return ['written' => false, 'ignored' => false];
        }

        $digest = $this->normalizeDigest($digest);

        $existing = $this->find($identity);
        if ($existing && $sourceUpdatedAt !== null && $sourceUpdatedAt !== '') {
            $old = (string) ($existing['source_updated_at'] ?? '');
            // digest 变更优先：同名 tag 内容已变，强制刷新，不受时间单调守卫拦截
            $digestChanged = $digest !== null && $digest !== ''
                && $digest !== trim((string) ($existing['digest'] ?? ''));
            if (!$digestChanged && $old !== '' && $this->compareTimes($sourceUpdatedAt, $old) < 0) {
                return ['written' => false, 'ignored' => true];
            }
        }

        $now = $createdAt ?: gmdate('Y-m-d H:i:s');
        if ($existing) {
            $digestToStore = $digest !== null && $digest !== ''
                ? $digest
                : (string) ($existing['digest'] ?? '');

            $stmt = $this->pdo->prepare(
                'UPDATE ' . AppConfig::TABLE_PIPELINE_ARTIFACTS . '
                 SET project_key = ?, tag = ?, repository = ?, status = ?,
                     digest = ?, source_updated_at = ?, created_at = ?, updated_at = ' . Database::sqlNow() . '
                 WHERE provider = ? AND project_id = ? AND pipeline_iid = ?'
            );
            $stmt->execute([
                $projectKey, $tag, $repository, $status, $digestToStore !== '' ? $digestToStore : null,
                $sourceUpdatedAt, $createdAt ?: $existing['created_at'],
                $identity->provider, $identity->projectId, $identity->pipelineIid,
            ]);
        } else {
            $sql = Database::sqlInsertIgnore(
                AppConfig::TABLE_PIPELINE_ARTIFACTS,
                'provider, project_id, pipeline_iid, project_key, repository, tag, digest, status, source_updated_at, created_at, updated_at',
                '?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ' . Database::sqlNow()
            );
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                $identity->provider, $identity->projectId, $identity->pipelineIid,
                $projectKey, $repository, $tag, $digest !== null && $digest !== '' ? $digest : null,
                $status, $sourceUpdatedAt, $now,
            ]);
        }

        return ['written' => true, 'ignored' => false];
    }

    /**
     * @return array<string,mixed>|null
     */
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
            'SELECT provider, project_id, pipeline_iid, project_key, repository, tag, digest, status,
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

    /**
     * 规范化 digest：trim；空串视为 null；不做强制格式拒绝（兼容非 sha256 仓库实现）。
     */
    private function normalizeDigest(?string $digest): ?string
    {
        if ($digest === null) {
            return null;
        }
        $digest = trim($digest);
        return $digest === '' ? null : $digest;
    }

    /**
     * 每 project_key 最新一条 artifact（按 created_at 取最大）。
     *
     * @return array<string, array{tag:string, pipeline_iid:mixed, created_at:mixed, digest?:string}>
     */
    public function latestTagsByProject(): array
    {
        $rows = $this->pdo->query(
            'SELECT t.project_key AS project, t.pipeline_iid, t.tag, t.digest, t.created_at'
            . ' FROM ' . AppConfig::TABLE_PIPELINE_ARTIFACTS . ' t'
            . ' INNER JOIN (SELECT project_key, MAX(created_at) AS max_created_at'
            . ' FROM ' . AppConfig::TABLE_PIPELINE_ARTIFACTS . ' GROUP BY project_key) m'
            . ' ON t.project_key = m.project_key AND t.created_at = m.max_created_at'
            . ' ORDER BY t.created_at DESC, t.pipeline_iid DESC'
        )->fetchAll();
        $latest = [];
        foreach ($rows as $r) {
            if (!isset($latest[$r['project']])) {
                $latest[$r['project']] = [
                    'tag'          => $r['tag'] ?? '',
                    'digest'       => $r['digest'] ?? '',
                    'pipeline_iid' => $r['pipeline_iid'] ?? '',
                    'created_at'   => $r['created_at'] ?? '',
                ];
            }
        }
        return $latest;
    }

    /** @param list<string> $keys 命中 project_key 的 artifact 行数 */
    public function countByProjectKeys(array $keys): int
    {
        if ($keys === []) {
            return 0;
        }
        $ph = implode(',', array_fill(0, count($keys), '?'));
        $stmt = $this->pdo->prepare('SELECT COUNT(*) AS c FROM ' . AppConfig::TABLE_PIPELINE_ARTIFACTS . ' WHERE project_key IN (' . $ph . ')');
        $stmt->execute($keys);
        return (int) $stmt->fetch()['c'];
    }

    /**
     * 按 project_key 集合分页取 artifact 列表。
     *
     * @param list<string> $keys
     * @return list<array{tag:string, pipeline_iid:mixed, created_at:mixed, digest?:string}>
     */
    public function listByProjectKeys(array $keys, int $pageSize, int $offset): array
    {
        if ($keys === []) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($keys), '?'));
        $stmt = $this->pdo->prepare(
            'SELECT tag, digest, pipeline_iid, created_at FROM ' . AppConfig::TABLE_PIPELINE_ARTIFACTS .
            ' WHERE project_key IN (' . $ph . ') ORDER BY created_at DESC LIMIT ? OFFSET ?'
        );
        foreach ($keys as $i => $k) {
            $stmt->bindValue($i + 1, $k, \PDO::PARAM_STR);
        }
        $stmt->bindValue(count($keys) + 1, $pageSize, \PDO::PARAM_INT);
        $stmt->bindValue(count($keys) + 2, $offset, \PDO::PARAM_INT);
        $stmt->execute();
        return array_values(array_map(fn($r) => [
            'tag'          => (string)($r['tag'] ?? ''),
            'digest'       => (string)($r['digest'] ?? ''),
            'pipeline_iid' => $r['pipeline_iid'] ?? '',
            'created_at'   => $r['created_at'] ?? '',
        ], $stmt->fetchAll()));
    }

    /**
     * 全表 tag 索引：project → pipeline_iid → {tag, registry, status, digest}。
     *
     * @return array<string, array<string, array<string,mixed>>>
     */
    public function tagIndex(): array
    {
        $rows = $this->pdo->query(
            "SELECT project_key AS project, pipeline_iid, tag, digest, repository AS registry_repository, status, created_at FROM " . AppConfig::TABLE_PIPELINE_ARTIFACTS . " ORDER BY created_at DESC"
        )->fetchAll();
        $result = [];
        foreach ($rows as $r) {
            $result[$r['project']][(string) $r['pipeline_iid']] = [
                'tag'      => $r['tag'],
                'digest'   => $r['digest'] ?? '',
                'registry' => $r['registry_repository'] ?? '',
                'status'   => $r['status'] ?? '',
            ];
        }
        return $result;
    }
}
