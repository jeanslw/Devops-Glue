<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Config\AppConfig;
use App\Controller\Admin\BaseAdminController;
use App\Service\ApiAccessLogRepository;
use App\Service\DeployLogRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * 后台审计与构建日志（安全扫描 / 自定义构建 / 操作日志 / 部署日志 / API 调用日志）。
 * 方法体从 AdminController 原样搬迁。
 */
class LogController extends BaseAdminController
{
    private ?DeployLogRepository $deployLog = null;
    private ?ApiAccessLogRepository $apiAccessLog = null;

    /** 部署日志仓储（懒加载，经 CD 只读端点拉取部署记录） */
    private function deployLog(): DeployLogRepository
    {
        if ($this->deployLog === null) {
            $cd = $this->config->getCdConfig();
            $this->deployLog = new DeployLogRepository($cd['base_url'] ?? '', $cd['api_token'] ?? '');
        }
        return $this->deployLog;
    }

    /** API token 调用日志仓储（懒加载） */
    private function apiAccessLogRepo(): ApiAccessLogRepository
    {
        if ($this->apiAccessLog === null) {
            $this->apiAccessLog = new ApiAccessLogRepository($this->pdo);
        }
        return $this->apiAccessLog;
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
        $project   = trim($params['project'] ?? '');
        $checkType = trim($params['check_type'] ?? '');
        $state     = trim($params['state'] ?? '');
        $writeback = trim($params['writeback'] ?? '');
        $exclude   = trim($params['exclude'] ?? '');
        $page      = max(1, (int)($params['page'] ?? 1));
        $perPage   = max(1, min(100, (int)($params['per_page'] ?? 20)));

        try {
            $pdo = $this->pdo;

            $where = [];
            $bind  = [];
            if ($project !== '') {
                $where[] = 'project LIKE ?';
                $bind[]  = "%{$project}%";
            }
            if ($checkType !== '') {
                $where[] = 'check_type = ?';
                $bind[]  = $checkType;
            }
            if ($state !== '') {
                $where[] = 'state = ?';
                $bind[]  = $state;
            }
            if ($writeback !== '') {
                $where[] = 'writeback_status = ?';
                $bind[]  = $writeback;
            }
            if ($exclude !== '') {
                $excluded = array_filter(explode(',', $exclude), fn($s) => $s !== '');
                if (!empty($excluded)) {
                    $placeholders = implode(',', array_fill(0, count($excluded), '?'));
                    $where[] = "state NOT IN ({$placeholders})";
                    $bind = array_merge($bind, array_values($excluded));
                }
            }
            $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

            // 总数
            $countStmt = $pdo->prepare("SELECT count(*) FROM " . AppConfig::TABLE_SECURITY_CHECKS . " {$whereClause}");
            $countStmt->execute($bind);
            $total = (int)$countStmt->fetchColumn();

            $totalPages = max(1, (int)ceil($total / $perPage));
            $page = min($page, $totalPages);
            $offset = ($page - 1) * $perPage;

            // 分页查询
            $rows = $pdo->prepare(
                "SELECT * FROM " . AppConfig::TABLE_SECURITY_CHECKS . " {$whereClause} ORDER BY created_at DESC LIMIT {$perPage} OFFSET {$offset}"
            );
            $rows->execute($bind);
            $checks = $rows->fetchAll();

            // 可选筛选值
            $types = $pdo->query("SELECT DISTINCT check_type FROM " . AppConfig::TABLE_SECURITY_CHECKS . " ORDER BY check_type")->fetchAll(\PDO::FETCH_COLUMN);

            return $this->output($response, [
                'checks'      => $checks,
                'total'       => $total,
                'page'        => $page,
                'per_page'    => $perPage,
                'total_pages' => $totalPages,
                'filter_opts' => [
                    'check_types'       => $types,
                    'states'            => ['success', 'failed', 'pending', 'error'],
                    'writeback_statuses' => ['success', 'failed', 'skipped'],
                ],
            ], $request);
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
            $pdo = $this->pdo;

            $total = (int) $pdo->query("SELECT count(*) FROM " . AppConfig::TABLE_CUSTOM_BUILDS)->fetchColumn();

            $totalPages = max(1, (int) ceil($total / $perPage));
            $page = min($page, $totalPages);
            $offset = ($page - 1) * $perPage;

            $stmt = $pdo->prepare(
                'SELECT b.job_name, b.pipeline_iid, b.status, b.sha, b.variables_json, b.log_url, b.web_url, b.finished_at,
                        t.tag
                 FROM ' . AppConfig::TABLE_CUSTOM_BUILDS . ' b
                 LEFT JOIN ' . AppConfig::TABLE_PIPELINE_ARTIFACTS . ' t
                   ON t.project_key = b.job_name AND t.pipeline_iid = b.pipeline_iid AND t.provider = ?
                 ORDER BY b.finished_at DESC, b.pipeline_iid DESC
                 LIMIT ' . $perPage . ' OFFSET ' . $offset
            );
            $stmt->execute([AppConfig::PROVIDER_CUSTOM_PUSH]);
            $records = array_map(function (array $r): array {
                return [
                    'job_name'       => $r['job_name'] ?? '',
                    'pipeline_iid'   => (int) ($r['pipeline_iid'] ?? 0),
                    'status'         => $r['status'] ?? '',
                    'tag'            => $r['tag'] ?? '',
                    'sha'            => $r['sha'] ?? '',
                    'variables_json' => $r['variables_json'] ?? '',
                    'log_url'        => $r['log_url'] ?? '',
                    'web_url'        => $r['web_url'] ?? '',
                    'finished_at'    => $r['finished_at'] ?? '',
                ];
            }, $stmt->fetchAll());

            return $this->output($response, [
                'records'     => $records,
                'total'       => $total,
                'page'        => $page,
                'per_page'    => $perPage,
                'total_pages' => $totalPages,
            ], $request);
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
            return $this->output(
                $response,
                $this->apiAccessLogRepo()->list($filters, $page, $perPage),
                $request
            );
        } catch (\Exception $e) {
            return $this->jsonError($response, $this->__('build.query_failed') . ': ' . $e->getMessage(), 500);
        }
    }
}
