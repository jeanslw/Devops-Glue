<?php

namespace App\Service;

use App\Config\AppConfig;
use App\Service\Build\BuildProviderRegistry;

/**
 * 监控看板只读查询服务（Grafana Infinity 数据源消费）。
 *
 * 三个端点按「数据源」严格隔离，互不 join：
 *  - /api/dashboard/mapping     → 只读 ci_job_git_map（映射配置本身）
 *  - /api/dashboard/deployment  → 只读 cd_deploy_logs（CD 部署日志）
 *  - /api/dashboard/build       → ci_custom_builds（custom_push 构建）+ jenkins/gitlab 实时流水线
 *
 * 事实依据（字段名以真实建表脚本为准，禁止臆造）：
 *  - cd_deploy_logs（Devops_CD database/init_mysql.sql）：id, deploy_id, project, tag, image,
 *    deploy_type, target, status, output, triggered_by, deploy_note, duration_ms, stage_times, created_at。
 *    其中 deploy_note/duration_ms/stage_times 是 v1.3.1 迁移后补列，output/stage_times 为大文本/JSON，
 *    本服务只取「基础建表即有」的标量列，避免旧版 CD 缺列导致 SQL 报错。
 *  - ci_custom_builds（Glue database/*_init.sql）：id, job_name, pipeline_iid, ref, sha,
 *    variables_json, status, exit_code, log_url, web_url, triggered_at, started_at, finished_at。
 *
 * 降级约定：cd_deploy_logs 由 CD 系统拥有，Glue 不保证存在 → 表缺失返回空数组；
 * jenkins/gitlab 实时查询失败按 job 降级为 error 字段，绝不让看板整体 500。
 */
class DashboardService
{
    private \PDO $pdo;
    private bool $isMysql;
    private BuildProviderRegistry $buildRegistry;
    private MappingManager $mapping;

    /** @var array<string,bool> 表存在性缓存（一次请求内只查一次） */
    private array $tableExistsCache = [];

    public function __construct(\PDO $pdo, BuildProviderRegistry $buildRegistry, MappingManager $mapping)
    {
        $this->pdo = $pdo;
        $this->isMysql = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql';
        $this->buildRegistry = $buildRegistry;
        $this->mapping = $mapping;
    }

    // ─────────────────────────── 对外查询 ───────────────────────────

    /**
     * GET /api/dashboard/mapping —— 只输出 ci_job_git_map 字段。
     *
     * @return array<int,array<string,mixed>>
     */
    public function getMapping(): array
    {
        $m = AppConfig::TABLE_JOB_GIT_MAP;

        $sql = "SELECT
                m.job_name,
                m.git_platform,
                m.build_provider,
                m.project_id,
                m.git_remote,
                m.web_url,
                m.current_path,
                m.harbor_repository,
                m.status AS map_status
            FROM {$m} m
            WHERE m.status = 'active'
            ORDER BY m.job_name";

        return $this->pdo->query($sql)->fetchAll();
    }

    /**
     * GET /api/dashboard/deployment —— 只输出 cd_deploy_logs 字段。
     *
     * 只取基础建表即有的标量列（见类注释），排除 output/stage_times 大字段与 v1.3.1 迁移补列。
     *
     * @return array<int,array<string,mixed>>
     */
    public function getDeploymentData(): array
    {
        $dl = 'cd_deploy_logs';

        if (!$this->tableExists($dl)) {
            return [];
        }

        $sql = "SELECT
                dl.id,
                dl.deploy_id,
                dl.project,
                dl.tag,
                dl.image,
                dl.deploy_type,
                dl.target,
                dl.status,
                dl.triggered_by,
                dl.created_at
            FROM {$dl} dl
            ORDER BY dl.created_at DESC, dl.id DESC
            LIMIT 500";

        return $this->pdo->query($sql)->fetchAll();
    }

    /**
     * GET /api/dashboard/build —— ci_custom_builds 字段 + jenkins/gitlab 实时流水线。
     *
     * @return array{custom_builds: array, jenkins_gitlab: array}
     */
    public function getBuildData(): array
    {
        return [
            'custom_builds'  => $this->customBuilds(),
            'jenkins_gitlab' => $this->jenkinsGitlabPipelines(),
        ];
    }

    /**
     * GET /api/dashboard/trends —— 时序聚合（喂 Time-series 面板）。默认最近 30 天。
     *
     * 按天聚合：每日新增 tag 数、每日部署次数（成功/失败分列）。
     *
     * @return array{from: string, to: string, tags: array, deploys: array}
     */
    public function getTrends(string $from = '', string $to = ''): array
    {
        $to   = $this->sanitizeDate($to)   ?: date('Y-m-d');
        $from = $this->sanitizeDate($from) ?: date('Y-m-d', strtotime($to . ' -30 days'));

        $t  = AppConfig::TABLE_PIPELINE_TAGS;
        $dl = 'cd_deploy_logs';

        $dayT = $this->dayExpr('created_at');
        $tags = $this->pdo->query(
            "SELECT {$dayT} AS day, COUNT(*) AS cnt
             FROM {$t}
             WHERE created_at >= '{$from}' AND created_at < '{$to} 23:59:59'
             GROUP BY {$dayT} ORDER BY day"
        )->fetchAll();

        $deploys = [];
        if ($this->tableExists($dl)) {
            // cd_deploy_logs 无 deployed_at 列，真实时间列为 created_at（见类注释事实依据）
            $dayD = $this->dayExpr('created_at');
            $deploys = $this->pdo->query(
                "SELECT {$dayD} AS day,
                        COUNT(*) AS total,
                        SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) AS success,
                        SUM(CASE WHEN status <> 'success' THEN 1 ELSE 0 END) AS failed
                 FROM {$dl}
                 WHERE created_at >= '{$from}' AND created_at < '{$to} 23:59:59'
                 GROUP BY {$dayD} ORDER BY day"
            )->fetchAll();
        }

