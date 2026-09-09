<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Glue 内部统一的 Pipeline 身份。
 *
 * canonical identity = (provider, project_id, pipeline_iid)
 *
 * project_id 的含义由 provider 决定：
 * - jenkins: job/path
 * - gitlab_ci: GitLab 数字 project_id（字符串存储，兼容未来非数字 provider）
 * - custom_push / 其他 push provider: job_name
 */
final class PipelineIdentity
{
    public function __construct(
        public readonly string $provider,
        public readonly string $projectId,
        public readonly int $pipelineIid,
    ) {
        if ($provider === '' || $projectId === '' || $pipelineIid <= 0) {
            throw new \InvalidArgumentException('无效的 Pipeline Identity');
        }
    }

    public function key(): string
    {
        return $this->provider . ':' . $this->projectId . ':' . $this->pipelineIid;
    }
}
