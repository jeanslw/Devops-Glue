<?php

namespace App\Controller;

use App\Controller\Admin\BaseAdminController;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Config\AppConfig;
use App\Exceptions\ApiException;
use App\Service\ApiAccessLogRepository;
use App\Service\Registry\RegistryProviderInterface;
use App\Service\DeployLogRepository;
use App\Service\PasswordHasher;

class AdminController extends BaseAdminController
{
    private ?RegistryProviderInterface $registryProvider;
    private ?DeployLogRepository $deployLog = null;
    private ?ApiAccessLogRepository $apiAccessLog = null;

    public function __construct(
        \App\Service\I18nService $i18n,
        \App\Service\Settings $config,
        \PDO $pdo,
        \App\Service\AppSettingRepository $appSettings,
        ?RegistryProviderInterface $registryProvider = null
    ) {
        parent::__construct($i18n, $config, $pdo, $appSettings);
        $this->registryProvider   = $registryProvider;
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
     * POST /api/admin/login — 登录获取 token
     */


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

    // ────────────────────────── 用户管理 ──────────────────────────

    /**
     * GET /api/admin/users — 列出所有用户
     * - admin 可以看到全部用户（含 admin）
     * - 非 admin 看不到 admin 用户
     */














    // ────────────────────────── me/permissions ──────────────────────────

    /**
     * GET /api/admin/me/permissions — 用 token 查当前用户的权限列表
     * 不需要 admin 权限，只需要有效的 Bearer token
     * super_admin 返回 '*' 通配符表示拥有所有权限
     */

    // ────────────────────────── 操作日志 ──────────────────────────

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

    // ────────────────────────── 系统信息 ──────────────────────────

    /**
     * GET /api/admin/system_info — 数据库 schema 状态（驱动/版本/核心表存在性）
     * 权限：ci.system
     */
    public function systemInfo(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_SYSTEM)) {
            return $resp;
        }
        try {
            $status = \App\Service\Database::schemaStatus();
            $status['php_version'] = PHP_VERSION;
            return $this->output($response, $status, $request);
        } catch (\Exception $e) {
            return $this->jsonError($response, $this->__('build.query_failed') . ': ' . $e->getMessage(), 500);
        }
    }

    /**
     * 手动触发 tag 定时任务的前置校验（开关 + 镜像仓库），与两个 CLI 脚本的安全不变量保持一致。
     * 返回 null 表示可执行；否则返回应直接下发的错误响应。
     * 权限由调用方先行校验（ci.platform-config）。
     */
    private function guardTagJob(Response $response, bool $enabled, string $disabledKey): ?Response
    {
        if (!$enabled) {
            return $this->jsonError($response, $disabledKey, 409);
        }
        $registry = $this->config->getHarborConfig();
        if (empty($registry['url']) || $this->registryProvider === null) {
            return $this->jsonError($response, 'build.registry_unconfigured', 409);
        }
        return null;
    }

    /**
     * POST /api/admin/tag_cleanup — 后台手动立即执行一次清理过期 tag
     *（等价于跑一遍 cli/cleanup-pipeline-tags.php，供无 cron / 无 CLI 的部署主动触发）。
     * 权限：ci.platform-config（与启用清理过期 tag 记录开关同权限）。
     * 与 CLI 一致的校验：开关未开启返回 409；镜像仓库未配置（url 为空或提供方不可用）返回 409；
     * 镜像仓库（Harbor 或 registry:2）不可达以及 registry_repository 或 tag 为空的行一律跳过，不删除。幂等，可重复执行。
     */
    public function runTagCleanup(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_PLATFORM_CONFIG)) {
            return $resp;
        }
        if ($resp = $this->guardTagJob($response, $this->appSettings->getStaleTagCleanupEnabled(), 'build.tag_cleanup_disabled')) {
            return $resp;
        }

        @set_time_limit(0);
        try {
            $service = new \App\Service\PipelineTagService($this->pdo, $this->registryProvider);
            $stat    = $service->cleanupStaleTags();
            $this->opLog()->record($this->currentUser, 'cleanup_pipeline_tags', '', $stat, $this->clientIp($request), 'success');
            return $this->output($response, $stat + ['success' => true], $request);
        } catch (\Throwable $e) {
            if ($this->currentUser !== '') {
                try {
                    $this->opLog()->record($this->currentUser, 'cleanup_pipeline_tags', '', ['error' => $e->getMessage()], $this->clientIp($request), 'failure');
                } catch (\Throwable $ignored) {
                }
            }
            return $this->jsonError($response, $this->__('build.stale_tag_cleanup_failed') . ': ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/admin/tag_backfill — 后台手动立即执行一次镜像 Tag 日志回填
     *（等价于跑一遍 cli/backfill-pipeline-tags.php，供无 cron / 无 CLI 的部署主动触发）。
     * 权限：ci.platform-config（与启用镜像 Tag 日志回填开关同权限）。
     * 与 CLI 一致的校验：开关未开启返回 409；镜像仓库未配置（url 为空或提供方不可用）返回 409；
     * 仅当镜像仓库（Harbor 或 registry:2）确认该 tag 存在、且该 (provider,project_id,pipeline_iid) 尚无
     * canonical tag 时才写入，不覆盖 scan-sync 的结果。幂等，可重复执行。
     */
    public function runTagBackfill(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_PLATFORM_CONFIG)) {
            return $resp;
        }
        if ($resp = $this->guardTagJob($response, $this->appSettings->getBackfillTagEnabled(), 'build.tag_backfill_disabled')) {
            return $resp;
        }
        @set_time_limit(0);
        try {
            $service = new \App\Service\PipelineTagService($this->pdo, $this->registryProvider);
            $stat    = $service->backfillTagsFromBuildLog();
            $this->opLog()->record($this->currentUser, 'backfill_pipeline_tags', '', $stat, $this->clientIp($request), 'success');
            return $this->output($response, $stat + ['success' => true], $request);
        } catch (\Throwable $e) {
            if ($this->currentUser !== '') {
                try {
                    $this->opLog()->record($this->currentUser, 'backfill_pipeline_tags', '', ['error' => $e->getMessage()], $this->clientIp($request), 'failure');
                } catch (\Throwable $ignored) {
                }
            }
            return $this->jsonError($response, $this->__('build.tag_backfill_failed') . ': ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/admin/migrate — 手动同步库结构（建缺失表 + 补列 + 索引 + 种子 + 标记 schema 当前）
     *
     * 与 `cli/migrate.php` 等价的入口，区别是本入口用应用自身的数据库账号执行 DDL：
     * 该账号缺 CREATE/ALTER 权限时（手动建库模式的最小权限部署很常见）返回 409 并提示改用 CLI，
     * 而不是返回 500。
     * 权限：仅 super_admin（DDL 敏感操作）
     */
    public function migrateSchema(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requireSuperAdmin($response)) {
            return $resp;
        }
        try {
            $status = \App\Service\Database::migrateNow();
            $this->opLog()->record($this->currentUser, 'migrate_schema', '', ['driver' => $status['driver'], 'app_version' => $status['app_version']], $this->clientIp($request), 'success');
            return $this->output($response, ['success' => true, 'status' => $status], $request);
        } catch (\Exception $e) {
            $driverCode = $e instanceof \PDOException ? (int) ($e->errorInfo[1] ?? 0) : 0;
            $kind       = \App\Service\Database::classifyMigrationError(
                (string) $e->getMessage(),
                (string) $e->getCode(),
                $driverCode
            );
            $this->recordSchemaSyncFailure($request, $e->getMessage(), $kind);

            $tables = \App\Service\Database::lastTableBuildLog();
            if ($kind === 'ddl_denied') {
                return $this->jsonError(
                    $response,
                    $this->__('sys.migrate_denied') . ' ' . $this->__('sys.migrate_denied_hint')
                        . ' [' . mb_substr($e->getMessage(), 0, 300) . ']',
                    409,
                    ['tables' => $tables]
                );
            }
            return $this->jsonError($response, $this->__('sys.migrate_failed') . ': ' . $e->getMessage(), 500, ['tables' => $tables]);
        }
    }

    private function recordSchemaSyncFailure(Request $request, string $message, string $kind): void
    {
        if ($this->currentUser === '') {
            return;
        }
        try {
            $this->opLog()->record(
                $this->currentUser,
                'migrate_schema',
                '',
                ['error' => $message, 'kind' => $kind],
                $this->clientIp($request),
                'failure'
            );
        } catch (\Throwable $ignored) {
        }
    }

    /**
     * POST /api/admin/backup — 手动执行数据库备份，仅备份、不提供恢复。
     * 支持 sqlite / mysql 两种驱动，产物为 zip 归档，命名 devops-glue_<driver>_<datetime>.zip；
     * 启用 CD（共享库存在 cd_* 表）时额外产出一份 devops-cd_<driver>_<datetime>.zip。
     * 权限：仅 super_admin（与迁移同级，属敏感运维操作）。
     *
     * 备份目录：Docker 内 BACKUP_DIR=/data/backups（compose 卷映射宿主 ./data/backups）；
     * 非 Docker / 未设 BACKUP_DIR 时落仓库根 backups/。目录不存在时自动创建并滚动留存最近 10 份。
     * 备份逻辑由 DataBackupService 提供（bin/ 不随镜像发布，Web 侧不依赖 CLI 工具），
     * 完成后写入操作日志。
     */
    public function backupDatabase(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requireSuperAdmin($response)) {
            return $resp;
        }
        try {
            $service       = new \App\Service\DataBackupService($this->pdo);
            $files         = $service->backup(10);
            $warningKey    = $service->lastWarningKey();
            $warningParams = $service->lastWarningParams();

            $detail = ['driver' => $service->driver(), 'files' => count($files)];
            if ($warningKey !== '') {
                $detail['warning'] = $this->i18n->trans($warningKey, $warningParams);
            }
            $this->opLog()->record(
                $this->currentUser,
                'backup_database',
                implode(', ', array_column($files, 'name')),
                $detail,
                $this->clientIp($request),
                'success'
            );

            $out = [];
            foreach ($files as $f) {
                $out[] = [
                    'name'   => $f['name'],
                    'mode'   => $f['mode'],
                    'tables' => count($f['counts']),
                    'rows'   => array_sum($f['counts']),
                ];
            }

            return $this->output($response, [
                'success'  => true,
                'files'    => $out,

                'warnings' => $warningKey !== '' ? [['key' => $warningKey, 'params' => $warningParams]] : [],
            ], $request);
        } catch (\Throwable $e) {
            if ($this->currentUser !== '') {
                try {
                    $this->opLog()->record($this->currentUser, 'backup_database', '', ['error' => $e->getMessage()], $this->clientIp($request), 'failure');
                } catch (\Throwable $ignored) {
                }
            }
            return $this->jsonError($response, $this->__('sys.backup_failed') . ': ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/admin/backups — 列出已生成的数据库备份文件（供数据管理面板渲染）。
     * 权限：仅 super_admin（与备份同级）。
     */
    public function listBackups(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requireSuperAdmin($response)) {
            return $resp;
        }
        try {
            $service = new \App\Service\DataBackupService($this->pdo);
            return $this->output($response, ['files' => $service->listBackups()], $request);
        } catch (\Throwable $e) {
            return $this->jsonError($response, $this->__('sys.backup_failed') . ': ' . $e->getMessage(), 500);
        }
    }

    // ────────────────────────── helpers ──────────────────────────
}
