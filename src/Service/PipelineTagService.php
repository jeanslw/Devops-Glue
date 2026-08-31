<?php

namespace App\Service;

use App\Config\AppConfig;
use App\Helper\Log;

/**
 * ci_pipeline_tags 的「以 Harbor 为准」清理服务。
 *
 * 正确性归 Glue（CI 层）维护：按解耦约定 CD 层只读 ci_* 表、绝不删（用户可能不启用
 * CD 系统），所以 Harbor 里被删除的 tag，其对应 ci_pipeline_tags 记录由本服务清除。
 *
 * 安全不变量（继承自 BuildController 旧实现，搬移时保持一致）：
 *   - Harbor 未配置 → 直接跳过，一条不删；
 *   - harbor_repository / tag 为空 → 跳过（不可校验 = 保留）；
 *   - Harbor 不可达 → 该仓库跳过，不误删；
 *   - 只删「Harbor 明确返回了 tag 列表且其中没有这条」的行，绝不反向推断；
 *   - 只碰 ci_pipeline_tags，绝不碰任何 cd_* 表。
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
     * 全表清理：删除 Harbor 中已不存在的 tag 记录。
     *
     * @return array{deleted:int,checked:int,unreachable:int,unverifiable:int}
     *   deleted         实际删除的条数
     *   checked         已与 Harbor 核对（明确判断在/不在）的行数
     *   unreachable     因 Harbor 不可达而无法核对、予以保留的行数
     *   unverifiable    harbor_repository 或 tag 为空、无法核对、予以保留的行数
     */
    public function cleanupStaleTags(): array
    {
        $stat = ['deleted' => 0, 'checked' => 0, 'unreachable' => 0, 'unverifiable' => 0];

        if (!$this->harbor) {
            return $stat;
        }

        try {
            $rows = $this->pdo->query(
                'SELECT project, pipeline_iid, tag, harbor_repository FROM ' . AppConfig::TABLE_PIPELINE_TAGS
            )->fetchAll();
        } catch (\Throwable $e) {
            Log::exception($e);
            return $stat;
        }

        if (!$rows) {
            return $stat;
        }

        $repoCache = [];  // harbor_repo => string[]（tag 列表）或 null（Harbor 不可达）
        $staleKeys = [];  // [['project' => ..., 'pipeline_iid' => int], ...]

        foreach ($rows as $r) {
            $harborRepo = $r['harbor_repository'] ?? '';
            $tag        = $r['tag'] ?? '';
            if ($harborRepo === '' || $tag === '') {
                $stat['unverifiable']++;
                continue;
            }

            if (!array_key_exists($harborRepo, $repoCache)) {
                $parts = explode('/', $harborRepo, 2);
                if (count($parts) === 2) {
                    $tags = $this->harbor->getTags($parts[0], $parts[1]);
                    // Harbor 不可达时 getTags 返回 ['error' => ...]，置 null 跳过该仓库
                    $repoCache[$harborRepo] = isset($tags['error']) ? null : $tags;
                } else {
                    $repoCache[$harborRepo] = [];
                }
            }

            $validTags = $repoCache[$harborRepo];
            if ($validTags === null) {
                $stat['unreachable']++;
                continue;
            }
            $stat['checked']++;
            if (!in_array($tag, $validTags, true)) {
                $staleKeys[] = ['project' => (string) $r['project'], 'pipeline_iid' => (int) $r['pipeline_iid']];
            }
        }

        if (!empty($staleKeys)) {
            try {
                $stmt = $this->pdo->prepare(
                    'DELETE FROM ' . AppConfig::TABLE_PIPELINE_TAGS . ' WHERE project = ? AND pipeline_iid = ?'
                );
                foreach ($staleKeys as $key) {
                    $stmt->execute([$key['project'], $key['pipeline_iid']]);
                    $stat['deleted'] += $stmt->rowCount();
                }
            } catch (\Throwable $e) {
                Log::exception($e);
            }
        }

        return $stat;
    }
}
