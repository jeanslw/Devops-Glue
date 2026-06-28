<?php

namespace App\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Service\JenkinsService;

class JenkinsController
{
    private JenkinsService $jenkinsService;

    public function __construct(JenkinsService $jenkinsService)
    {
        $this->jenkinsService = $jenkinsService;
    }

private function parseJobPath(array $args): array
    {
        $group = $args['group'] ?? '';
        $project = $args['projest'] ?? ''; // 保持原路由拼写
        return [$group, $project];
    }

    /**
     * 返回 JSON 格式的错误响应
     */
    private function errorJson(Response $response, string $message, int $status = 400): Response
    {
        $payload = json_encode(['code' => $status, 'error' => $message], JSON_UNESCAPED_UNICODE);
        $response->getBody()->write($payload);
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }

    /**
     * 构建触发
     */
    public function triggerBuild(Request $request, Response $response, array $args): Response
    {
        // 1. 从 Body 获取 jobname（唯一来源）
        $bodyParams = $request->getParsedBody() ?? [];
        $jobname    = $bodyParams['jobname'] ?? '';

        // 2. 从 URL 只取 group、branches、zone
        $group    = $args['group'] ?? '';
        $branches = $args['branches'] ?? '';
        $zone     = $args['zone'] ?? '';

        // ==========================================
        // 基础非空拦截（合并到最前面，避免重复校验）
        // ==========================================
        if (empty($jobname))  return $this->errorJson($response, "缺少必要参数: jobname");
        if (empty($branches)) return $this->errorJson($response, "缺少必要参数: branches");
        if (empty($zone))     return $this->errorJson($response, "缺少必要参数: zone");

        // 3. 拼接完整路径
        $fullJobPath = $group ? "{$group}/{$jobname}" : $jobname;

        // ==========================================
        // 校验1: jobname 是否在 getJobsList() 列表中
        // 【修复】$this->jenkins -> $this->jenkinsService
        // ==========================================
        try {
            $jobsList = $this->jenkinsService->getJobsList();
            if (!in_array($jobname, $jobsList)) {
                return $this->errorJson($response, "校验失败: Job [{$jobname}] 不在 Jenkins Job 列表中");
            }
        } catch (\Exception $e) {
            return $this->errorJson($response, "获取 Job 列表失败: " . $e->getMessage(), 500);
        }

        // ==========================================
        // 获取参数列表
        // 【修复】$this->jenkins -> $this->jenkinsService
        // 【修复】$project -> $jobname （$project 变量已不存在！）
        // ==========================================
        try {
            $paramsList = $this->jenkinsService->getParametersList($group, $jobname, null);
            if (isset($paramsList['error'])) {
                return $this->errorJson($response, "获取参数列表失败: " . $paramsList['error'], 500);
            }
        } catch (\Exception $e) {
            return $this->errorJson($response, "获取参数列表异常: " . $e->getMessage(), 500);
        }

        // ==========================================
        // 校验2: branches 匹配与还原
        // ==========================================
        $jenkinsBranches = $paramsList['branches'] ?? [];
        $originalBranchValue = $branches;
        $branchMatched = false;

        foreach ($jenkinsBranches as $jb) {
            $cleanedJb = $jb;
            if (is_string($jb) && str_contains($jb, '/')) {
                $parts = explode('/', $jb);
                $cleanedJb = end($parts);
            }
            
            if ($cleanedJb === $branches) {
                $originalBranchValue = $jb;
                $branchMatched = true;
                break;
            }
        }

        if (!$branchMatched) {
            return $this->errorJson($response, "校验失败: 分支 [{$branches}] 不在可用列表中");
        }

        // ==========================================
        // 校验3: zone 匹配
        // ==========================================
        $jenkinsZones = $paramsList['zone'] ?? [];
        if (!in_array($zone, $jenkinsZones)) {
            return $this->errorJson($response, "校验失败: 区域 [{$zone}] 不在可用列表中");
        }

        // ==========================================
        // 校验全部通过，组装参数并触发构建！
        // 【修复】$this->jenkins -> $this->jenkinsService
        // 【修复】$project -> $jobname
        // ==========================================
        $urlParams = [
            'branches' => $originalBranchValue, 
            'zone'     => $zone
        ];

        try {
            $result = $this->jenkinsService->triggerBuild($group, $jobname, $urlParams, $bodyParams);
        } catch (\Exception $e) {
            return $this->errorJson($response, "触发构建异常: " . $e->getMessage(), 500);
        }

        if (isset($result['error'])) {
            return $this->errorJson($response, $result['error']);
        }

        return $this->withJson($response, $result);
    }
    
