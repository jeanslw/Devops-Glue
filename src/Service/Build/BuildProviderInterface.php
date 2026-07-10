<?php
namespace App\Service\Build;

interface BuildProviderInterface
{
    public function getName(): string;

    /** Pipeline 列表，每项含 id, iid, status, ref, sha, web_url, created_at */
    public function getPipelines(string $projectId, int $perPage = 20): array;

    /** 指定 pipeline 的 job 列表，每项含 id, name, stage, status, runner, created_at, duration */
    public function getJobs(string $projectId, int $pipelineId): array;

    /** job 原始日志 */
    public function getJobTrace(string $projectId, int $jobId): string;

    /** 触发新 pipeline；返回触发结果 */
    public function trigger(string $projectId, string $ref, array $variables = []): array;

    /** 重试失败的 pipeline；不支持时应返回明确的错误信息 */
    public function retry(string $projectId, int $pipelineId): array;

    /** 取消运行中的 pipeline；不支持时应返回明确的错误信息 */
    public function cancel(string $projectId, int $pipelineId): array;

    /** CI 变量/构建参数列表，每项含 key, value（value 脱敏） */
    public function getVariables(string $projectId): array;

    /** 回写 commit 状态（用于 Harbor 扫描结果等外部检查） */
    public function setCommitStatus(string $projectId, string $sha, string $state, string $name, string $description, string $targetUrl = ''): array;
}
