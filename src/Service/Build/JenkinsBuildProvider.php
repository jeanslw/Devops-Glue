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
            $builds = $this->jenkins->getBuildTimestamps($projectId); // 一次 API：ID + 时间 + 状态
            $ids = array_slice(array_keys($builds), 0, $perPage);
            $result = [];
            foreach ($ids as $bid) {
                $info = $builds[$bid] ?? ['time' => '', 'status' => 'unknown'];
                $result[] = [
                    'id'         => (int) $bid,
                    'iid'        => (int) $bid,
                    'status'     => $info['status'],
                    'ref'        => '',
                    'sha'        => '',
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
        $inputParams = $variables;
        if (!empty($ref) && empty($inputParams)) {
            $inputParams['branches'] = $ref;
        }
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

        // 2. 获取 Jenkins 参数定义
        try {
            $allParams = $this->jenkins->getParameters($fullName, null);
        } catch (\Exception $e) {
            return ['success' => false, 'message' => '获取构建参数失败: ' . $e->getMessage()];
        }

        if (empty($allParams)) {
            try {
                $result = $this->jenkins->triggerBuild($fullName, []);
                return ['success' => true, 'queue_id' => $result['queue_id'] ?? '', 'queue_url' => $result['queue_url'] ?? '', 'message' => '构建已触发'];
            } catch (\Exception $e) {
                return ['success' => false, 'message' => '触发失败: ' . $e->getMessage()];
            }
        }

        // 2. 找到分支参数（按关键词匹配第一个含 branch 的，否则第一个参数）
        $paramNames = array_keys($allParams);
        $branchKey = null;
        foreach ($paramNames as $name) {
            if (stripos($name, 'branch') !== false) { $branchKey = $name; break; }
        }
        if (!$branchKey) $branchKey = $paramNames[0];

        // 3. 分支选项为空时从 Git 补齐
        $branchOptions = $allParams[$branchKey] ?? [];
        if (empty($branchOptions) && $this->git) {
            try {
                $gitBranches = $this->git->getBranchesForJob($fullName);
                if (!empty($gitBranches)) { $branchOptions = $gitBranches; $allParams[$branchKey] = $gitBranches; }
            } catch (\Exception $e) {}
        }

        // 4. 验证传入参数
        if (empty($inputParams)) {
            return ['success' => false, 'message' => '缺少参数，可用参数: ' . implode(', ', $paramNames)];
        }
        $buildParams = [];
        foreach ($inputParams as $key => $value) {
            $matchedKey = $this->matchParamKey($key, $paramNames);
            if (!$matchedKey) {
                return ['success' => false, 'message' => "Jenkins Job '{$projectId}' 没有参数 '{$key}'，可用参数: " . implode(', ', $paramNames)];
            }
            $options = $allParams[$matchedKey] ?? [];
            // 分支参数用 Git 补全后的选项
            if ($matchedKey === $branchKey && !empty($branchOptions)) $options = $branchOptions;
            if (!empty($options)) {
                $actual = $this->matchBranchValue($value, $options);
                if ($actual === null) {
                    return ['success' => false, 'message' => "无效的值 '{$value}'（参数 '{$matchedKey}'），可用值: " . implode(', ', array_slice($options, 0, 10))];
                }
                $value = $actual;
            }
            $buildParams[$matchedKey] = $value;
        }

        // 5. 触发
        try {
            $result = $this->jenkins->triggerBuild($fullName, $buildParams);
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

    /** 匹配参数 key：输入值可能是别名（branches→branch_param），用相似度匹配 */
    private function matchParamKey(string $input, array $paramNames): ?string
    {
        if (in_array($input, $paramNames)) return $input;
        foreach ($paramNames as $name) {
            if (stripos($name, $input) !== false || stripos($input, $name) !== false) return $name;
        }
        return null;
    }

    /** 处理 origin/ 前缀匹配 */
    private function matchBranchValue(string $input, array $options): ?string
    {
        if (in_array($input, $options)) return $input;
        $allOrigin = true;
        foreach ($options as $opt) { if (strpos($opt, 'origin/') !== 0) { $allOrigin = false; break; } }
        if ($allOrigin && in_array('origin/' . $input, $options)) return 'origin/' . $input;
        return null;
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
            $result = [];
            foreach ($params as $key => $options) {
                $opts = is_array($options) ? $options : [];
                // 分支参数为空时从 Git 补齐
                if (empty($opts) && stripos($key, 'branch') !== false && $this->git) {
                    try { $opts = $this->git->getBranchesForJob($projectId); } catch (\Exception $e) {}
                }
                $result[] = ['key' => $key, 'value' => '', 'options' => $opts, 'type' => 'choice'];
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