    // 2. 所有 Job 列表
    public function getJobsList(Request $request, Response $response, array $args): Response
    {
        return $this->withJson($response, $this->jenkinsService->getJobsList());
    }

    // 3. 构建状态
    public function getBuildStatus(Request $request, Response $response, array $args): Response
    {
        $result = $this->jenkinsService->getBuildStatus(
            $args['group'] ?? '', 
            $args['project'] ?? '', 
            (int)($args['build_id'] ?? 0)
        );
        return $this->withJson($response, $result);
    }

    // 4.5.6. 获取构建列表
    // Controller 层：统一的构建列表入口
    public function getBuildList(Request $request, Response $response, array $args): Response
    {
        $group = $args['group'] ?? '';
        $project = $args['project'] ?? '';
        $type = $args['type'] ?? 'build'; 

        $result = $this->jenkinsService->getBuildListByType($group, $project, $type);
        
        return $this->withJson($response, $result);
    }

    // 7. 获取分支列表
    public function getBranchesList(Request $request, Response $response, array $args): Response
    {
        $group = $args['group'] ?? '';
        $project = $args['project'] ?? '';
        
        $queryParams = $request->getQueryParams();
        $gitlabProjectId = $queryParams['gitlab_project_id'] ?? null;

        $result = $this->jenkinsService->getBranchList($group, $project, $gitlabProjectId);
        return $this->withJson($response, $result);
    }

    // 构建参数列表  
    public function getParametersList(Request $request, Response $response, array $args): Response
    {
        $group   = $args['group'] ?? '';
        $project = $args['project'] ?? ''; // 对应 projest
        
        // 【关键修改】严格区分 build_id 是留空、0 还是 >0
        $buildId = null;
        if (isset($args['build_id'])) {
            if (!is_numeric($args['build_id'])) {
                return $this->errorJson($response, "参数错误: build_id 必须是数字");
            }
            $buildId = (int)$args['build_id'];
            if ($buildId < 0) {
                return $this->errorJson($response, "参数错误: build_id 不能为负数");
            }
        }

        // 调用 Service，传入严格的 null, 0, 或 >0
        $result = $this->jenkinsService->getParametersList($group, $project, $buildId);

        // 错误处理
        if (isset($result['error'])) {
            return $this->errorJson($response, $result['error'], 500);
        }

        // 【兼容您原有的判空拦截逻辑】
        if (is_array($result) && empty($result)) {
            // 如果返回的是空数组（无论是 [] 还是 {}），替换为 "null"
            $result = 'null'; 
        }

        return $this->withJson($response, $result);
    }

    // 9. Job与Git映射
    public function getJobGitList(Request $request, Response $response, array $args): Response
    {
        return $this->withJson($response, $this->jenkinsService->getJobGitList());
    }

    // 10. 控制台日志 (注意：返回 HTML)
    public function getConsoleOutput(Request $request, Response $response, array $args): Response
    {
        $html = $this->jenkinsService->getConsoleOutput(
            $args['group'] ?? '', 
            $args['project'] ?? '', 
            (int)($args['build_id'] ?? 0)
        );
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * 封装 Slim 4 的 JSON 响应方法 (完美替代 Slim 3 的 withJson)
     */
    protected function withJson(Response $response, $data, int $status = 200): Response
    {
        // JSON_UNESCAPED_UNICODE 保证中文不乱码，JSON_UNESCAPED_SLASHES 保证 URL 里的斜杠不被转义
        $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $response->getBody()->write($payload);
        
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }

    // routes辅助方法
    protected function resolveGroupProject(array $args): array
    {
        $group = $args['group'] ?? '';
        $project = $args['project'] ?? '';
        if (empty($project) && !empty($group)) {
            $project = $group;
            $group = '';
        }
        return [$group, $project];
    }
}