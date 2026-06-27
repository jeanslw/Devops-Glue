<?php
namespace App\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Service\HarborService;

class HarborController extends BaseController
{
    private HarborService $harbor;

    public function __construct(HarborService $harbor)
    {
        $this->harbor = $harbor;
    }

    /**
     * 获取项目列表
     * GET|POST /api/harbor/{projects}/list
     * 注意：路由中 {projects} 参数在此接口中不使用，仅为路由匹配占位
     * 输出：["library","mycode","toolkit"]
     */
    public function getProjectsList(Request $request, Response $response, array $args): Response
    {
        return $this->jsonResponse($response, $this->harbor->getProjects());
    }

    /**
     * 获取仓库列表
     * GET|POST /api/harbor/{projects}/{repository}/list
     * 注意：{repository} 在此接口中不使用，仅为路由匹配占位
     * 输出：["nginx","goharbor/chartmuseum-photon"]
     */
    public function getReposList(Request $request, Response $response, array $args): Response
    {
        return $this->jsonResponse($response, $this->harbor->getRepositories($args['project']));
    }

    /**
     * 获取Tags列表
     * GET|POST /api/harbor/{projects}/{repository}/tags/list
     * {repository} 可能包含 '/'，由路由 {repoPath:.+} 完整捕获
     * 输出：["v1.10.1","v1.10.0","v1.9.0"]
     */
    public function getTagsList(Request $request, Response $response, array $args): Response
    {
        return $this->jsonResponse($response, $this->harbor->getTags($args['project'], $args['repoPath']));
    }
}