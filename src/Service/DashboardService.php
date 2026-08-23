<?php
namespace App\Service;

use App\Config\AppConfig;

/**
 * 监控看板只读查询服务（Grafana Infinity 数据源消费）。
 *
 * 链路：ci_job_git_map ↔ ci_pipeline_tags ↔ cd_deploy_logs ↔ cd_registry_artifacts
 *
 * 关键 join 语义（从代码反推的非显而易见坑，写 SQL 必须遵守）：
 *  - ci_pipeline_tags.project 是模糊匹配：落库时可能写 job_name 或 current_path，
 *    因此连接条件是 t.project IN (m.job_name, m.current_path)。
 *  - cd_deploy_logs.project 同理：dl.project IN (m.job_name, m.current_path)。
 *  - "project" 双关：ci_pipeline_tags.project / cd_deploy_logs.project 是 CI 项目名；
 *    cd_registry_repositories.project_name 是 Harbor 项目名。registry 段只能靠
 *    harbor_repository（"project/repo" 两段式）拼接 CONCAT(project_name,'/',repo_name) 连接，
 *    绝不能拿 project 去连 registry。
 *  - cd_registry_artifacts 一个 tag 可能多 digest（uk_repo_tag_digest），
 *    首版按 a.tag = t.tag 简单处理，用 MAX(push_time) 收敛脏数据。
 *  - cd_* 表由 CD 系统拥有，本服务只读；表不存在时优雅降级（对应字段返回 NULL），
 *    绝不让看板接口因为 CD 未部署而 500。
 */
class DashboardService
{
    private \PDO $pdo;
    private bool $isMysql;

