<?php
namespace App\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Service\JenkinsService;
use App\Service\MapService;

class MainController
{
    private JenkinsService $jenkins;
    private MapService $map;

    public function __construct(JenkinsService $jenkins, MapService $map)
    {
        $this->jenkins = $jenkins;
        $this->map = $map;
    }

    /**
     * GET/POST /api/main/jobs/list
     * 返回所有 Job 名称列表
     */
    public function jobsList(Request $request, Response $response, array $args): Response
    {
        $jobs = $this->jenkins->getAllJobs();
        $response->getBody()->write(json_encode($jobs));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * GET/POST /api/main/map/list
     * 返回 Job ↔ Git 仓库、Harbor 映射关系
     */
    public function mapList(Request $request, Response $response, array $args): Response
    {
        $maps = $this->map->getAllMaps();
        $response->getBody()->write(json_encode($maps));
        return $response->withHeader('Content-Type', 'application/json');
    }
}