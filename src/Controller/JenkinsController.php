<?php
namespace App\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Service\JenkinsService;
use App\Service\GitService;

class JenkinsController extends BaseController
{
    private JenkinsService $jenkins;
    private GitService $git;

    public function __construct(JenkinsService $jenkins, GitService $git)
    {
        $this->jenkins = $jenkins;
        $this->git = $git;
    }

    // 参数列表（支持两种 URL 写法）
    public function parameters(Request $request, Response $response, array $args): Response
    {
        $path = $args['path'] ?? '';
        $buildId = $args['build_id'] ?? null;

        if ($buildId === null && preg_match('/^(.*)\/(\d+)$/', $path, $matches)) {
            $path = $matches[1];
            $buildId = (int)$matches[2];
        }
        $buildId = $buildId !== null ? (int)$buildId : null;

        $resolved = $this->jenkins->resolvePath($path);
        if (!$resolved || $resolved['type'] !== 'job') {
            return $this->jsonError($response, 'Job not found: ' . $path, 404);
        }

        $params = $this->jenkins->getParameters($resolved['fullName'], $buildId);

        if ($buildId === null && empty($params['branches'])) {
            try {
                $params['branches'] = $this->git->getBranchesForJob($resolved['fullName']);
            } catch (\Exception $e) {
                error_log("Git branches fetch failed: " . $e->getMessage());
                $params['branches'] = [];
            }
        }

        return $this->output($response, $params, $request);
    }

    // 触发构建
    public function buildTrigger(Request $request, Response $response, array $args): Response
    {
        $jobPath     = $args['path'] ?? '';
        $branchValue = $args['branch_value'] ?? null;
        $zoneValue   = $args['zone_value'] ?? null;

        if (!$branchValue) {
            return $this->jsonError($response, 'Missing branch value', 400);
        }

        $resolved = $this->jenkins->resolvePath($jobPath);
        if (!$resolved || $resolved['type'] !== 'job') {
            return $this->jsonError($response, 'Invalid job: ' . $jobPath, 400);
        }

        $currentParams = $this->jenkins->getParameters($resolved['fullName'], null);

        // 如果 Job 没有任何参数，拒绝触发
        if (empty($currentParams)) {
            return $this->jsonError($response, 'This job has no build parameters. Triggering via this API requires at least a branch parameter.', 400);
        }
        
        // 补齐 branches（处理 Git Parameter 等动态参数）
        if (empty($currentParams['branches'])) {
            try {
                $currentParams['branches'] = $this->git->getBranchesForJob($resolved['fullName']);
            } catch (\Exception $e) {
                error_log("Build trigger branch fetch failed: " . $e->getMessage());
                $currentParams['branches'] = [];
            }
        }

        // 校验 zone（如果存在该参数）
        if (isset($currentParams['zone']) && !in_array($zoneValue, $currentParams['zone'])) {
            return $this->jsonError($response, "Invalid zone: $zoneValue", 400);
        }

        // 校验 branch
        if (!in_array($branchValue, $currentParams['branches'] ?? [])) {
            return $this->jsonError($response, "Invalid branch: $branchValue", 400);
        }

        try {
            $result = $this->jenkins->triggerBuild($resolved['fullName'], [
                'zone' => $zoneValue,
                'branches' => $branchValue,
            ]);

            // 构建成功响应数据（无 code 字段）
            $responseData = [
                'message' => '构建触发成功',
                'job'     => $resolved['fullName'],
                'triggered_params' => [
                    'branches' => $branchValue,
                    'zone'     => $zoneValue,
                    'jobname'  => $resolved['fullName'],
                ],
                'queue_id'  => $result['queueId'] ?? null,
                'queue_url' => $result['queueUrl'] ?? null,
            ];

            $response->getBody()->write(json_encode($responseData));
            return $response->withHeader('Content-Type', 'application/json');

        } catch (\Exception $e) {
            return $this->jsonError($response, 'Trigger failed: ' . $e->getMessage(), 500);
        }
    }

    // 状态
    public function status(Request $request, Response $response, array $args): Response
    {
        $path = $args['path'];
        $buildId = (int)$args['build_id'];
        $resolved = $this->jenkins->resolvePath($path);
        if (!$resolved || $resolved['type'] !== 'job') {
            return $this->jsonError($response, 'Job not found', 404);
        }
        $status = $this->jenkins->getBuildStatus($resolved['fullName'], $buildId);
        return $this->output($response, [$status], $request);
    }

    // 合并的 build 列表
    public function buildList(Request $request, Response $response, array $args): Response
    {
        $path = $args['path'];
        $type = $args['type'] ?? 'build';

        $resolved = $this->jenkins->resolvePath($path);
        if (!$resolved || $resolved['type'] !== 'job') {
            return $this->jsonError($response, 'Job not found', 404);
        }

        $success = $this->jenkins->getSuccessfulBuilds($resolved['fullName']);

        $result = match ($type) {
            'build_id'  => array_map(fn($b) => (string)$b['id'], $success),
            'build_time' => array_map(function ($b) {
                $timestamp = (int)($b['timestamp'] / 1000);
                return "#{$b['id']} [" . date('Y-m-d H:i:s', $timestamp) . "]";
            }, $success),
            'build'     => array_map(fn($b) => "#{$b['id']}", $success),
            default     => [],
        };

        return $this->output($response, $result, $request);
    }

    // 控制台（强制原样输出）
    public function console(Request $request, Response $response, array $args): Response
    {
        $path = $args['path'];
        $buildId = (int)$args['build_id'];
        $resolved = $this->jenkins->resolvePath($path);
        if (!$resolved) return $this->jsonError($response, 'Job not found', 404);
        $text = $this->jenkins->getConsoleOutput($resolved['fullName'], $buildId);
        return $this->output($response, $text, $request, true);
    }
}