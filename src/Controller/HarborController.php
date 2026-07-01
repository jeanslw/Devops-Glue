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

    public function getProjectsList(Request $request, Response $response, array $args): Response
    {
        $result = $this->harbor->getProjects();
        return $this->handleResult($response, $result, $request);
    }

    public function getRepositoriesList(Request $request, Response $response, array $args): Response
    {
        $project = $args['project'] ?? '';
        $result = $this->harbor->getRepositories($project);
        return $this->handleResult($response, $result, $request);
    }

    public function getTagsList(Request $request, Response $response, array $args): Response
    {
        $project    = $args['project'] ?? '';
        $repository = $args['repository'] ?? '';
        $result = $this->harbor->getTags($project, $repository);
        return $this->handleResult($response, $result, $request);
    }

    public function scanTrigger(Request $request, Response $response, array $args): Response
    {
        $project    = $args['project'] ?? '';
        $repository = $args['repository'] ?? '';
        $tag        = $args['tag'] ?? '';
        $result = $this->harbor->scanArtifact($project, $repository, $tag);

        if (isset($result['error']) && strpos($result['error'], '412') !== false) {
            return $this->jsonError($response, '镜像扫描功能未启用，请联系管理员', 503);
        }

        return $this->handleResult($response, $result, $request);
    }

    public function getScanReport(Request $request, Response $response, array $args): Response
    {
        $project    = $args['project'] ?? '';
        $repository = $args['repository'] ?? '';
        $tag        = $args['tag'] ?? '';
        $result = $this->harbor->getScanReport($project, $repository, $tag);

        if (isset($result['error']) && strpos($result['error'], '412') !== false) {
            return $this->jsonError($response, '镜像扫描功能未启用，无法获取报告', 503);
        }

        return $this->handleResult($response, $result, $request);
    }

    // ---------- 统一响应处理 ----------
    private function handleResult(Response $response, array $data, Request $request): Response
    {
        if (isset($data['error'])) {
            return $this->jsonError($response, $data['error'], 500);
        }
        return $this->output($response, $data, $request);
    }
}