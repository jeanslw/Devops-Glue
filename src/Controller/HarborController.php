<?php
namespace App\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Service\HarborService;

class HarborController
{
    private HarborService $harbor;

    public function __construct(HarborService $harbor)
    {
        $this->harbor = $harbor;
    }

    /**
     * 获取项目列表
     * GET|POST /api/harbor/projects
     */
    public function getProjectsList(Request $request, Response $response, array $args): Response
    {
        $result = $this->harbor->getProjects();
        return $this->handleResult($response, $result);
    }

    /**
     * 获取仓库列表
     * GET|POST /api/harbor/{project}/repositories
     */
    public function getRepositoriesList(Request $request, Response $response, array $args): Response
    {
        $project = $args['project'] ?? '';
        $result = $this->harbor->getRepositories($project);
        return $this->handleResult($response, $result);
    }

    /**
     * 获取 Tag 列表
     * GET|POST /api/harbor/{project}/repositories/{repository}/tags
     */
    public function getTagsList(Request $request, Response $response, array $args): Response
    {
        $project    = $args['project'] ?? '';
        $repository = $args['repository'] ?? '';
        $result = $this->harbor->getTags($project, $repository);
        return $this->handleResult($response, $result);
    }

    /**
     * harbor触发扫描
     * POST /api/harbor/{project}/repositories/{repository}/tags/{tag}/scan
     */
    public function scanTrigger(Request $request, Response $response, array $args): Response
    {
        $project    = $args['project'] ?? '';
        $repository = $args['repository'] ?? '';
        $tag        = $args['tag'] ?? '';
        $result = $this->harbor->scanArtifact($project, $repository, $tag);
        
        // 如果 Harbor 返回 412，说明扫描器未启用
        if (isset($result['error']) && strpos($result['error'], '412') !== false) {
            return $this->jsonError($response, '镜像扫描功能未启用，请联系管理员', 503);
        }
        
        return $this->handleResult($response, $result);
    }

    /**
     * 扫描报告
     * GET /api/harbor/{project}/repositories/{repository}/tags/{tag}/scan
     */
    public function getScanReport(Request $request, Response $response, array $args): Response
    {
        $project    = $args['project'] ?? '';
        $repository = $args['repository'] ?? '';
        $tag        = $args['tag'] ?? '';
        $result = $this->harbor->getScanReport($project, $repository, $tag);
        
        if (isset($result['error']) && strpos($result['error'], '412') !== false) {
            return $this->jsonError($response, '镜像扫描功能未启用，无法获取报告', 503);
        }
        
        return $this->handleResult($response, $result);
    }

    // ---------- 统一响应处理 ----------
    private function handleResult(Response $response, array $data): Response
    {
        if (isset($data['error'])) {
            return $this->jsonError($response, $data['error'], 500);
        }
        return $this->jsonResponse($response, $data);
    }

    private function jsonResponse(Response $response, $data): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_SLASHES));
        return $response->withHeader('Content-Type', 'application/json');
    }

    private function jsonError(Response $response, string $msg, int $code = 400): Response
    {
        return $this->jsonResponse(
            $response->withStatus($code),
            ['code' => $code, 'message' => $msg]
        );
    }
}