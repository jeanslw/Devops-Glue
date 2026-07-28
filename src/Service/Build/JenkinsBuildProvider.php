<?php
namespace App\Service\Build;

use App\Service\JenkinsService;
use App\Service\GitService;
use App\Service\Logger;
use App\Config\AppConfig;

class JenkinsBuildProvider implements BuildProviderInterface
{
    private JenkinsService $jenkins;
    private ?GitService $git;
    private ?Logger $logger;

    public function __construct(JenkinsService $jenkins, ?GitService $git = null, ?Logger $logger = null)
    {
        $this->jenkins = $jenkins;
        $this->git    = $git;
        $this->logger = $logger;
    }

    public function getName(): string { return AppConfig::PROVIDER_JENKINS; }

    public function getPipelines(string $projectId, int $perPage = 20): array
    {
        try {
            $builds = $this->jenkins->getBuildTimestamps($projectId); // 一次 API：ID + 时间 + 状态
            $ids = array_slice(array_keys($builds), 0, $perPage);
            $result = [];
            foreach ($ids as $bid) {
                $info = $builds[$bid] ?? ['time' => '', 'status' => 'unknown'];
                $result[] = [
                    'id'         => (int) $bid,
                    'iid'        => (int) $bid,
                    'status'     => $info['status'],
                    'ref'        => $info['ref'] ?? '',
                    'sha'        => $info['sha'] ?? '',
                    'web_url'    => $this->jenkins->getJobUrl($projectId) . '/' . $bid . '/',
                    'created_at' => $info['time'],
                    'updated_at' => $info['time'],
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
            'runner'     => AppConfig::PROVIDER_JENKINS,
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
        // 1. 校验 Job 是否存在
        try {
            $resolved = $this->jenkins->resolvePath($projectId);
            if (!$resolved || ($resolved['type'] ?? '') !== 'job') {
                return ['success' => false, 'message' => "Job 不存在: {$projectId}"];
            }
            $fullName = $resolved['fullName'];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => "Jenkins 不可达: " . $e->getMessage()];
        }

        // 2. 获取参数定义
        try {
            $allParams = $this->jenkins->getParameterDefinitions($fullName);
        } catch (\Exception $e) {
            return ['success' => false, 'message' => '获取构建参数失败: ' . $e->getMessage()];
        }

        // 无参数 → 直接触发
        if (empty($allParams)) {
            try {
                $result = $this->jenkins->triggerBuild($fullName, []);
                return ['success' => true, 'queue_id' => $result['queue_id'] ?? '', 'queue_url' => $result['queue_url'] ?? '', 'message' => '构建已触发'];
            } catch (\Exception $e) {
                return ['success' => false, 'message' => '触发失败: ' . $e->getMessage()];
            }
        }

        // 3. 参数校验：调用方必须在 variables 里显式传参数名和值
        if (empty($variables)) {
            return ['success' => false, 'message' => '缺少构建参数，可用参数: ' . implode(', ', array_keys($allParams))];
        }

        $paramNames = array_keys($allParams);
        foreach ($variables as $key => $value) {
            if (!in_array($key, $paramNames, true)) {
                return ['success' => false, 'message' => "Jenkins Job 没有参数 '{$key}'，可用参数: " . implode(', ', $paramNames)];
            }
        }

        // 4. 触发
        try {
            $result = $this->jenkins->triggerBuild($fullName, $variables);
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
            $paramDefs = $this->jenkins->getParameterDefinitions($projectId);
            $result = [];
            foreach ($paramDefs as $key => $def) {
                $class = $def['_class'] ?? '';
                $type  = str_contains($class, 'GitParameterDefinition') ? 'git' : 'choice';
                $result[] = ['key' => $key, 'type' => $type];
            }
            return $result;
        } catch (\Exception $e) {
            $this->logger?->error('Jenkins parameters 查询失败', ['project' => $projectId, 'error' => $e->getMessage()]);
            return [];
        }
    }

    public function getBranches(string $projectId): array
    {
        if (!$this->git) {
            return [];
        }
        try {
            return $this->git->getBranchesForJob($projectId);
        } catch (\Exception $e) {
            $this->logger?->warning('Git 分支查询失败', ['project' => $projectId, 'error' => $e->getMessage()]);
            return [];
        }
    }

    public function setCommitStatus(string $projectId, string $sha, string $state, string $name, string $description, string $targetUrl = ''): array
    {
        return ['success' => false, 'message' => 'Jenkins 不支持 commit status 回写'];
    }
}