    /** @var array<string,bool> 表存在性缓存（一次请求内只查一次） */
    private array $tableExistsCache = [];

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->isMysql = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql';
    }

    // ─────────────────────────── 对外查询 ───────────────────────────

    /**
     * 扁平映射条目列表（喂 Grafana Table / Stat 面板）。
     *
     * 每行 = 一个 job 映射 + 最新 pipeline tag + 最近一次部署 + 镜像 artifact 概览。
     *
     * @return array<int,array<string,mixed>>
     */
    public function getMapping(): array
    {
        $m  = AppConfig::TABLE_JOB_GIT_MAP;
        $t  = AppConfig::TABLE_PIPELINE_TAGS;
        $dl = 'cd_deploy_logs';
        $rr = 'cd_registry_repositories';
        $ra = 'cd_registry_artifacts';

        $hasDeploy   = $this->tableExists($dl);
        $hasRegistry = $this->tableExists($rr) && $this->tableExists($ra);

        // 最新 tag 子查询：每个 project 取 created_at 最新的一条（并列取 pipeline_iid 最大）
        $latestTag = "SELECT t1.* FROM {$t} t1
            INNER JOIN (
                SELECT project, MAX(created_at) AS max_created
                FROM {$t} GROUP BY project
            ) t2 ON t1.project = t2.project AND t1.created_at = t2.max_created
            WHERE NOT EXISTS (
                SELECT 1 FROM {$t} t3
                WHERE t3.project = t1.project AND t3.created_at = t1.created_at
                  AND t3.pipeline_iid > t1.pipeline_iid
            )";

        $sql = "SELECT
                m.job_name,
                m.git_platform,
                m.build_provider,
                m.git_remote,
                m.web_url,
                m.current_path,
                m.harbor_repository,
                m.status        AS map_status,
                t.pipeline_iid  AS latest_pipeline_iid,
                t.tag           AS latest_tag,
                t.status        AS latest_tag_status,
                t.created_at    AS latest_tag_at";

        if ($hasDeploy) {
            $sql .= ",
                dl.id           AS last_deploy_id,
                dl.tag          AS last_deploy_tag,
                dl.status       AS last_deploy_status,
                dl.env          AS last_deploy_env,
                dl.deployed_at  AS last_deployed_at";
        } else {
            $sql .= ",
                NULL AS last_deploy_id, NULL AS last_deploy_tag,
                NULL AS last_deploy_status, NULL AS last_deploy_env, NULL AS last_deployed_at";
        }

        if ($hasRegistry) {
            $sql .= ",
                ra.digest       AS artifact_digest,
                ra.push_time    AS artifact_push_time,
                ra.size         AS artifact_size";
        } else {
            $sql .= ",
                NULL AS artifact_digest, NULL AS artifact_push_time, NULL AS artifact_size";
        }

        $sql .= " FROM {$m} m
            LEFT JOIN ({$latestTag}) t
                ON t.project IN (m.job_name, m.current_path)";

        if ($hasDeploy) {
            // 最近一次部署：同 project 模糊匹配，取 deployed_at 最新
            $sql .= " LEFT JOIN {$dl} dl
                ON dl.project IN (m.job_name, m.current_path)
               AND dl.id = (
                   SELECT dl2.id FROM {$dl} dl2
                   WHERE dl2.project IN (m.job_name, m.current_path)
                   ORDER BY dl2.deployed_at DESC, dl2.id DESC
                   LIMIT 1
               )";
        }

        if ($hasRegistry) {
            // registry 段：harbor_repository = CONCAT(project_name,'/',repo_name)，
            // 一个 tag 多 digest 时取 push_time 最新的一条
            $concat = $this->isMysql
                ? "CONCAT(rr.project_name,'/',rr.repo_name)"
                : "(rr.project_name || '/' || rr.repo_name)";
            $sql .= " LEFT JOIN {$rr} rr
                ON m.harbor_repository <> '' AND {$concat} = m.harbor_repository
               LEFT JOIN {$ra} ra
                ON ra.repo_id = rr.id AND ra.tag = t.tag
               AND ra.push_time = (
                   SELECT MAX(ra2.push_time) FROM {$ra} ra2
                   WHERE ra2.repo_id = rr.id AND ra2.tag = t.tag
               )";
        }

        $sql .= " ORDER BY m.job_name";

        return $this->pdo->query($sql)->fetchAll();
    }

    /**
     * 时序聚合（喂 Grafana Time-series 面板）。
     *
     * 按天聚合：每日新增 tag 数、每日部署次数（成功/失败分列）。
     *
     * @param string $from Y-m-d（含），默认 30 天前
     * @param string $to   Y-m-d（含），默认今天
     * @return array{tags: array, deploys: array}
     */
    public function getTrends(string $from = '', string $to = ''): array
    {
        $to   = $this->sanitizeDate($to)   ?: date('Y-m-d');
        $from = $this->sanitizeDate($from) ?: date('Y-m-d', strtotime($to . ' -30 days'));

        $t  = AppConfig::TABLE_PIPELINE_TAGS;
        $dl = 'cd_deploy_logs';

        $dayT  = $this->dayExpr('created_at');
        $tags  = $this->pdo->query(
            "SELECT {$dayT} AS day, COUNT(*) AS cnt
             FROM {$t}
             WHERE created_at >= '{$from}' AND created_at < '{$to} 23:59:59'
             GROUP BY {$dayT} ORDER BY day"
        )->fetchAll();

        $deploys = [];
        if ($this->tableExists($dl)) {
            $dayD = $this->dayExpr('deployed_at');
            $deploys = $this->pdo->query(
                "SELECT {$dayD} AS day,
                        COUNT(*) AS total,
                        SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) AS success,
                        SUM(CASE WHEN status <> 'success' THEN 1 ELSE 0 END) AS failed
                 FROM {$dl}
                 WHERE deployed_at >= '{$from}' AND deployed_at < '{$to} 23:59:59'
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
        if ($d === '') return '';
        $dt = \DateTime::createFromFormat('!Y-m-d', $d);
        return ($dt && $dt->format('Y-m-d') === $d) ? $d : '';
    }
}
