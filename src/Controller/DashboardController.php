<?php

namespace App\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Service\DashboardService;
use App\Service\I18nService;
use App\Config\AppConfig;

/**
 * 监控看板只读 API（供 Grafana Infinity 数据源消费）。
 *
 * 设计约束：
 *  - 只读：不提供任何写端点；
 *  - 复用现有 RBAC：要求 ci.manage 权限（与映射查看同级）；
 *  - 不碰现有 controller、不碰 UI；
 *  - cd_* 表缺失时由 DashboardService 优雅降级，本层不感知。
 */
class DashboardController extends BaseController
{
    private DashboardService $dashboard;

    public function __construct(I18nService $i18n, DashboardService $dashboard)
    {
        parent::__construct($i18n);
        $this->dashboard = $dashboard;
    }

    /**
     * GET /api/dashboard/mapping
     * 扁平映射条目列表（喂 Table / Stat 面板）。
     */
    public function mapping(Request $request, Response $response, array $args): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_MANAGE)) {
            return $resp;
        }
        try {
            return $this->output($response, $this->dashboard->getMapping(), $request);
        } catch (\Throwable $e) {
            \App\Helper\Log::exception($e);
            return $this->jsonError($response, 'dashboard.query_failed', 500);
        }
    }


    /**
     * GET /api/dashboard/trends?from=YYYY-MM-DD&to=YYYY-MM-DD
     * 时序聚合（喂 Time-series 面板）。默认最近 30 天。
     */
    public function trends(Request $request, Response $response, array $args): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_MANAGE)) {
            return $resp;
        }
        $q = $request->getQueryParams();
        try {
            $data = $this->dashboard->getTrends(
                (string)($q['from'] ?? ''),
                (string)($q['to'] ?? '')
            );
            return $this->output($response, $data, $request);
        } catch (\Throwable $e) {
            \App\Helper\Log::exception($e);
            return $this->jsonError($response, 'dashboard.query_failed', 500);
        }
    }

    /**
     * GET /api/dashboard/deployment
     * 部署日志列表（只读 cd_deploy_logs，喂 Table / Stat 面板）。
     */
    public function deployment(Request $request, Response $response, array $args): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_MANAGE)) {
            return $resp;
        }
        try {
            return $this->output($response, $this->dashboard->getDeploymentData(), $request);
        } catch (\Throwable $e) {
            \App\Helper\Log::exception($e);
            return $this->jsonError($response, 'dashboard.query_failed', 500);
        }
    }

    /**
     * GET /api/dashboard/build
     * 构建数据：ci_custom_builds 字段 + jenkins/gitlab 实时流水线。
     */
    public function build(Request $request, Response $response, array $args): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_MANAGE)) {
            return $resp;
        }
        try {
            return $this->output($response, $this->dashboard->getBuildData(), $request);
        } catch (\Throwable $e) {
            \App\Helper\Log::exception($e);
            return $this->jsonError($response, 'dashboard.query_failed', 500);
        }
    }
}
