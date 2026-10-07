<?php

namespace App\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Service\HarborService;
use App\Service\I18nService;
use App\Config\AppConfig;

class HarborController extends BaseController
{
    private HarborService $harbor;

    public function __construct(I18nService $i18n, HarborService $harbor)
    {
        parent::__construct($i18n);
        $this->harbor = $harbor;
    }

    /**
     * @param array<string,string> $args 路由参数
     */
    public function getProjectsList(Request $request, Response $response, array $args): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_BUILD_RECORDS_PULL)) {
            return $resp;
        }

        $result = $this->harbor->getProjects();
        return $this->handleResult($response, $result, $request);
    }

    /**
     * @param array<string,string> $args 路由参数
     */
    public function getRepositoriesList(Request $request, Response $response, array $args): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_BUILD_RECORDS_PULL)) {
            return $resp;
        }

        $project = $args['project'] ?? '';
        $result = $this->harbor->getRepositories($project);
        return $this->handleResult($response, $result, $request);
    }

    /**
     * @param array<string,string> $args 路由参数
     */
    public function getTagsList(Request $request, Response $response, array $args): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_BUILD_RECORDS_PULL)) {
            return $resp;
        }

        $project    = $args['project'] ?? '';
        $repository = $args['repository'] ?? '';
        $result = $this->harbor->getTags($project, $repository);
        return $this->handleResult($response, $result, $request);
    }

    /**
     * @param array<string,string> $args 路由参数
     */
    public function scanTrigger(Request $request, Response $response, array $args): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_TRIGGER)) {
            return $resp;
        }

        $project    = $args['project'] ?? '';
        $repository = $args['repository'] ?? '';
        $tag        = $args['tag'] ?? '';
        $result = $this->harbor->scanArtifact($project, $repository, $tag);

        if (isset($result['error']) && strpos($result['error'], '412') !== false) {
            return $this->jsonError($response, $this->__('harbor.scan_not_enabled'), 503);
        }

        if (isset($result['error']) && strpos($result['error'], '409') !== false) {
            return $this->jsonError($response, $this->__('harbor.scan_in_progress'), 409);
        }

        return $this->handleResult($response, $result, $request);
    }

    /**
     * @param array<string,string> $args 路由参数
     */
    public function getScanReport(Request $request, Response $response, array $args): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_BUILD_RECORDS_PULL)) {
            return $resp;
        }

        $project    = $args['project'] ?? '';
        $repository = $args['repository'] ?? '';
        $tag        = $args['tag'] ?? '';
        $result = $this->harbor->getScanReport($project, $repository, $tag);

        if (isset($result['error']) && strpos($result['error'], '412') !== false) {
            return $this->jsonError($response, $this->__('harbor.scan_report_not_enabled'), 503);
        }

        return $this->handleResult($response, $result, $request);
    }

    // ---------- 统一响应处理 ----------
    /**
     * @param array<int|string,mixed> $data Harbor 返回结果（键可能含整数）
     */
    private function handleResult(Response $response, array $data, Request $request): Response
    {
        if (isset($data['error'])) {
            return $this->jsonError($response, $data['error'], 500);
        }
        return $this->output($response, $data, $request);
    }
}
