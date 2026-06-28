<?php

namespace App\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Service\JenkinsService;
use App\Service\GitService; 

class JenkinsController
{
    private JenkinsService $jenkinsService;
    private GitService $gitService; // 声明属性

    // 构造函数注入
    public function __construct(JenkinsService $jenkinsService, GitService $gitService)
    {
        $this->jenkinsService = $jenkinsService;
        $this->gitService = $gitService; 
    }

    private function errorJson(Response $response, string $message, int $status = 400): Response
    {
        $payload = json_encode(['code' => $status, 'error' => $message], JSON_UNESCAPED_UNICODE);
        $response->getBody()->write($payload);
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }

    // Controller 代码
    public function triggerBuild(Request $request, Response $response, array $args): Response
    {
        $bodyParams = $request->getParsedBody() ?: [];
        $jobName = $bodyParams['jobname'] ?? '';

        if (empty($jobName)) {
            // 1. 参数错误 (返回 400)
            $response = $response->withStatus(400);
            return $this->withJson($response, ['code' => 400, 'message' => '缺少 jobname 参数']);
        }

        try {
            // 2. 呼叫 Service
            $result = $this->jenkinsService->triggerBuild($jobName);
            
            // 3. 成功返回 (默认 200)
            return $this->withJson($response, ['code' => 200, 'data' => $result]);

        } catch (\Exception $e) {
            // 4. 失败返回 (返回 500)
            $response = $response->withStatus(500); // 先给 response 设置 500 状态码
            return $this->withJson($response, [
                'code' => 500, 
                'message' => '触发 Jenkins 失败', 
                'error_detail' => $e->getMessage()
            ]);
        }
    }
    
    public function getJobsList(Request $request, Response $response, array $args): Response
    {
        return $this->withJson($response, $this->jenkinsService->getJobsList());
    }

    public function getBuildStatus(Request $request, Response $response, array $args): Response
    {
        $result = $this->jenkinsService->getBuildStatus(
            $args['group'] ?? '', 
            $args['project'] ?? '', 
            (int)($args['build_id'] ?? 0)
        );
        return $this->withJson($response, $result);
    }

    public function getBuildList(Request $request, Response $response, array $args): Response
    {
        $group = $args['group'] ?? '';
        $project = $args['project'] ?? '';
        $type = $args['type'] ?? 'build'; 
        $result = $this->jenkinsService->getBuildListByType($group, $project, $type);
        return $this->withJson($response, $result);
    }

    public function getParametersList(Request $request, Response $response, array $args): Response
    {
        $group   = $args['group'] ?? '';
        $project = $args['project'] ?? ''; 
        
        $buildId = null;
        if (isset($args['build_id'])) {
            if (!is_numeric($args['build_id'])) return $this->errorJson($response, "参数错误: build_id 必须是数字");
            $buildId = (int)$args['build_id'];
            if ($buildId < 0) return $this->errorJson($response, "参数错误: build_id 不能为负数");
        }

        $result = $this->jenkinsService->getParametersList($group, $project, $buildId);

        if (isset($result['error'])) return $this->errorJson($response, $result['error'], 500);

        if (is_array($result) && empty($result)) $result = 'null'; 

        return $this->withJson($response, $result);
    }

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

    protected function withJson(Response $response, $data, int $status = 200): Response
    {
        $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $response->getBody()->write($payload);
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}