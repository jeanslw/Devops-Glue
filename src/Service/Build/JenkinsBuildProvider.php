<?php
namespace App\Service\Build;

use App\Service\JenkinsService;
use App\Service\GitService;
use App\Service\Logger;

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
        $branchValue = $ref;
        $zoneValue   = $variables['zone'] ?? '';

        if (empty($branchValue)) {
            return ['success' => false, 'message' => '缺少分支参数'];
        }

        // 1. 获取 Jenkins 参数定义
        try {
            $allParams = $this->jenkins->getParameters($projectId, null);
        } catch (\Exception $e) {
            return ['success' => false, 'message' => '获取构建参数失败: ' . $e->getMessage()];
        }

        if (empty($allParams)) {
            // 无参数 Job：直接触发
            try {
                $result = $this->jenkins->triggerBuild($projectId, []);
                return ['success' => true, 'queue_id' => $result['queue_id'] ?? '', 'queue_url' => $result['queue_url'] ?? '', 'message' => '构建已触发'];
            } catch (\Exception $e) {
                return ['success' => false, 'message' => '触发失败: ' . $e->getMessage()];
            }
        }

        // 2. 自动识别 branch 参数名
        $branchParamName = null;
        foreach ($allParams as $name => $choices) {
            if (stripos($name, 'branch') !== false) { $branchParamName = $name; break; }
        }
        if (!$branchParamName) $branchParamName = array_keys($allParams)[0] ?? null;
        if (!$branchParamName) {
            return ['success' => false, 'message' => '无法识别分支参数名'];
        }

        // 3. 分支选项为空时从 Git 补齐
        $branchOptions = $allParams[$branchParamName] ?? [];
        if (empty($branchOptions) && $this->git) {
            try {
                $gitBranches = $this->git->getBranchesForJob($projectId);
                if (!empty($gitBranches)) { $branchOptions = $gitBranches; $allParams[$branchParamName] = $gitBranches; }
            } catch (\Exception $e) {}
        }

        // 4. 验证分支值
        if (!empty($branchOptions)) {
            $actual = $branchValue;
            if (!in_array($actual, $branchOptions)) {
                // 尝试 origin/ 前缀适配
                $allOrigin = true;
                foreach ($branchOptions as $opt) { if (strpos($opt, 'origin/') !== 0) { $allOrigin = false; break; } }
                if ($allOrigin && in_array('origin/' . $branchValue, $branchOptions)) {
                    $actual = 'origin/' . $branchValue;
                } else {
                    return ['success' => false, 'message' => "无效的分支: {$branchValue}，可用值: " . implode(', ', array_slice($branchOptions, 0, 10))];
                }
            }
            $branchValue = $actual;
        }

        // 5. 构造参数
        $buildParams = [$branchParamName => $branchValue];

        // 6. 双参数处理
        $paramNames = array_keys($allParams);
        if (count($paramNames) === 2 && !empty($zoneValue)) {
            $zoneParamName = ($paramNames[0] === $branchParamName) ? $paramNames[1] : $paramNames[0];
            $zoneOptions = $allParams[$zoneParamName] ?? [];
            if (!in_array($zoneValue, $zoneOptions)) {
                return ['success' => false, 'message' => "无效的 zone: {$zoneValue}，可用值: " . implode(', ', $zoneOptions)];
            }
            $buildParams[$zoneParamName] = $zoneValue;
        }

        // 7. 触发
        try {
            $result = $this->jenkins->triggerBuild($projectId, $buildParams);
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
