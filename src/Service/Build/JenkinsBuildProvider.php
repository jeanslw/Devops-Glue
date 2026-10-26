<?php

namespace App\Service\Build;

use App\Service\JenkinsService;
use App\Service\GitService;
use App\Service\Logger;
use App\Config\AppConfig;
use App\Exceptions\ApiException;
use GuzzleHttp\Exception\ClientException;

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

    public function getName(): string
    {
        return AppConfig::PROVIDER_JENKINS;
    }

    /** @return list<array<string, mixed>> */
    public function getPipelines(string $projectId, int $perPage = 20): array
    {
        try {
            $builds = $this->jenkins->getBuildTimestamps($projectId);
            $ids = array_slice(array_keys($builds), 0, $perPage);
            $result = [];
            foreach ($ids as $bid) {
                $info = $builds[$bid] ?? ['time' => '', 'status' => 'unknown'];
                $result[] = [
                    'id'         => (int) $bid,
                    'iid'        => (int) $bid,
                    'status'     => BuildStatus::normalize((string) $info['status']),
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
            $this->throwJenkinsError($projectId, $e);
        }
    }

    /** @return list<array<string, mixed>> */
    public function getJobs(string $projectId, int $pipelineId): array
    {
        try {
            $status = $this->jenkins->getBuildStatus($projectId, $pipelineId);
        } catch (\Exception $e) {
            $this->logger?->error('Jenkins build 状态查询失败', ['project' => $projectId, 'build' => $pipelineId, 'error' => $e->getMessage()]);
            BuildProviderError::throwFor($e, 'Jenkins');
        }
        return [[
            'id'         => $pipelineId,
            'name'       => $projectId,
            'stage'      => 'build',
            'status'     => BuildStatus::normalize($status),
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
            BuildProviderError::throwFor($e, 'Jenkins');
        }
    }

    /**
     * @param array<string, mixed> $variables
     * @return array<string, mixed>
     */
    public function trigger(string $projectId, string $ref, array $variables = []): array
    {

        try {
            /** @var array<string, mixed>|null $resolved */
            $resolved = $this->jenkins->resolvePath($projectId);
            if (!$resolved || ($resolved['type'] ?? '') !== 'job') {
                return ['success' => false, 'message' => "Job 不存在: {$projectId}"];
            }
            $fullName = $resolved['fullName'];
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Exception $e) {
            return ['success' => false, 'message' => "Jenkins 不可达: " . $e->getMessage()];
        }


        try {
            $allParams = $this->jenkins->getParameterDefinitions($fullName);
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Exception $e) {
            return ['success' => false, 'message' => '获取构建参数失败: ' . $e->getMessage()];
        }


        if (empty($allParams)) {
            try {
                /** @var array<string, mixed> $result */
                $result = $this->jenkins->triggerBuild($fullName, []);
                return ['success' => true, 'queue_id' => $result['queue_id'] ?? '', 'queue_url' => $result['queue_url'] ?? '', 'message' => '构建已触发'];
            } catch (\RuntimeException $e) {
                throw $e;
            } catch (\Exception $e) {
                return ['success' => false, 'message' => '触发失败: ' . $e->getMessage()];
            }
        }


        foreach ($allParams as $name => $def) {
            if (!array_key_exists($name, $variables) && $def['defaultValue'] !== null) {
                $variables[$name] = $def['defaultValue'];
            }
        }

        if (empty($variables)) {
            return ['success' => false, 'message' => '缺少构建参数，可用参数: ' . implode(', ', array_keys($allParams))];
        }

        $paramNames = array_keys($allParams);
        foreach ($variables as $key => $value) {
            if (!in_array($key, $paramNames, true)) {
                return ['success' => false, 'message' => "Jenkins Job 没有参数 '{$key}'，可用参数: " . implode(', ', $paramNames)];
            }
        }


        try {
            /** @var array<string, mixed> $result */
            $result = $this->jenkins->triggerBuild($fullName, $variables);
            return [
                'success'   => true,
                'queue_id'  => $result['queue_id'] ?? '',
                'queue_url' => $result['queue_url'] ?? '',
                'message'   => $result['message'] ?? '构建已触发',
            ];
        } catch (\Exception $e) {
            $this->logger?->error('Jenkins trigger 失败', ['project' => $projectId, 'error' => $e->getMessage()]);
            BuildProviderError::throwFor($e, 'Jenkins');
        }
    }

    /** @return array<string, mixed> */
    public function retry(string $projectId, int $pipelineId): array
    {
        return ['success' => false, 'message' => 'Jenkins 不支持 retry，请使用 trigger 重新触发构建'];
    }

    /** @return array<string, mixed> */
    public function cancel(string $projectId, int $pipelineId): array
    {
        return ['success' => false, 'message' => 'Jenkins 不支持 cancel，请到 Jenkins 后台手动中止'];
    }

    /** @return list<array<string, mixed>> */
    public function getVariables(string $projectId): array
    {
        try {
            $paramDefs = $this->jenkins->getParameterDefinitions($projectId);
            $result = [];
            foreach ($paramDefs as $key => $def) {
                $class = $def['_class'];
                $item = ['key' => $key];


                if (str_contains($class, 'GitParameterDefinition')) {
                    $item['type'] = 'git';
                } elseif (str_contains($class, 'BooleanParameterDefinition')) {
                    $item['type'] = 'boolean';
                    $item['defaultValue'] = $def['defaultValue'] ?? false;
                } elseif (str_contains($class, 'TextParameterDefinition')) {
                    $item['type'] = 'text';
                    $item['defaultValue'] = $def['defaultValue'] ?? '';
                } elseif (str_contains($class, 'PasswordParameterDefinition')) {
                    $item['type'] = 'password';
                } elseif (str_contains($class, 'ChoiceParameterDefinition') || str_contains($class, 'ListStringParameterDefinition')) {
                    $item['type'] = 'select';
                    if (!empty($def['choices'])) {
                        $item['choices'] = $def['choices'];
                    }
                    $item['defaultValue'] = $def['defaultValue'] ?? null;
                } elseif (str_contains($class, 'unochoice') || str_contains($class, 'CascadeChoiceParameter')) {
                    $item['type'] = 'dynamic';
                    $item['defaultValue'] = $def['defaultValue'] ?? null;
                } elseif (str_contains($class, 'StringParameterDefinition')) {
                    $item['type'] = 'string';
                    $item['defaultValue'] = $def['defaultValue'] ?? '';
                } else {
                    $item['type'] = 'string';
                    $item['defaultValue'] = $def['defaultValue'] ?? '';
                    if (!empty($def['choices'])) {
                        $item['choices'] = $def['choices'];
                    }
                }


                if (!empty($def['description'])) {
                    $item['description'] = $def['description'];
                }

                $result[] = $item;
            }
            return $result;
        } catch (\Exception $e) {
            $this->logger?->error('Jenkins parameters 查询失败', ['project' => $projectId, 'error' => $e->getMessage()]);
            $this->throwJenkinsError($projectId, $e);
        }
    }

    /** @return array<int, string> */
    public function getBranches(string $projectId): array
    {
        if (!$this->git) {
            return [];
        }
        try {
            return $this->git->getBranchesForJob($projectId);
        } catch (\Exception $e) {
            $this->logger?->warning('Git 分支查询失败', ['project' => $projectId, 'error' => $e->getMessage()]);
            BuildProviderError::throwFor($e, 'Jenkins');
        }
    }

    /** @return list<array<string, mixed>> */
    public function getRunners(string $projectId): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    public function setCommitStatus(string $projectId, string $sha, string $state, string $name, string $description, string $targetUrl = ''): array
    {
        return ['success' => false, 'message' => 'Jenkins 不支持 commit status 回写'];
    }

    /**
     * 把 Jenkins 底层异常映射为 HTTP 语义，与 Git provider 的约定对齐：
     * 404 = Job 不存在，502 = Jenkins 服务不可达，其余保持 500。始终抛出 ApiException。
     */
    private function throwJenkinsError(string $projectId, \Exception $e): never
    {
        if ($e instanceof ClientException && $e->getResponse()->getStatusCode() === 404) {
            throw new ApiException("Job 不存在: {$projectId}", 404);
        }

        BuildProviderError::throwFor($e, 'Jenkins');
    }
}
