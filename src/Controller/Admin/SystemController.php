<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Config\AppConfig;
use App\Service\DataBackupService;
use App\Service\PipelineTagService;
use App\Service\Registry\RegistryProviderInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * 后台系统运维（系统信息 / schema 迁移 / 数据库备份 / tag 任务手动触发）。
 * 方法体从 AdminController 原样搬迁；4 处内联 new（PipelineTagService / DataBackupService）改为构造注入。
 */
class SystemController extends BaseAdminController
{
    private DataBackupService $dataBackup;
    private PipelineTagService $pipelineTags;
    private ?RegistryProviderInterface $registryProvider;

    public function __construct(
        \App\Service\I18nService $i18n,
        \App\Service\Settings $config,
        \PDO $pdo,
        \App\Service\AppSettingRepository $appSettings,
        DataBackupService $dataBackup,
        PipelineTagService $pipelineTags,
        ?RegistryProviderInterface $registryProvider = null
    ) {
        parent::__construct($i18n, $config, $pdo, $appSettings);
        $this->dataBackup       = $dataBackup;
        $this->pipelineTags     = $pipelineTags;
        $this->registryProvider = $registryProvider;
    }

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
            $service = $this->pipelineTags;
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
            $service = $this->pipelineTags;
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
            $service       = $this->dataBackup;
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
            $service = $this->dataBackup;
            return $this->output($response, ['files' => $service->listBackups()], $request);
        } catch (\Throwable $e) {
            return $this->jsonError($response, $this->__('sys.backup_failed') . ': ' . $e->getMessage(), 500);
        }
    }
}
