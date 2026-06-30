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

        return $this->jsonResponse($response, $params);
    }

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

    $allParams = $this->jenkins->getParameters($resolved['fullName'], null);

    // 如果 Job 没有任何参数，不允许触发
    if (empty($allParams)) {
        return $this->jsonError($response, 'This job has no build parameters. Triggering via this API requires at least a branch parameter.', 400);
    }

    // 强制补全 branch 参数（处理 Git Parameter 等情况）
    $hasBranchParam = false;
    foreach ($allParams as $name => $choices) {
        if (stripos($name, 'branch') !== false) {
            $hasBranchParam = true;
            if (empty($choices)) {
                try {
                    $allParams[$name] = $this->git->getBranchesForJob($resolved['fullName']);
                } catch (\Exception $e) {
                    $allParams[$name] = [];
                }
            }
            break;
        }
    }
    if (!$hasBranchParam) {
        try {
            $branches = $this->git->getBranchesForJob($resolved['fullName']);
            if (!empty($branches)) {
                $allParams['branches'] = $branches;
            }
        } catch (\Exception $e) {}
    }

    $paramCount = count($allParams);
    $inputParamCount = $zoneValue ? 2 : 1;

    if ($inputParamCount !== $paramCount) {
        return $this->jsonError($response,
            "This job expects $paramCount parameter(s), but you provided $inputParamCount.", 400);
    }

    if ($paramCount > 2) {
        return $this->jsonError($response,
            "Jobs with more than 2 parameters are not supported via this API.", 400);
    }

    $branchParamName = $this->findBranchParamName($allParams);
    if (!$branchParamName || !isset($allParams[$branchParamName])) {
        return $this->jsonError($response, 'Cannot identify branch parameter', 400);
    }
    if (!in_array($branchValue, $allParams[$branchParamName])) {
        return $this->jsonError($response, "Branch value '$branchValue' not allowed", 400);
    }

    $buildParams = [$branchParamName => $branchValue];

    if ($paramCount === 2) {
        if (!$zoneValue) {
            return $this->jsonError($response, 'Missing zone value', 400);
        }
        $zoneParamName = $this->findZoneParamName($allParams, $branchParamName);
        if (!$zoneParamName || !isset($allParams[$zoneParamName])) {
            return $this->jsonError($response, 'Cannot identify zone parameter', 400);
        }
        if (!in_array($zoneValue, $allParams[$zoneParamName])) {
            return $this->jsonError($response, "Zone value '$zoneValue' not allowed", 400);
        }
        $buildParams[$zoneParamName] = $zoneValue;
    }

    try {
        $result = $this->jenkins->triggerBuild($resolved['fullName'], $buildParams);
        return $this->jsonResponse($response, [
            'code'       => 200,
            'message'    => '构建触发成功',
            'job'        => $resolved['fullName'],
            'parameters' => $buildParams,
            'queue_id'   => $result['queueId'] ?? null,
            'queue_url'  => $result['queueUrl'] ?? null,
        ]);
    } catch (\Exception $e) {
        return $this->jsonError($response, 'Trigger failed: ' . $e->getMessage(), 500);
    }
}

    // 辅助方法 1：自动识别 branch 参数名
    private function findBranchParamName(array $params): ?string
    {
        foreach ($params as $name => $choices) {
            if (stripos($name, 'branch') !== false) return $name;
        }
        $names = array_keys($params);
        return $names[0] ?? null;
    }

    // 辅助方法 2：自动识别 zone 参数名（排除已识别的 branch 参数）
    private function findZoneParamName(array $params, string $excludeName): ?string
    {
        foreach ($params as $name => $choices) {
            if ($name === $excludeName) continue;
            if (stripos($name, 'zone') !== false || stripos($name, 'env') !== false) return $name;
        }
        $names = array_keys($params);
        foreach ($names as $name) {
            if ($name !== $excludeName) return $name;
        }
        return null;
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

        return $this->jsonResponse($response, $result);
    }

    // 控制台
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