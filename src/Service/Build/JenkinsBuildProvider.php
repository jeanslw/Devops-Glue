<?php
namespace App\Service\Build;

use App\Service\JenkinsService;
use App\Service\Logger;

class JenkinsBuildProvider implements BuildProviderInterface
{
    private JenkinsService $jenkins;
    private ?Logger $logger;

    public function __construct(JenkinsService $jenkins, ?Logger $logger = null)
    {
        $this->jenkins = $jenkins;
        $this->logger  = $logger;
    }

    public function getName(): string { return 'jenkins'; }

    public function getPipelines(string $projectId, int $perPage = 20): array
    {
        try {
            $buildIds = $this->jenkins->getBuildIds($projectId);
            $ids = array_slice($buildIds, 0, $perPage);
            $result = [];
            foreach ($ids as $bid) {
                try {
                    $status = $this->jenkins->getBuildStatus($projectId, (int) $bid);
                } catch (\Exception $e) {
                    $status = 'unknown';
                }
                $result[] = [
                    'id'         => (int) $bid,
                    'iid'        => (int) $bid,
                    'status'     => strtolower($status),
                    'ref'        => '',
                    'sha'        => '',
                    'web_url'    => $this->jenkins->getJobUrl($projectId) . '/' . $bid . '/',
                    'created_at' => '',
                    'updated_at' => '',
                ];
            }
            return $result;
        } catch (\Exception $e) {
            $this->logger?->error('Jenkins build 查询失败', ['project' => $projectId, 'error' => $e->getMessage()]);
            return [];
        }
    }

    public function getJobs(string $projectId, int $pipelineId): array
    {
        try {
            $status = $this->jenkins->getBuildStatus($projectId, $pipelineId);
        } catch (\Exception $e) {
            $status = 'unknown';
        }
        return [[
            'id'         => $pipelineId,
            'name'       => $projectId,
            'stage'      => 'build',
            'status'     => strtolower($status),
            'runner'     => 'jenkins',
            'created_at' => '',
            'duration'   => 0,
        ]];
    }

    public function getJobTrace(string $projectId, int $jobId): string
    {
        try {
            return $this->jenkins->getConsoleOutput($projectId, $jobId);
        } catch (\Exception $e) {
            $this->logger?->error('Jenkins console 查询失败', ['project' => $projectId, 'job' => $jobId, 'error' => $e->getMessage()]);
            return '日志获取失败: ' . $e->getMessage();
        }
    }

    public function trigger(string $projectId, string $ref, array $variables = []): array
    {
        $params = array_merge(['branches' => $ref], $variables);
        try {
            $result = $this->jenkins->triggerBuild($projectId, $params);
            return [
                'success'   => true,
                'queue_id'  => $result['queue_id'] ?? '',
                'queue_url' => $result['queue_url'] ?? '',
                'message'   => $result['message'] ?? '构建已触发',
            ];
        } catch (\Exception $e) {
            $this->logger?->error('Jenkins trigger 失败', ['project' => $projectId, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => '触发失败: ' . $e->getMessage()];
        }
    }

    public function retry(string $projectId, int $pipelineId): array
    {
        return ['success' => false, 'message' => 'Jenkins 不支持 retry，请使用 trigger 重新触发构建'];
    }

    public function cancel(string $projectId, int $pipelineId): array
    {
        return ['success' => false, 'message' => 'Jenkins 不支持 cancel，请到 Jenkins 后台手动中止'];
    }

    public function getVariables(string $projectId): array
    {
        try {
            $params = $this->jenkins->getParameters($projectId);
            // Jenkins 参数格式：{"branches":["main","master"],"zone":["test","prd"]}
            $result = [];
            foreach ($params as $key => $options) {
                $result[] = [
                    'key'       => $key,
                    'value'     => '',
                    'options'   => is_array($options) ? $options : [],
                    'type'      => 'choice',
                ];
            }
            return $result;
        } catch (\Exception $e) {
            $this->logger?->error('Jenkins parameters 查询失败', ['project' => $projectId, 'error' => $e->getMessage()]);
            return [];
        }
    }

    public function setCommitStatus(string $projectId, string $sha, string $state, string $name, string $description, string $targetUrl = ''): array
    {
        return ['success' => false, 'message' => 'Jenkins 不支持 commit status 回写'];
    }
}
