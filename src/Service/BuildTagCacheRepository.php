<?php

declare(strict_types=1);

namespace App\Service;

use App\Config\AppConfig;

/**
 * ci_build_tag_cache 的读写（镜像 tag 懒解析结果的 sha→tag 缓存）。
 */
class BuildTagCacheRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * 按 (provider, project_id, pipeline_id) 批量取缓存 tag 与 digest（不再按 sha 聚合，避免同 sha 多构建塌缩）。
     *
     * @param list<string|int> $pipelineIds
     * @return array<string,array{tag:string,digest:string}> pipeline_id => 数据
     */
    public function tagsByPipelineKeys(string $provider, string $projectId, array $pipelineIds): array
    {
        if ($pipelineIds === []) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($pipelineIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT pipeline_id, tag, digest FROM " . AppConfig::TABLE_BUILD_TAG_CACHE
            . " WHERE provider = ? AND project_id = ? AND pipeline_id IN ({$ph}) AND tag != ''"
        );
        $stmt->execute(array_merge([$provider, $projectId], $pipelineIds));
        $map = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $pid = (string) ($row['pipeline_id'] ?? '');
            if ($pid !== '' && ($row['tag'] ?? '') !== '') {
                $map[$pid] = [
                    'tag'    => (string) $row['tag'],
                    'digest' => (string) ($row['digest'] ?? ''),
                ];
            }
        }
        return $map;
    }

    /** 读某次 pipeline 构建的缓存 tag 与 digest；无则 null。 */
    public function findTag(string $provider, string $projectId, string $pipelineId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT tag, digest FROM " . AppConfig::TABLE_BUILD_TAG_CACHE
            . " WHERE provider = ? AND project_id = ? AND pipeline_id = ?"
        );
        $stmt->execute([$provider, $projectId, $pipelineId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false || ($row['tag'] ?? '') === '') {
            return null;
        }
        return [
            'tag'    => (string) $row['tag'],
            'digest' => (string) ($row['digest'] ?? ''),
        ];
    }

    /** upsert 一条 sha→tag 缓存（含日志提取的 digest）。 */
    public function upsert(string $projectKey, string $provider, string $projectId, string $sha, string $pipelineId, string $tag, string $repository, string $source, string $digest = ''): void
    {
        $sql = Database::sqlUpsert(
            AppConfig::TABLE_BUILD_TAG_CACHE,
            'project_key, provider, project_id, sha, pipeline_id, tag, repository, source, digest, updated_at',
            '?, ?, ?, ?, ?, ?, ?, ?, ?, ' . Database::sqlNow()
        );
        $this->pdo->prepare($sql)->execute([$projectKey, $provider, $projectId, $sha, $pipelineId, $tag, $repository, $source, $digest]);
    }
}
