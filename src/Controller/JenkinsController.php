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
    public function buildTrigger(Request $request, Response $response, array $args): Response
    {
        $jobPath = $args['path'] ?? '';

        // 从 Query String 获取参数
        $queryParams = $request->getQueryParams();
        $branchValue = $queryParams['branches'] ?? $queryParams['branch'] ?? null;
        $zoneValue   = $queryParams['zone'] ?? null;

        if (empty($branchValue)) {
            return $this->jsonError($response, '缺少 branches 参数', 400);
        }

        $resolved = $this->jenkins->resolvePath($jobPath);
        if (!$resolved || $resolved['type'] !== 'job') {
            return $this->jsonError($response, '无效的 Job: ' . $jobPath, 400);
        }

        // 1. 获取 Jenkins 原始参数
        $allParams = $this->jenkins->getParameters($resolved['fullName'], null);

        if (empty($allParams)) {
            return $this->jsonError($response, '此 Job 没有构建参数', 400);
        }

        // 2. 自动识别 branch 参数名（优先名称含 branch，否则取第一个参数）
        $branchParamName = null;
        foreach ($allParams as $name => $choices) {
            if (stripos($name, 'branch') !== false) {
                $branchParamName = $name;
                break;
            }
        }
        if (!$branchParamName) {
            $names = array_keys($allParams);
            $branchParamName = $names[0] ?? null;
        }
        if (!$branchParamName) {
            return $this->jsonError($response, '无法识别分支参数名', 400);
        }

        // 3. 获取分支选项，如果为空则从 Git 平台补齐
        $branchOptions = $allParams[$branchParamName] ?? [];

        if (empty($branchOptions)) {
            try {
                $gitBranches = $this->git->getBranchesForJob($resolved['fullName']);
                if (!empty($gitBranches)) {
                    $branchOptions = $gitBranches;
                    $allParams[$branchParamName] = $gitBranches;
                }
            } catch (\Exception $e) {
                error_log("Git平台补齐分支失败: " . $e->getMessage());
            }
        }

        if (empty($branchOptions)) {
            return $this->jsonError($response, 'Jenkins未能提供可用分支列表，且 Git 平台也不可用', 400);
        }

        // 4. 智能适配 origin/ 前缀
        $actualBranchValue = $branchValue;
        if (!in_array($actualBranchValue, $branchOptions)) {
            $allHaveOrigin = true;
            foreach ($branchOptions as $opt) {
                if (strpos($opt, 'origin/') !== 0) {
                    $allHaveOrigin = false;
                    break;
                }
            }
            if ($allHaveOrigin) {
                $candidate = 'origin/' . $branchValue;
                if (in_array($candidate, $branchOptions)) {
                    $actualBranchValue = $candidate;
                }
            }
            if (!in_array($actualBranchValue, $branchOptions)) {
                $allowed = implode(', ', $branchOptions);
                return $this->jsonError($response, "无效的分支: $branchValue,可用值: $allowed", 400);
            }
        }

        // 5. 构造构建参数（使用原始参数名确保大小写一致）
        $buildParams = [$branchParamName => $actualBranchValue];

        // 6. 双参数处理
        $paramNames = array_keys($allParams);
        if (count($paramNames) === 2) {
            if (empty($zoneValue)) {
                return $this->jsonError($response, '缺少 zone 参数', 400);
            }
            $zoneParamName = ($paramNames[0] === $branchParamName) ? $paramNames[1] : $paramNames[0];
            $zoneOptions = $allParams[$zoneParamName] ?? [];
            if (!in_array($zoneValue, $zoneOptions)) {
                $allowed = implode(', ', $zoneOptions);
                return $this->jsonError($response, "无效的 zone: $zoneValue，可用值: $allowed", 400);
            }
            $buildParams[$zoneParamName] = $zoneValue;
        }

        // 7. 触发构建
        try {
            $result = $this->jenkins->triggerBuild($resolved['fullName'], $buildParams);
            $responseData = [
                'message'          => '构建触发成功',
                'job'              => $resolved['fullName'],
                'triggered_params' => $buildParams,
                'queue_id'         => $result['queueId'] ?? null,
                'queue_url'        => $result['queueUrl'] ?? null,
            ];
            $response->getBody()->write(json_encode($responseData));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            return $this->jsonError($response, '触发失败: ' . $e->getMessage(), 500);
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