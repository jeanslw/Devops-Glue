<?php
namespace App\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Service\JenkinsService;
use App\Service\GitService;

class JenkinsController
{
    private JenkinsService $jenkins;
    private GitService $git;

    public function __construct(JenkinsService $jenkins, GitService $git)
    {
        $this->jenkins = $jenkins;
        $this->git = $git;
    }

    // 参数列表
    public function parameters(Request $request, Response $response, array $args): Response
    {
        $path = $args['path'] ?? '';
        $buildId = $args['build_id'] ?? null;

        // 智能解析：如果 path 末尾是数字，且 build_id 未提供，则将其作为 build_id
        if ($buildId === null && preg_match('/^(.*)\/(\d+)$/', $path, $matches)) {
            $path = $matches[1];          // 前面的部分，如 java/registry
            $buildId = (int)$matches[2];  // 末尾数字，如 69
        }
        $buildId = $buildId !== null ? (int)$buildId : null;

        // 解析 job 路径
        $resolved = $this->jenkins->resolvePath($path);
        if (!$resolved || $resolved['type'] !== 'job') {
            return $this->jsonError($response, 'Job not found: ' . $path, 404);
        }

        // 获取参数（根据 buildId 不同返回不同格式）
        $params = $this->jenkins->getParameters($resolved['fullName'], $buildId);

        // 仅在默认模式（无 buildId）且 branches 为空时，从 Git 补齐
        if ($buildId === null && empty($params['branches'])) {
            try {
                $params['branches'] = $this->git->getBranchesForJob($resolved['fullName']);
            } catch (\Exception $e) {
                error_log("Failed to fetch git branches: " . $e->getMessage());
                $params['branches'] = [];
            }
        }

        return $this->jsonResponse($response, $params);
    }

    // 触发构建
    public function buildTrigger(Request $request, Response $response, array $args): Response
    {
        $path = $args['path'] ?? '';
        $parts = explode('/', $path);
        if (count($parts) < 3) {
            return $this->jsonError($response, 'Invalid path, expected {job}/{branch}/{zone}', 400);
        }
        $zone = array_pop($parts);
        $branch = array_pop($parts);
        $jobPath = implode('/', $parts);

        $resolved = $this->jenkins->resolvePath($jobPath);
        if (!$resolved || $resolved['type'] !== 'job') {
            return $this->jsonError($response, 'Invalid job: ' . $jobPath, 400);
        }

        $currentParams = $this->jenkins->getParameters($resolved['fullName'], null);
        // 若 branches 参数为空（动态参数未取到），则不校验分支（严格门禁下会拒绝所有）
        if (!in_array($zone, $currentParams['zone'] ?? [])) {
            return $this->jsonError($response, "Invalid zone: $zone", 400);
        }
        if (!in_array($branch, $currentParams['branches'] ?? [])) {
            return $this->jsonError($response, "Invalid branch: $branch", 400);
        }

        try {
            $result = $this->jenkins->triggerBuild($resolved['fullName'], [
                'zone' => $zone,
                'branches' => $branch,
            ]);
            return $this->jsonResponse($response, [
                'code' => 200,
                'message' => '构建触发成功',
                'job' => $resolved['fullName'],
                'triggered_params' => [
                    'branches' => $branch,
                    'zone' => $zone,
                    'jobname' => $resolved['fullName'],
                ],
                'queue_id' => $result['queueId'] ?? null,
            ]);
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
        return $this->jsonResponse($response, [$status]);
    }
    
    // 合并 build_id、build_time、build 三个接口
    public function buildList(Request $request, Response $response, array $args): Response
    {
        $path = $args['path'];
        $type = $args['type'];   // 'build', 'build_id' 或 'build_time'

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

        return $this->jsonResponse($response, $result);
    }

    // 控制台输出
    public function console(Request $request, Response $response, array $args): Response
    {
        $path = $args['path'];
        $buildId = (int)$args['build_id'];
        $resolved = $this->jenkins->resolvePath($path);
        if (!$resolved) return $this->jsonError($response, 'Job not found', 404);
        $text = $this->jenkins->getConsoleOutput($resolved['fullName'], $buildId);
        $response->getBody()->write($text);
        return $response->withHeader('Content-Type', 'text/plain');
    }

    // 工具方法
    private function jsonResponse(Response $response, $data): Response
    {
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json');
    }

    private function jsonError(Response $response, string $msg, int $code = 400): Response
    {
        return $this->jsonResponse($response->withStatus($code), ['code' => $code, 'message' => $msg]);
    }
}