        return [
            'from'    => $from,
            'to'      => $to,
            'tags'    => $tags,
            'deploys' => $deploys,
        ];
    }

    // ─────────────────────────── 数据子查询 ───────────────────────────

    /** ci_custom_builds 全量（跨所有 job），按插入序倒排。 */
    private function customBuilds(): array
    {
        $cb = AppConfig::TABLE_CUSTOM_BUILDS;

        if (!$this->tableExists($cb)) {
            return [];
        }

        $sql = "SELECT
                cb.id,
                cb.job_name,
                cb.pipeline_iid,
                cb.ref,
                cb.sha,
                cb.status,
                cb.exit_code,
                cb.log_url,
                cb.web_url,
                cb.triggered_at,
                cb.started_at,
                cb.finished_at
            FROM {$cb} cb
            ORDER BY cb.id DESC
            LIMIT 500";

        return $this->pdo->query($sql)->fetchAll();
    }

    /**
     * jenkins / gitlab_ci 活跃映射的实时流水线列表。
     *
     * 只查外部 CI（jenkins、gitlab_ci）；custom_push 的构建在 ci_custom_builds，
     * 由 customBuilds() 覆盖，此处跳过。每个 job 独立 try/catch，单点失败降级为 error 字段。
     *
     * @return array<int,array<string,mixed>>
     */
    private function jenkinsGitlabPipelines(): array
    {
        $entries = [];
        foreach ($this->mapping->activeMaps() as $m) {
            $bp = $m['build_provider'] ?? AppConfig::PROVIDER_JENKINS;
            if ($bp !== AppConfig::PROVIDER_JENKINS && $bp !== AppConfig::PROVIDER_GITLAB_CI) {
                continue;
            }

            $job = (string) ($m['job_name'] ?? '');
            if ($job === '') {
                continue;
            }

            // resolveProject 已按 provider 归一化 projectId（gitlab→数字 id，jenkins→job 路径）
            $resolved  = $this->mapping->resolveProject($job);
            $provider  = $resolved['provider'];
            $projectId = $resolved['projectId'];
            if (!in_array($provider, [AppConfig::PROVIDER_JENKINS, AppConfig::PROVIDER_GITLAB_CI], true)) {
                continue;
            }

            if (!$this->buildRegistry->isRegistered($provider)) {
                $entries[] = [
                    'job_name'   => $job,
                    'provider'   => $provider,
                    'project_id' => $projectId,
                    'error'      => 'provider 未配置',
                ];
                continue;
            }

            try {
                $pipelines = $this->buildRegistry->create($provider)->getPipelines($projectId);
                $entries[] = [
                    'job_name'   => $job,
                    'provider'   => $provider,
                    'project_id' => $projectId,
                    'pipelines'  => $pipelines,
                ];
            } catch (\Throwable $e) {
                $entries[] = [
                    'job_name'   => $job,
                    'provider'   => $provider,
                    'project_id' => $projectId,
                    'error'      => $e->getMessage(),
                ];
            }
        }
        return $entries;
    }

    // ─────────────────────────── 内部工具 ───────────────────────────

    /** 表是否存在（cd_* 表由 CD 系统创建，Glue 不保证存在） */
    private function tableExists(string $table): bool
    {
        if (isset($this->tableExistsCache[$table])) {
            return $this->tableExistsCache[$table];
        }
        try {
            if ($this->isMysql) {
                $stmt = $this->pdo->prepare(
                    "SELECT 1 FROM information_schema.TABLES
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
                );
                $stmt->execute([$table]);
                $exists = (bool) $stmt->fetchColumn();
            } else {
                $stmt = $this->pdo->prepare(
                    "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?"
                );
                $stmt->execute([$table]);
                $exists = (bool) $stmt->fetchColumn();
            }
        } catch (\Throwable $e) {
            $exists = false;
        }
        return $this->tableExistsCache[$table] = $exists;
    }

    /** 跨驱动的「取日期部分」表达式 */
    private function dayExpr(string $column): string
    {
        return $this->isMysql ? "DATE({$column})" : "substr({$column}, 1, 10)";
    }

    /** 严格校验 Y-m-d，非法返回空串（防 SQL 注入，日期会内联进 SQL） */
    private function sanitizeDate(string $d): string
    {
        $d = trim($d);
        if ($d === '') {
            return '';
        }
        $dt = \DateTime::createFromFormat('!Y-m-d', $d);
        return ($dt && $dt->format('Y-m-d') === $d) ? $d : '';
    }
}
