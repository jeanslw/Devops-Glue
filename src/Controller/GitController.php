<?php

namespace App\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Service\GitService;

class GitController
{
    private GitService $gitService;

    public function __construct(GitService $gitService)
    {
        $this->gitService = $gitService;
    }

    /**
     * 获取代码仓库分支列表
     */
    public function getBranchesList(Request $request, Response $response, array $args): Response
    {
        $group = $args['group'] ?? '';
        $project = $args['project'] ?? '';

        $result = $this->gitService->getBranchList($group, $project);
        return $this->withJson($response, $result);
    }

    /**
     * 获取 Job 与 Git 映射关系
     */
    public function getJobGitList(Request $request, Response $response, array $args): Response
    {
        $result = $this->gitService->getJobGitList();
        return $this->withJson($response, $result);
    }

    protected function withJson(Response $response, $data, int $status = 200): Response
    {
        $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $response->getBody()->write($payload);
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}