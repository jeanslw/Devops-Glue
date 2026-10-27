<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Config\AppConfig;
use App\Service\ApiAccessLogRepository;
use App\Service\AppSettingRepository;
use App\Service\CustomBuildRepository;
use App\Service\DeployLogRepository;
use App\Service\I18nService;
use App\Service\OperationLogRepository;
use App\Service\SecurityCheckRepository;
use App\Service\Settings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * 后台审计与构建日志（安全扫描 / 自定义构建 / 操作日志 / 部署日志 / API 调用日志）。
 * 各数据源读写复用对应仓储：SecurityCheckRepository / CustomBuildRepository / ApiAccessLogRepository。
 */
class LogController extends BaseAdminController
{
    private ?DeployLogRepository $deployLog = null;
    private ApiAccessLogRepository $apiAccessLog;
    private SecurityCheckRepository $securityChecks;
    private CustomBuildRepository $customBuilds;

    public function __construct(
        I18nService $i18n,
        Settings $config,
        AppSettingRepository $appSettings,
        OperationLogRepository $operationLog,
        ApiAccessLogRepository $apiAccessLog,
        SecurityCheckRepository $securityChecks,
        CustomBuildRepository $customBuilds
    ) {
        parent::__construct($i18n, $config, $appSettings, $operationLog);
        $this->apiAccessLog   = $apiAccessLog;
        $this->securityChecks = $securityChecks;
        $this->customBuilds   = $customBuilds;
    }

    /** 部署日志仓储（懒加载，经 CD 只读端点拉取部署记录） */
    private function deployLog(): DeployLogRepository
    {
        if ($this->deployLog === null) {
            $cd = $this->config->getCdConfig();
            $this->deployLog = new DeployLogRepository($cd['base_url'] ?? '', $cd['api_token'] ?? '');
        }
        return $this->deployLog;
    }

    /**
     * GET /api/admin/security_checks — 安全扫描结果列表（支持筛选/分页）
     */
    public function securityChecksList(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_MANAGE)) {
            return $resp;
        }

        $params = array_merge(
            $request->getQueryParams(),
            $request->getParsedBody() ?? json_decode($request->getBody()->__toString(), true) ?? []
        );
        $filters = [
            'project'    => trim($params['project'] ?? ''),
            'check_type' => trim($params['check_type'] ?? ''),
            'state'      => trim($params['state'] ?? ''),
            'writeback'  => trim($params['writeback'] ?? ''),
            'exclude'    => trim($params['exclude'] ?? ''),
        ];
        $page    = max(1, (int)($params['page'] ?? 1));
        $perPage = max(1, min(100, (int)($params['per_page'] ?? 20)));

        try {
            return $this->output($response, $this->securityChecks->search($filters, $page, $perPage), $request);
        } catch (\Exception $e) {
            return $this->jsonError($response, $this->__('build.query_failed') . ': ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/admin/custom_builds — Custom_Push 构建记录列表（push 记录 Tab）
     */
    public function customBuildList(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_BUILD_RECORDS_PUSH)) {
            return $resp;
        }

        $params  = $request->getQueryParams();
        $page    = max(1, (int)($params['page'] ?? 1));
        $perPage = max(1, min(100, (int)($params['per_page'] ?? 20)));

        try {
            return $this->output($response, $this->customBuilds->list($page, $perPage), $request);
        } catch (\Exception $e) {
            return $this->jsonError($response, $this->__('push.load_failed') . ': ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/admin/operation_logs — 后台操作审计日志列表（分页 + 筛选）
     * 权限：ci.operation-logs
     */
    public function operationLogList(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_OPERATION_LOGS)) {
            return $resp;
        }

        $params  = $request->getQueryParams();
        $filters = [
            'username'      => trim($params['username'] ?? ''),
            'action'        => trim($params['action'] ?? ''),
            'result'        => trim($params['result'] ?? ''),
            'operator_type' => trim($params['operator_type'] ?? ''),
            'date_from'     => trim($params['date_from'] ?? ''),
            'date_to'       => trim($params['date_to'] ?? ''),
        ];
        $page    = max(1, (int)($params['page'] ?? 1));
        $perPage = max(1, min(100, (int)($params['per_page'] ?? 20)));

        try {
            return $this->output($response, $this->opLog()->list($filters, $page, $perPage), $request);
        } catch (\Exception $e) {
            return $this->jsonError($response, $this->__('build.query_failed') . ': ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/admin/deploy_logs — CD 部署记录审计列表（分页 + 筛选，只读）
     * 权限：ci.deploy-logs
     */
    public function deployLogList(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_DEPLOY_LOGS)) {
            return $resp;
        }

        $params  = $request->getQueryParams();
        $filters = [
            'project'     => trim($params['project'] ?? ''),
            'status'      => trim($params['status'] ?? ''),
            'deploy_type' => trim($params['deploy_type'] ?? ''),
            'date_from'   => trim($params['date_from'] ?? ''),
            'date_to'     => trim($params['date_to'] ?? ''),
        ];
        $page    = max(1, (int)($params['page'] ?? 1));
        $perPage = max(1, min(100, (int)($params['per_page'] ?? 20)));

        try {
            return $this->output($response, $this->deployLog()->list($filters, $page, $perPage), $request);
        } catch (\Exception $e) {
            return $this->jsonError($response, $this->__('build.query_failed') . ': ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/admin/api_access_logs — API token 调用审计列表（分页 + 筛选，只读）
     * 权限：ci.api-logs。只返回 token 展示名等审计字段，绝无 token 原文/hash/body/query。
     */
    public function apiAccessLogList(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_API_LOGS)) {
            return $resp;
        }

        $params  = $request->getQueryParams();
        $result  = trim((string)($params['result'] ?? ''));
        $method  = strtoupper(trim((string)($params['method'] ?? '')));
        $filters = [
            'result'     => in_array($result, ['success', 'failure', 'denied'], true) ? $result : '',
            'method'     => in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true) ? $method : '',
            'token_name' => trim((string)($params['token_name'] ?? '')),
            'route'      => trim((string)($params['route'] ?? '')),
            'date_from'  => trim((string)($params['date_from'] ?? '')),
            'date_to'    => trim((string)($params['date_to'] ?? '')),
        ];
        $page    = max(1, (int)($params['page'] ?? 1));
        $perPage = max(1, min(100, (int)($params['per_page'] ?? 20)));

        try {
            return $this->output($response, $this->apiAccessLog->list($filters, $page, $perPage), $request);
        } catch (\Exception $e) {
            return $this->jsonError($response, $this->__('build.query_failed') . ': ' . $e->getMessage(), 500);
        }
    }
}
