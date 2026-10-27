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
     * 批量按 sha 取缓存 tag。
     *
     * @param list<string> $shas
     * @return array<string,string> sha => tag
     */
    public function tagsBySha(array $shas): array
    {
        if ($shas === []) {
            return [];
        }
        $sph = implode(',', array_fill(0, count($shas), '?'));
        $stmt = $this->pdo->prepare("SELECT sha, tag FROM " . AppConfig::TABLE_BUILD_TAG_CACHE . " WHERE sha IN ({$sph}) AND tag != ''");
        $stmt->execute($shas);
        $map = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $sha = (string) ($row['sha'] ?? '');
            if ($sha !== '' && ($row['tag'] ?? '') !== '') {
                $map[$sha] = $row['tag'];
            }
        }
        return $map;
    }

    /** 读 sha 缓存的 tag；无则 null。 */
    public function findTag(string $sha): ?string
    {
        $stmt = $this->pdo->prepare("SELECT tag FROM " . AppConfig::TABLE_BUILD_TAG_CACHE . " WHERE sha = ?");
        $stmt->execute([$sha]);
        $cached = $stmt->fetchColumn();
        return $cached === false ? null : (string)$cached;
    }

    /** upsert 一条 sha→tag 缓存。 */
    public function upsert(string $projectKey, string $provider, string $projectId, string $sha, string $pipelineId, string $tag, string $repository, string $source): void
    {
        $sql = Database::sqlUpsert(
            AppConfig::TABLE_BUILD_TAG_CACHE,
            'project_key, provider, project_id, sha, pipeline_id, tag, repository, source, updated_at',
            '?, ?, ?, ?, ?, ?, ?, ?, ' . Database::sqlNow()
        );
        $this->pdo->prepare($sql)->execute([$projectKey, $provider, $projectId, $sha, $pipelineId, $tag, $repository, $source]);
    }
}
