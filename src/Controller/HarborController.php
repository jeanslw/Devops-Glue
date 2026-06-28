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
     * 1. 获取项目列表
     * GET|POST /api/harbor/projects
     * 输出：["library","mycode","toolkit"]
     */
    public function getProjectsList(Request $request, Response $response, array $args): Response
    {
        $result = $this->harbor->getProjects();
        return $this->jsonResponse($response, $result);
    }

    /**
     * 2. 获取仓库列表
     * GET|POST /api/harbor/{project}/repositories
     * 输出：["nginx","goharbor/chartmuseum-photon"]
     */
    public function getRepositoriesList(Request $request, Response $response, array $args): Response
    {
        $project = $args['project'] ?? '';
        $result = $this->harbor->getRepositories($project);
        return $this->jsonResponse($response, $result);
    }

    /**
     * 3. 获取 Tags 列表
     * GET|POST /api/harbor/{project}/repositories/{repository}/tags
     * 注意：{repository} 可能包含 '/'，由路由完整捕获
     * 输出：["v1.10.1","v1.10.0","v1.9.0"]
     */
    public function getTagsList(Request $request, Response $response, array $args): Response
    {
        $project    = $args['project'] ?? '';
        // 修正：路由里定义的是 {repository}，这里必须对应取 repository
        $repository = $args['repository'] ?? ''; 
        
        $result = $this->harbor->getTags($project, $repository);
        return $this->jsonResponse($response, $result);
    }
}