<?php

namespace App\Service;

class Database
{
    private static ?\PDO $pdo = null;
    private static string $driver = 'sqlite';
    /** @var array<string,mixed> */
    private static array $config = [];
    private static bool $bootstrapped = false;

    /**
     * 内部访问器：返回「已引导」的连接。
     * 到达这里的路径（bootstrap()/getPdo() 之后）必然已赋值；
     * 若违反前置约定直接抛异常，而不是在 null 上调用方法报模糊 fatal。
     */
    private static function pdo(): \PDO
    {
        if (self::$pdo === null) {
            throw new \RuntimeException('Database not bootstrapped: call Database::getPdo() or bootstrap() first');
        }
        return self::$pdo;
    }

    /** ci_app_settings 中记录「已应用的 schema 版本」的 key（/healthz 探针经 schemaState() 读取，故保持公开） */
    public const SCHEMA_VERSION_KEY = 'schema_version';

    /**
     * 手动建库模式（DB_AUTO_MIGRATE=false）下记录「种子已应用版本」的 key。
     *
     * 刻意与 SCHEMA_VERSION_KEY 分开：该模式的 DDL（含存量库补列）由运维负责
     * （database/*.sql 只有 CREATE TABLE IF NOT EXISTS，对已有表是空操作），
     * 若复用 schema_version 会把「种子已补齐」谎报成「表结构已是最新」。
     */
    private const SEED_VERSION_KEY = 'seed_version';

    /** 播种模式：按当前代码定义收敛——清掉系统角色的多余映射（正常升级、显式 cli/migrate.php / 按钮走这条） */
    private const SEED_MODE_PRUNE = 'prune';

    /**
     * 播种模式：只补不删——保留新版本授予的映射，同时补上当前代码期望的映射。
     * 专用于**降级回滚**：避免"回滚顺手把系统角色权限收窄"这类意外（可用显式迁移收敛回 prune 语义）。
     */
    private const SEED_MODE_MERGE = 'merge';

    /** 操作日志中记录「版本跃迁」的三个动作名；i18n 键分别为 oplog.act_schema_init / _upgrade / _downgrade */
    private const ACTION_SCHEMA_INIT      = 'schema_init';
    private const ACTION_SCHEMA_UPGRADE   = 'schema_upgrade';
    private const ACTION_SCHEMA_DOWNGRADE = 'schema_downgrade';

    /** 操作日志中记录「RBAC 种子部分失败」的动作名；i18n 键为 oplog.act_rbac_seed_failed */
    private const ACTION_RBAC_SEED_FAILED = 'rbac_seed_failed';

    /**
     * 系统级审计（版本跃迁、种子失败等）在操作日志里的去重时间窗（秒）。
     *
     * 这些记录来自**自动路径**（引导 / 建表 / 播种），多进程同时启动、或条件持续不满足时每个请求
     * 都会重试一次：同 (action, target) 在窗口内只记一条，避免把审计表刷爆；窗口外再次发生是真事件，照记。
     */
    private const SYSTEM_AUDIT_DEDUPE_WINDOW = 600;

    // ── 初始化 ──

    /**
     * @param array<string,mixed>|null $config
     */
    public static function init(array $config = null): void
    {
        self::$config = $config ?? self::defaultConfig();
        self::$driver = self::$config['driver'] ?? 'sqlite';
    }

    /**
     * 数据库引导入口：建表（可选）+ 种子数据 + 自检，幂等。
     *
     * 职责边界：只管「初始化」，不管连接（连接由 createPdo() 负责）。
     * 想一次拿齐「连接 + 初始化」用 getPdo()；只有需拆分二者的场景（如 restore 分阶段）才单独调本方法。
     */
    public static function bootstrap(\PDO $pdo): void
    {
        // 进程内幂等守卫：显式重复调用（容器 + 业务代码、CLI 分阶段等）直接短路，
        // 避免重复跑 seedAdmin/verifySeed 的写库与校验开销。
        // 仅「全部成功」后才置位；中途抛异常（如 verifySeed 失败）保持 false，下次调用可重试自愈。
        if (self::$bootstrapped) {
            return;
        }
        self::$pdo = $pdo;
        if (empty(self::$config)) {
            self::init();
        }
        // 一次读取同时判定「是否当前版本」与「是否降级回滚」：后者决定播种模式，无需为此多跑一次查询。
        $needMark     = false;
        $needSeedMark = false;
        $seedMode     = self::SEED_MODE_PRUNE;
        $recorded     = null; // 跃迁前的记录值（null = 首次初始化），供跃迁留痕使用
        if (self::$config['auto_migrate'] ?? true) {
            // 仅在 schema/种子版本与当前代码版本不一致时执行建表 + 种子，
            // 避免每个请求都重复跑 ensureTables/seedRbac 的写库操作。
            $recorded = self::recordedVersion(self::SCHEMA_VERSION_KEY);
            if (!self::isVersionCurrent($recorded)) {
                // 降级（记录版本 > 代码版本）走「合并播种」：只补不删、保留新版本授予的权限映射，
                // 避免"回滚顺手把系统角色权限收窄"；显式 cli/migrate.php / 按钮仍走 prune 收敛。
                $seedMode = self::isVersionAhead($recorded) ? self::SEED_MODE_MERGE : self::SEED_MODE_PRUNE;
                self::ensureTables($seedMode); // 建表 + RBAC 种子 + 索引 + JSON 迁移
                $needMark = true;
            }
        } else {
            // 手动建库脚本模式（DB_AUTO_MIGRATE=false）：表由运维脚本创建，应用只补种子。
            // 用独立 key（seed_version）短路，避免 php-fpm 每请求重复跑 seedRbac 的写库；
            // 不复用 schema_version —— 本模式 DDL（含存量库补列）归运维，标「schema 当前」
            // 会谎报表结构已升级。需要补表/补列请跑 cli/migrate.php 或后台「同步库结构」。
            $recorded = self::recordedVersion(self::SEED_VERSION_KEY);
            if (!self::isVersionCurrent($recorded)) {
                $seedMode = self::isVersionAhead($recorded) ? self::SEED_MODE_MERGE : self::SEED_MODE_PRUNE;
                self::seedRbac($seedMode); // 表已存在，补/同步种子数据
                $needSeedMark = true;
            }
        }
        self::seedAdmin();
        self::verifySeed();
        // 自检通过后才标记版本，并留一条「版本跃迁」审计（升级 / 降级 / 首次初始化共用一条通道）：
        // 若自检抛异常则版本号保持旧值、审计也不写，下次引导重新自愈，不会留下"已跃迁"的假象。
        if ($needMark) {
            self::markSchemaCurrent();
            self::noteVersionTransition(self::SCHEMA_VERSION_KEY, $recorded, $seedMode, 'schema');
        }
        if ($needSeedMark) {
            self::markSeedCurrent();
            self::noteVersionTransition(self::SEED_VERSION_KEY, $recorded, $seedMode, 'seed');
        }
        self::$bootstrapped = true;
    }

    /**
     * 读取某个版本键的已记录值（`ci_app_settings` 缺表/缺键 → null）。
     *
     * bootstrap() 用**一次读取**同时判定「是否当前版本」与「是否降级回滚」，故本方法只取值、不做判断，
     * 避免为了判方向再多跑一次查询。
     */
    private static function recordedVersion(string $key): ?string
    {
        try {
            $stmt = self::pdo()->prepare(
                'SELECT value FROM ' . \App\Config\AppConfig::TABLE_APP_SETTINGS . ' WHERE setting_key = ?'
            );
            $stmt->execute([$key]);
            $row = $stmt->fetchColumn();
            return ($row === false) ? null : (string) $row;
        } catch (\Throwable $e) {
            return null; // ci_app_settings 表尚未创建 → 视为未记录
        }
    }

    /** 已记录版本是否就是当前代码版本（未记录或不同 → false：需要建表 / 补种子） */
    private static function isVersionCurrent(?string $recorded): bool
    {
        return $recorded === \App\Config\AppConfig::APP_VERSION;
    }

    /** 记录「表结构已应用到当前代码版本」（跃迁留痕由 bootstrap() 在标记写完后统一处理） */
    private static function markSchemaCurrent(): void
    {
        $sql = self::sqlUpsert(
            \App\Config\AppConfig::TABLE_APP_SETTINGS,
            'setting_key, value, updated_at',
            '?, ?, ' . self::sqlNow()
        );
        self::pdo()->prepare($sql)->execute([self::SCHEMA_VERSION_KEY, \App\Config\AppConfig::APP_VERSION]);
    }

    /** 记录手动建库模式下「当前代码版本的种子已应用」（独立 key，不影响系统信息面板的 schema 版本展示） */
    private static function markSeedCurrent(): void
    {
        $sql = self::sqlUpsert(
            \App\Config\AppConfig::TABLE_APP_SETTINGS,
            'setting_key, value, updated_at',
            '?, ?, ' . self::sqlNow()
        );
        self::pdo()->prepare($sql)->execute([self::SEED_VERSION_KEY, \App\Config\AppConfig::APP_VERSION]);
    }

    /**
     * 版本跃迁留痕（由 bootstrap() 在标记写完后调用，一次跃迁一次）——「升级 / 降级 / 首次初始化」共用一条通道。
     *
     * 两件事：
     *  1. **降级**→ 额外写一条文件 WARNING（这是异常，需要人关注；含合并播种与收敛办法）；
     *  2. **一律写操作日志**（`ci_operation_logs`）：文件日志按天轮转，而审计表可在后台
     *     「日志中心 → 操作日志」按操作名筛选，也是 cron / CLI 等无前端场景唯一 web 可见的途径
     *     （约定与 `cli/backup-db.php` 的系统级事件一致：username=`system`、operator_type=`system`）。
     *
     * 显式路径（后台「同步库结构」按钮、`cli/migrate.php`）**不走这里**——它们记录的是「操作」本身
     * （`migrate_schema`）；降级方向的显式迁移由 noteExplicitDowngrade() 单独留痕。本方法只覆盖
     * 「没有人工操作」的自动引导副作用。
     *
     * @param string      $key      被改写的版本键（schema_version / seed_version）
     * @param string|null $before   跃迁前的记录值；null = 此前无记录（首次初始化）
     * @param string      $seedMode 本次播种模式（merge 时提示"未撤销新版本授予的权限"）
     * @param string      $scope    'schema'（结构+种子）/ 'seed'（手动建库模式，仅补种子）
     */
    private static function noteVersionTransition(string $key, ?string $before, string $seedMode, string $scope): void
    {
        $isDowngrade = $before !== null && self::isVersionAhead($before);
        $action      = $isDowngrade
            ? self::ACTION_SCHEMA_DOWNGRADE
            : ($before === null ? self::ACTION_SCHEMA_INIT : self::ACTION_SCHEMA_UPGRADE);

        if ($isDowngrade) {
            try {
                \App\Helper\Log::warning(
                    $seedMode === self::SEED_MODE_MERGE
                        ? '检测到数据库版本高于当前代码版本（疑似代码降级回滚）：本次按「合并模式」补种子——'
                          . '补齐当前代码期望的权限映射，但保留新版本授予的映射（不撤销）。'
                          . '若将长期停留在旧版本，请执行 php cli/migrate.php 按旧定义收敛权限。'
                        : '检测到数据库版本高于当前代码版本（疑似代码降级回滚），本次将把版本标记改写为当前代码版本。',
                    [
                        'key'       => $key,
                        'previous'  => $before,
                        'current'   => \App\Config\AppConfig::APP_VERSION,
                        'seed_mode' => $seedMode,
                    ]
                );
            } catch (\Throwable $e) {
                // 纯诊断信息，失败不影响迁移
            }
        }

        self::recordVersionTransition($action, $key, $before, $seedMode, $scope, $isDowngrade ? 'failure' : 'success');
    }

    /**
     * 把一次版本跃迁写进操作日志（`ci_operation_logs`）。
     *
     * 结果：首次初始化 / 升级 → success；降级回滚 → failure（"检测到异常"不是成功操作，界面渲染 ❌、
     * 也便于按结果筛选）。`detail` 带 `scope`（schema=结构+种子 / seed=仅种子）与 `seed_mode`（merge/prune），
     * 便于事后审计"当时权限是怎么处理的"。
     */
    private static function recordVersionTransition(
        string $action,
        string $key,
        ?string $before,
        string $seedMode,
        string $scope,
        string $result
    ): void {
        self::recordSystemAudit($action, $before ?? '', [
            'key'       => $key,
            'scope'     => $scope,
            'previous'  => $before,
            'current'   => \App\Config\AppConfig::APP_VERSION,
            'seed_mode' => $seedMode,
            'channel'   => 'bootstrap',
        ], $result, 'bootstrap');
    }

    /**
     * 写一条「系统级」操作日志（操作人 = system），按 (action, target, channel) 在 SYSTEM_AUDIT_DEDUPE_WINDOW
     * 秒内去重；审计失败绝不影响主流程（引导 / 迁移必须照常进行）。
     *
     * channel 参与去重键的原因：同一回滚事件里「自动引导降级」（merge，保留新版本权限）与「显式
     * 迁移降级」（prune，收敛到旧定义）是**后果不同**的两类事件——若共用 (action, target) 去重，
     * 先发生的自动引导行会把随后按钮 / CLI 的显式降级审计吞掉，后台就再也查不到「权限被 prune
     * 收敛」这个事实。channel 同时写入 detail，便于区分来源；null = 不区分（按旧行为去重）。
     *
     * @param array<string,mixed> $detail
     */
    private static function recordSystemAudit(string $action, string $target, array $detail, string $result, ?string $channel = null): void
    {
        try {
            $pdo   = self::pdo();
            $since = date('Y-m-d H:i:s', time() - self::SYSTEM_AUDIT_DEDUPE_WINDOW);

            $sql    = 'SELECT 1 FROM ' . \App\Config\AppConfig::TABLE_OPERATION_LOGS
                . ' WHERE action = ? AND target = ? AND created_at >= ?';
            $params = [$action, $target, $since];
            if ($channel !== null) {
                $sql     .= ' AND detail LIKE ?';
                $params[] = '%"channel":"' . $channel . '"%';
            }
            $exists = $pdo->prepare($sql);
            $exists->execute($params);
            if ($exists->fetchColumn() !== false) {
                return; // 去重窗口内已记录过同类事件
            }

            (new OperationLogRepository($pdo))->record('system', $action, $target, $detail, '', $result, 'system');
        } catch (\Throwable $e) {
            // 审计写入失败绝不能影响引导 / 迁移
        }
    }

    /**
     * 显式迁移（后台「同步库结构」按钮、`cli/migrate.php`）在**降级方向**的留痕。
     *
     * 触发场景：回滚后按 SOP 用旧代码执行迁移对齐标记——此时库里的版本标记高于当前代码版本，
     * migrateNow() 会把它改写回旧版本，并按旧定义 prune 收敛系统角色权限。这是人为决策而非故障，
     * 但「方向」必须可见：与自动引导的降级同一待遇（文件 WARNING + `schema_downgrade` 审计行，
     * `detail.channel=explicit_migrate` 区分来源）。否则操作日志里只有一条「migrate_schema 成功」，
     * 无法回答「标记是什么时候、被哪个动作拉回旧版本的」。
     *
     * 聚合为**一次事件一行**：`schema_version` 与 `seed_version` 可能同时领先（也可能是其中之一），
     * 逐 key 记会被审计去重窗合并成一行且丢掉另一个 key；故先挑出所有领先的 key，
     * 文件 WARNING 带 per-key 版本、审计行 `detail.keys` 列出全部涉及的 key、`target` 取最高版本。
     *
     * 与 `migrate_schema` 操作行的关系：操作行记「做了什么」，本行记「方向异常」——降级方向的一次
     * 显式迁移因此会有两行（action 不同、语义不同）；去重窗口保证同一事件簇不会刷屏。
     *
     * @param array<string,string|null> $recorded key => 改写前的记录值（null = 此前无记录）
     */
    private static function noteExplicitDowngrade(array $recorded): void
    {
        $ahead = array_filter($recorded, static function ($v): bool {
            return $v !== null && self::isVersionAhead($v);
        });
        if ($ahead === []) {
            return; // 正向 / 平级 / 首次初始化：无方向异常，不留痕
        }
        \App\Helper\Log::warning(
            '检测到数据库版本高于当前代码版本（疑似代码降级回滚）：本次显式迁移将把「'
            . implode('、', array_keys($ahead)) . '」标记改写为当前代码版本，'
            . '并按当前（旧）代码定义收敛系统角色权限（prune）。',
            [
                'previous' => $ahead, // per-key 的改写前值
                'current'  => \App\Config\AppConfig::APP_VERSION,
                'channel'  => 'explicit_migrate',
            ]
        );
        // target 取「最高被降级版本」：既是去重键，也直观回答「从哪个版本被拉回来」
        $maxAhead = array_reduce(
            $ahead,
            static function (string $carry, string $v): string {
                return version_compare($v, $carry, '>') ? $v : $carry;
            },
            (string) reset($ahead)
        );
        self::recordSystemAudit(
            self::ACTION_SCHEMA_DOWNGRADE,
            $maxAhead,
            [
                'keys'      => array_keys($ahead),
                'previous'  => $maxAhead,
                'current'   => \App\Config\AppConfig::APP_VERSION,
                'seed_mode' => self::SEED_MODE_PRUNE,
                'channel'   => 'explicit_migrate',
            ],
            'failure',
            'explicit_migrate'
        );
    }

    /**
     * 当前代码版本定义的全部数据表（系统信息面板按此清单逐表探测存在性）
     *
     * @return list<string>
     */
    private static function schemaTables(): array
    {
        return [
            \App\Config\AppConfig::TABLE_JOB_GIT_MAP,
            \App\Config\AppConfig::TABLE_PIPELINE_ARTIFACTS,
            \App\Config\AppConfig::TABLE_PIPELINE_BUILD_LOG,
            \App\Config\AppConfig::TABLE_CUSTOM_BUILDS,
            \App\Config\AppConfig::TABLE_SECURITY_CHECKS,
            \App\Config\AppConfig::TABLE_ADMIN_USERS,
            \App\Config\AppConfig::TABLE_PLATFORM_VERSIONS,
            \App\Config\AppConfig::TABLE_APP_SETTINGS,
            \App\Config\AppConfig::TABLE_CACHE,
            \App\Config\AppConfig::TABLE_ROLES,
            \App\Config\AppConfig::TABLE_PERMISSIONS,
            \App\Config\AppConfig::TABLE_ROLE_PERMISSIONS,
            \App\Config\AppConfig::TABLE_IMPLIED_RULES,
            \App\Config\AppConfig::TABLE_API_TOKENS,
            \App\Config\AppConfig::TABLE_USER_IDENTITIES,
            \App\Config\AppConfig::TABLE_OPERATION_LOGS,
        ];
    }

    /**
     * 系统信息：DB 驱动 + schema 版本 + 各核心表存在性（供后台「系统信息」面板只读查询）
     *
     * @return array{driver:string, schema_version:string|null, app_version:string, is_current:bool, auto_migrate:bool, tables:array<string,bool>}
     */
    public static function schemaStatus(): array
    {
        $status = [];
        foreach (self::schemaTables() as $t) {
            $status[$t] = self::tableExists(self::pdo(), $t);
        }
        $recorded = null;
        try {
            $stmt = self::pdo()->prepare(
                "SELECT value FROM " . \App\Config\AppConfig::TABLE_APP_SETTINGS . " WHERE setting_key = ?"
            );
            $stmt->execute([self::SCHEMA_VERSION_KEY]);
            $row = $stmt->fetchColumn();
            $recorded = ($row === false) ? null : (string) $row;
        } catch (\Throwable $e) {
            $recorded = null;
        }
        return [
            'driver'         => self::$driver,
            'schema_version' => $recorded,
            'app_version'    => \App\Config\AppConfig::APP_VERSION,
            'is_current'     => ($recorded !== null && $recorded === \App\Config\AppConfig::APP_VERSION),
            // 迁移模式（DB_AUTO_MIGRATE 实际生效值）：后台「系统信息 → 迁移模式」行按此渲染自动/手动语义
            'auto_migrate'   => self::isAutoMigrateEnabled(),
            'tables'         => $status,
        ];
    }

    /**
     * 读取「已应用的 schema / 种子版本」并判定是否对齐当前代码（供 /healthz 探针一次查询拿到全部版本语义）。
     *
     * 与 schemaStatus() 的分工：本方法**不建连、不 bootstrap**（PDO 由调用方传入），且把「是否对齐」的
     * 判定集中在此，避免探针里散落版本规则。ci_app_settings 缺失时本方法会抛异常，由调用方判定「库不可用」。
     *
     * 判定规则（schema_current 只反映**表结构**是否对齐，不看 seed_version）：
     *  - 有 schema_version → 与 APP_VERSION 比较（schema_version 只由执行过 DDL 的路径写入：自动模式
     *    ensureTables 成功，或手动模式跑过 cli/migrate.php / 后台「同步库结构」）；
     *  - 无 schema_version → null（未知）。DB_AUTO_MIGRATE=false 的常态即是如此：seed_version 仅证明
     *    RBAC 种子数据已播（INSERT），不证明业务表的新增列已就绪——手动模式升级漏跑 DDL 时种子照样
     *    能补齐，若据此判 true 会把「新代码 + 旧表结构」谎报成健康，故留作「未知」而非「已对齐」。
     *  - seed_version 仍在返回体给出（用于观测/排障；其「避免重复播种」的短路逻辑在 bootstrap() 内）。
     *
     * `schema_ahead` 是**方向位**（schema_current 只做等值比较，不区分方向）：
     *  - true  ⇒ `schema_version` **高于** `APP_VERSION`：库比代码新，典型场景是**代码降级/回滚**
     *    （旧代码 + 新库）；
     *  - false ⇒ 无记录，或记录等于/低于当前代码版本。
     * 与 schema_current 组合即可区分两种"未对齐"，因为二者处置完全不同：
     *  - `schema_current=false` + `schema_ahead=false` → 代码比库新（**没跑结构迁移**：跑 cli/migrate.php 或点按钮）；
     *  - `schema_current=false` + `schema_ahead=true`  → 库比代码新（**代码回滚了**：先确认是否接受旧代码
     *    跑新库，再跑迁移把标记对齐，并复核种子被回退覆盖的影响）。
     *
     * @param \PDO $pdo 调用方自建连接
     * @return array{schema_version:string|null, seed_version:string|null, schema_current:bool|null, schema_ahead:bool}
     */
    public static function schemaState(\PDO $pdo): array
    {
        $stmt = $pdo->prepare(
            'SELECT setting_key, value FROM ' . \App\Config\AppConfig::TABLE_APP_SETTINGS . ' WHERE setting_key IN (?, ?)'
        );
        $stmt->execute([self::SCHEMA_VERSION_KEY, self::SEED_VERSION_KEY]);

        $schemaVersion = null;
        $seedVersion   = null;
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $key = (string)($row['setting_key'] ?? '');
            if ($key === self::SCHEMA_VERSION_KEY) {
                $schemaVersion = (string)($row['value'] ?? '');
            } elseif ($key === self::SEED_VERSION_KEY) {
                $seedVersion = (string)($row['value'] ?? '');
            }
        }

        return [
            'schema_version' => $schemaVersion,
            'seed_version'   => $seedVersion,
            'schema_current' => self::isVersionAligned($schemaVersion),
            'schema_ahead'   => self::isVersionAhead($schemaVersion),
        ];
    }

    /**
     * 已记录的结构版本是否**高于**当前代码版本（方向位，供 /healthz 与 bootstrap() 共用）。
     *
     * 无记录（未知）→ false：没有任何记录可判定为「更高」。
     * 用 version_compare 而非字符串比较，避免 '2.10.0' < '2.9.0' 这类字典序陷阱
     * （HarborService 判断 API 版本时已用同一函数）。
     */
    private static function isVersionAhead(?string $recorded): bool
    {
        return $recorded !== null
            && version_compare($recorded, \App\Config\AppConfig::APP_VERSION, '>');
    }

    /**
     * 已应用的**表结构**是否对齐当前代码版本。
     *
     * 只认 schema_version：它只由「执行过 DDL」的路径写入（自动模式 ensureTables 成功，或手动模式
     * 跑过 cli/migrate.php / 后台同步）。刻意不接收 seed_version——后者仅证明 RBAC 种子已播（INSERT），
     * 不能证明业务表新增列已就绪；手动模式升级漏跑 DDL 时种子仍会补齐，若据此判 true 会谎报健康。
     * 无 schema_version 时返回 null（未知）：探针不降级也不宣称对齐，是否迁移过由 schema_version 表达。
     */
    private static function isVersionAligned(?string $schemaVersion): ?bool
    {
        if ($schemaVersion !== null) {
            return $schemaVersion === \App\Config\AppConfig::APP_VERSION;
        }
        return null;
    }

    /**
     * 缺失的核心表清单（/healthz 在判定版本未对齐时用它定位原因）。
     *
     * 逐表探测要跑 N 次查询，故正常路径不调用，只在已降级时调用一次，保持探针毫秒级。
     *
     * @param \PDO $pdo 调用方自建连接
     * @return list<string>
     */
    public static function missingTables(\PDO $pdo): array
    {
        $missing = [];
        foreach (self::schemaTables() as $table) {
            if (!self::tableExists($pdo, $table)) {
                $missing[] = $table;
            }
        }
        return $missing;
    }

    /**
     * 只读预检：计算「若现在跑一次迁移，会补哪些表 / 哪些列」（**不建连、不 bootstrap、不写库**）。
     *
     * 与 ensureTables() 同源（都用 columnMigrations() 与 schemaTables()），因此预检结论与实跑一致；
     * 供 `cli/migrate.php --dry-run` 在正式迁移前"先看一眼"。
     *
     * 覆盖范围：缺失表 + 缺失列（结构层面）。**不覆盖**索引与一次性数据搬迁——它们随迁移幂等执行，
     * 重复执行无副作用，无需预检；已在库中的表按映射逐列探测，表不存在时整表会被新建（含这些列），
     * 故不再逐列报告（否则同一张表会同时出现在"缺失表"与"缺失列"里，产生误导）。
     *
     * @param \PDO $pdo 调用方自建连接
     * @return array{missing_tables: list<string>, missing_columns: array<string, list<string>>}
     */
    public static function pendingChanges(\PDO $pdo): array
    {
        $missingColumns = [];
        foreach (self::columnMigrations() as $table => $columns) {
            if (!self::tableExists($pdo, $table)) {
                continue; // 整表缺失：由「缺失表」兜住，不必逐列报告
            }
            foreach (array_keys($columns) as $column) {
                if (!self::columnExists($pdo, $table, $column)) {
                    $missingColumns[$table][] = $column;
                }
            }
        }

        return [
            'missing_tables'  => self::missingTables($pdo),
            'missing_columns' => $missingColumns,
        ];
    }

    /**
     * 存量库「增列」映射：表 => 列 => 该驱动的列定义。**新增列时只需在此追加一项**。
     *
     * 从 ensureTables() 抽出，使补列循环（写）与 pendingChanges()（只读预检）共用同一份事实，
     * 避免预检与实跑两处各维护一份列清单而漂移。定义里的类型按驱动解析（SQLite 无 VARCHAR 概念）。
     *
     * @return array<string, array<string, string>>
     */
    private static function columnMigrations(): array
    {
        $isMySQL = self::$driver === 'mysql';
        $VARCHAR = $isMySQL ? 'VARCHAR(255)' : 'TEXT';  // DEFAULT / INDEX 的列不能用 TEXT
        $TS_TYPE = $isMySQL ? 'DATETIME' : 'TEXT';      // MySQL < 8.0.13 不允许 TEXT/BLOB 设 DEFAULT

        return [
            \App\Config\AppConfig::TABLE_JOB_GIT_MAP => [
                'status' => "{$VARCHAR} DEFAULT '" . \App\Config\AppConfig::STATUS_ACTIVE . "'",
            ],
            \App\Config\AppConfig::TABLE_SECURITY_CHECKS => [
                'tag'               => "{$VARCHAR} DEFAULT ''", // 关联 tag
                'writeback_status'  => "{$VARCHAR} DEFAULT ''", // commit status 回写结果（success/failed/skipped，空=历史）
                'writeback_message' => 'TEXT',
            ],
            \App\Config\AppConfig::TABLE_PIPELINE_BUILD_LOG => [
                'provider'   => "{$VARCHAR} DEFAULT ''", // canonical identity (provider)，回填用
                'project_id' => "{$VARCHAR} DEFAULT ''", // canonical identity (project_id)，回填用
                'repository' => "{$VARCHAR} DEFAULT ''", // harbor 仓库，回填用
            ],
            \App\Config\AppConfig::TABLE_PERMISSIONS => [
                'parent_key' => $isMySQL ? 'VARCHAR(128)' : 'TEXT', // 权限层级
                'created_at' => "{$TS_TYPE} DEFAULT NULL",          // 注册时间：内置为 NULL
            ],
            \App\Config\AppConfig::TABLE_OPERATION_LOGS => [
                'operator_type' => "{$VARCHAR} DEFAULT 'admin'", // 操作人类型：admin / api_token
            ],
            \App\Config\AppConfig::TABLE_ADMIN_USERS => [
                'email'      => "{$VARCHAR} NOT NULL DEFAULT ''", // 用户邮箱（OAuth userinfo 用，空则占位兜底）
                'avatar_url' => 'TEXT',                                        // 头像 URL
                'status'     => ($isMySQL ? 'TINYINT' : 'INTEGER') . " NOT NULL DEFAULT 1", // 1=启用 0=停用
                'created_at' => "{$TS_TYPE} NULL DEFAULT NULL",                // 注册时间（存量行为 NULL，新行为 NOW）
                // 注：id 主键不通过此 ALTER 自动补——MySQL 中 AUTO_INCREMENT 主键列无法幂等补加、
                // 且涉及旧主键（username）变更风险；存量库 id 主键由部署方手动迁移。
            ],

            // user_identities 为 v2.6.3 新增整表，不存在存量列；若后续给该表新加列再据此追加
            \App\Config\AppConfig::TABLE_USER_IDENTITIES => [
            ],
        ];
    }

    /**
     * 手动触发迁移：建缺失表 + 补列 + RBAC/管理员种子 + 自检 + 标记 schema 当前。
     *
     * 入口：后台「同步库结构」按钮（super_admin 专用）、`cli/migrate.php`（发布流水线，无视 DB_AUTO_MIGRATE）。
     * 自带连接（connect()），因此空库 / 手动建库模式漏跑脚本时也能一次建全，不依赖既有 bootstrap 状态。
     *
     * @return array{driver:string, schema_version:string|null, app_version:string, is_current:bool, auto_migrate:bool, tables:array<string,bool>}
     */
    public static function migrateNow(): array
    {
        self::connect();           // 迁移入口自带连接：不依赖既有 bootstrap 状态（空库/手动模式也能直接迁移）
        // 改写版本标记前先取「跃迁前」记录值：库标记高于当前代码版本时（回滚后按 SOP 用旧代码执行
        // 显式迁移，正是最常见的触发场景），这是一次**降级方向**的显式动作——必须与自动引导同等留痕
        // （WARNING + schema_downgrade 审计，detail.channel=explicit_migrate），否则后台只能看到
        // 「同步库结构 成功」，看不出标记被拉回旧版本、系统角色权限被 prune 收敛到了旧定义。
        $prevSchema = self::recordedVersion(self::SCHEMA_VERSION_KEY);
        $prevSeed   = self::recordedVersion(self::SEED_VERSION_KEY);
        self::ensureTables();      // 建表 + RBAC 种子 + 索引 + JSON 迁移
        self::seedAdmin();          // 补管理员种子（与 bootstrap() 一致，缺管理员时自愈）
        self::verifySeed();         // 自检种子不变量，缺失直接抛异常，不标记当前
        self::markSchemaCurrent();  // 自检通过后才标记当前版本
        self::markSeedCurrent();    // 同步种子版本：手动模式随后的首次引导即短路，不再重复播种
        self::noteExplicitDowngrade([self::SCHEMA_VERSION_KEY => $prevSchema, self::SEED_VERSION_KEY => $prevSeed]);
        return self::schemaStatus();
    }

    /**
     * 迁移失败归因：让调用方把「账号无 DDL 权限」与其它失败区分开，给出可操作的提示而不是裸驱动错误。
     *
     * 纯函数（无 IO）便于单测；调用方传 PDOException 的 getMessage() / getCode()（SQLSTATE）/ errorInfo[1]（驱动错误码）。
     *
     * @param string $message    驱动错误消息（大小写不敏感匹配）
     * @param string $sqlState   SQLSTATE（`\PDOException::getCode()`）
     * @param int    $driverCode 驱动特有错误码（`\PDOException::errorInfo[1]`）
     * @return string 'ddl_denied'（账号无 DDL 权限）| 'unreachable'（库不可达/不存在，或连接凭据被拒）| 'other'
     */
    public static function classifyMigrationError(string $message, string $sqlState = '', int $driverCode = 0): string
    {
        // MySQL 驱动错误码优先：1044 = 库级拒绝，1142 = 语句级拒绝（CREATE/ALTER command denied），1227 = 权限不足
        if (in_array($driverCode, [1044, 1142, 1227], true)) {
            return 'ddl_denied';
        }
        // 连接层：1045 = 账号/口令被拒，1049 = 库不存在，2002/2003 = 连不上
        if (in_array($driverCode, [1045, 1049, 2002, 2003], true)) {
            return 'unreachable';
        }
        $lower = strtolower($message);
        // SQLite：只读库 / 只读文件（应用账号无写权限，与「缺 DDL 权限」同类处置）
        if (str_contains($lower, 'readonly') || str_contains($lower, 'attempt to write')) {
            return 'ddl_denied';
        }
        if (
            str_contains($lower, 'unable to open database file')
            || str_contains($lower, 'open_basedir')          // PHP open_basedir 限制导致 SQLite 打不开库文件
            || str_contains($lower, 'connection refused')
            || str_contains($lower, 'unknown database')
            || str_contains($lower, 'no such file')
            || str_contains($lower, 'getaddrinfo')
        ) {
            return 'unreachable';
        }
        // 无驱动码时退回 SQLSTATE 兜底。注意 42000 是「语法错误或访问拒绝」大类：同时覆盖 MySQL
        // 1064(语法错误)/1060(重复列)/1050(表已存在)/1146(表不存在) 与真正的权限拒绝，必须再用消息
        // 关键字确认是权限类。否则迁移 SQL 本身语法失败、或部分成功后重跑撞「列已存在(1060)」这个
        // 最常见的重试路径，都会被误导成「账号缺 DDL 权限，请换高权账号」，把排障引向错误方向。
        if (str_starts_with($sqlState, '42000')) {
            // 只认 denied（command denied / access denied ... need privilege）。不能用 'access violation'：
            // PDO MySQL 标准异常前缀固定是 "Syntax error or access violation: <code>"，连 1064 语法错误也带
            // 这串字，用它会把所有 42000 错误再次一律判成权限问题。
            return str_contains($lower, 'denied') ? 'ddl_denied' : 'other';
        }
        if (str_starts_with($sqlState, '28000')) {
            return 'unreachable';
        }
        return 'other';
    }

    /** 读取配置：优先 $_ENV（phpdotenv 填充），其次真实环境变量 getenv()，避免 variables_order 不含 E 时 shell 环境丢失。 */
    private static function envValue(string $key, string $default = ''): string
    {
        if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
            return (string)$_ENV[$key];
        }
        $v = getenv($key);
        return $v === false ? $default : (string)$v;
    }

    /** 解析 DB_AUTO_MIGRATE：0/false/no/off/空 视为关闭，其余（含默认）视为开启 */
    private static function parseAutoMigrate(): bool
    {
        return !in_array(strtolower(trim(self::envValue('DB_AUTO_MIGRATE', 'true'))), ['0', 'false', 'no', 'off', ''], true);
    }

    /**
     * @return array{driver:string, path:string, host:string, port:string, database:string, username:string, password:string, charset:string, auto_migrate:bool}
     */
    private static function defaultConfig(): array
    {
        $driver = strtolower(self::envValue('DB_DRIVER'));
        if (!in_array($driver, ['sqlite', 'mysql'], true)) {
            throw new \RuntimeException('DB_DRIVER 必须设为 sqlite 或 mysql，当前: ' . ($driver ?: '未设置'));
        }
        return [
            'driver'       => $driver,
            'path'         => self::envValue('DB_PATH', __DIR__ . '/../../config/data/data.db'),
            'host'         => self::envValue('DB_HOST', '127.0.0.1'),
            'port'         => self::envValue('DB_PORT', '3306'),
            'database'     => self::envValue('DB_NAME', 'devops_glue'),
            'username'     => self::envValue('DB_USER', 'root'),
            'password'     => self::envValue('DB_PASS'),
            'charset'      => self::envValue('DB_CHARSET', 'utf8mb4'),
            'auto_migrate' => self::parseAutoMigrate(),
        ];
    }

    // ── PDO 连接 ──

    /**
     * 创建数据库连接（公共入口）：按 self::$config 选择驱动并统一设置 PDO 属性。
     * Web（config/container.php）与 CLI（cli/*.php）共用，消除手写 DSN 重复，
     * 以及 mysql 漏 ATTR_EMULATE_PREPARES / sqlite 漏 foreign_keys 的漂移。
     */
    public static function createPdo(): \PDO
    {
        if (empty(self::$config)) {
            self::init();
        }

        $pdo = (self::$driver === 'mysql') ? self::connectMysql() : self::connectSqlite();

        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        return $pdo;
    }

    /**
     * 返回「已初始化」的连接：内部 createPdo() + bootstrap()，并保证整个进程只执行一次。
     * 业务代码要一个可直接用的库连接时统一走这里，不必感知连接与初始化的分离。
     *
     * 进程内单库约束：连接与驱动由 init() 的静态配置决定，缓存不区分连接参数。
     * 同一进程切换不同库（测试 / CLI 多库）须先 reset()，否则仍返回首次连接。
     */
    public static function getPdo(): \PDO
    {
        if (self::$pdo === null) {
            try {
                self::$pdo = self::createPdo();
                self::bootstrap(self::$pdo);
            } catch (\Throwable $e) {
                self::$pdo = null; // 引导失败不缓存半成品，下次调用重新引导
                throw $e;
            }
        }
        return self::pdo();
    }

    /**
     * 仅建立连接并赋值给内部句柄，**不执行** bootstrap()（不建表/不补种子/不自检）。
     *
     * 供「迁移入口」使用：当表可能尚未就绪（空库 / 手动建库模式漏跑脚本）时，
     * 必须先拿到连接才能建表；此时走 getPdo() 会在补种子阶段先抛异常，形成死结。
     *
     * 与 getPdo() 的关系：getPdo() = connect() + bootstrap()；本方法只做前半段。
     */
    public static function connect(): \PDO
    {
        if (self::$pdo === null) {
            self::$pdo = self::createPdo();
        }
        return self::pdo();
    }

    private static function connectSqlite(): \PDO
    {
        $path = self::$config['path'];
        $dir  = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        $pdo = new \PDO('sqlite:' . $path);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA foreign_keys=ON');
        $pdo->exec('PRAGMA busy_timeout=5000');
        return $pdo;
    }

    private static function connectMysql(): \PDO
    {
        $cfg  = self::$config;
        $dsn  = "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['database']};charset={$cfg['charset']}";
        return new \PDO($dsn, $cfg['username'], $cfg['password'], [
            \PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$cfg['charset']}",
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    // ── 重置 ──

    public static function reset(): void
    {
        self::$pdo = null;
        self::$bootstrapped = false;
    }

    public static function driver(): string
    {
        return self::$driver;
    }

    /**
     * 当前是否为「自动建表」模式（DB_AUTO_MIGRATE 未显式关闭）。
     * 供 CLI（cli/db-init.php）在初始化失败时判断是否处于手动建库模式，从而给出可操作提示。
     * 优先取已解析的配置；配置尚未初始化时直接读环境变量，避免触发 defaultConfig() 抛错。
     */
    public static function isAutoMigrateEnabled(): bool
    {
        if (!empty(self::$config)) {
            return (bool)(self::$config['auto_migrate'] ?? true);
        }
        return self::parseAutoMigrate();
    }

    // ── SQL helper（屏蔽 SQLite/MySQL 语法差异）──

    /** INSERT OR REPLACE / REPLACE INTO */
    public static function sqlUpsert(string $table, string $columns, string $values): string
    {
        $isMySQL = self::$driver === 'mysql';
        return $isMySQL
            ? "REPLACE INTO {$table} ({$columns}) VALUES ({$values})"
            : "INSERT OR REPLACE INTO {$table} ({$columns}) VALUES ({$values})";
    }

    /** 当前时间表达式 */
    public static function sqlNow(): string
    {
        return self::$driver === 'mysql' ? 'NOW()' : "datetime('now','localtime')";
    }

    /** INSERT OR IGNORE / INSERT IGNORE */
    public static function sqlInsertIgnore(string $table, string $columns, string $values): string
    {
        $isMySQL = self::$driver === 'mysql';
        return $isMySQL
            ? "INSERT IGNORE INTO {$table} ({$columns}) VALUES ({$values})"
            : "INSERT OR IGNORE INTO {$table} ({$columns}) VALUES ({$values})";
    }

    /** 判断列是否已存在（MySQL/SQLite 双驱动）；PDO 显式传入，使只读预检（pendingChanges）可复用同一实现 */
    private static function columnExists(\PDO $pdo, string $table, string $column): bool
    {
        if (self::$driver === 'mysql') {
            $stmt = $pdo->prepare(
                "SELECT 1 FROM information_schema.COLUMNS "
                . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
            );
            $stmt->execute([$table, $column]);
            return (bool) $stmt->fetch();
        }
        foreach ($pdo->query("PRAGMA table_info({$table})")->fetchAll() as $row) {
            if (($row['name'] ?? '') === $column) {
                return true;
            }
        }
        return false;
    }

    /** 判断表是否已存在（MySQL/SQLite 双驱动），用于幂等迁移/删除遗留表 */
    private static function tableExists(\PDO $pdo, string $table): bool
    {
        try {
            $pdo->query('SELECT 1 FROM ' . $table . ' LIMIT 1');
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** 删除遗留表（不存在则跳过）；失败不致命，仅记录，下次启动会重试 */
    private static function dropTableIfExists(\PDO $pdo, string $table): void
    {
        try {
            $pdo->exec('DROP TABLE IF EXISTS ' . $table);
        } catch (\Throwable $e) {
            \App\Helper\Log::exception($e);
        }
    }

    // ── 建表 ──

    private static function ensureTables(string $seedMode = self::SEED_MODE_PRUNE): void
    {
        $pdo = self::pdo();
        $isMySQL = self::$driver === 'mysql';

        // 字段类型映射
        $PK       = $isMySQL ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $TEXT_PK  = $isMySQL ? 'VARCHAR(255) PRIMARY KEY'      : 'TEXT PRIMARY KEY';
        $VARCHAR  = $isMySQL ? 'VARCHAR(255)'                   : 'TEXT';  // DEFAULT / INDEX 的列不能用 TEXT
        $VCHAR255 = $isMySQL ? 'VARCHAR(255) NOT NULL'          : 'TEXT NOT NULL';  // 复合主键中的列
        $NOW      = self::sqlNow();
        $ENGINE   = $isMySQL ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
        // 时间戳列：MySQL < 8.0.13 不允许 TEXT/BLOB 设置 DEFAULT，必须用 DATETIME
        $TS_TYPE  = $isMySQL ? 'DATETIME' : 'TEXT';

        // ci_job_git_map
        $pdo->exec("CREATE TABLE IF NOT EXISTS " . \App\Config\AppConfig::TABLE_JOB_GIT_MAP . " (
            job_name {$TEXT_PK},
            git_platform TEXT,
            build_provider {$VARCHAR} DEFAULT '" . \App\Config\AppConfig::PROVIDER_JENKINS . "',
            git_remote TEXT,
            project_id INTEGER,
            web_url TEXT,
            current_path TEXT,
            harbor_repository TEXT,
            api_version TEXT,
            status {$VARCHAR} DEFAULT '" . \App\Config\AppConfig::STATUS_ACTIVE . "'
        ){$ENGINE}");
        // ci_platform_versions
        $pdo->exec("CREATE TABLE IF NOT EXISTS " . \App\Config\AppConfig::TABLE_PLATFORM_VERSIONS . " (
            platform {$TEXT_PK},
            version TEXT NOT NULL
        ){$ENGINE}");

        // ci_pipeline_artifacts：Pipeline → Primary Artifact（当前保持 1:1）。
        // canonical identity = (provider, project_id, pipeline_iid)。
        $pdo->exec("CREATE TABLE IF NOT EXISTS " . \App\Config\AppConfig::TABLE_PIPELINE_ARTIFACTS . " (
            id {$PK},
            provider {$VARCHAR} NOT NULL,
            project_id {$VARCHAR} NOT NULL,
            pipeline_iid INTEGER NOT NULL,
            project_key {$VCHAR255},
            repository TEXT NOT NULL,
            tag {$VARCHAR} NOT NULL,
            status {$VARCHAR} DEFAULT '',
            source_updated_at {$TS_TYPE} DEFAULT NULL,
            created_at {$TS_TYPE} DEFAULT ({$NOW}),
            updated_at {$TS_TYPE} DEFAULT ({$NOW}),
            UNIQUE (provider, project_id, pipeline_iid)
        ){$ENGINE}");
        // ci_custom_builds（自定义推送式 CI 的构建记录，只存元数据，不存日志内容）
        $pdo->exec("CREATE TABLE IF NOT EXISTS " . \App\Config\AppConfig::TABLE_CUSTOM_BUILDS . " (
            id {$PK},
            job_name {$VCHAR255},
            pipeline_iid INTEGER NOT NULL,
            ref TEXT,
            sha TEXT,
            variables_json TEXT,
            status {$VARCHAR} DEFAULT 'pending',
            exit_code INTEGER,
            log_url TEXT,
            web_url TEXT,
            triggered_at {$TS_TYPE},
            started_at {$TS_TYPE},
            finished_at {$TS_TYPE},
            UNIQUE (job_name, pipeline_iid)
        ){$ENGINE}");
        // cache
        if ($isMySQL) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS " . \App\Config\AppConfig::TABLE_CACHE . " (
                cache_key VARCHAR(255) PRIMARY KEY,
                `value` MEDIUMTEXT NOT NULL,
                expires_at INTEGER
            ){$ENGINE}");
        } else {
            $pdo->exec("CREATE TABLE IF NOT EXISTS " . \App\Config\AppConfig::TABLE_CACHE . " (
                cache_key TEXT PRIMARY KEY,
                value TEXT NOT NULL,
                expires_at INTEGER
            )");
        }

        // admin_users（v2.6.3 起：id 为主键，username 降为唯一约束；新增 avatar_url/status/created_at）
        $pdo->exec("CREATE TABLE IF NOT EXISTS " . \App\Config\AppConfig::TABLE_ADMIN_USERS . " (
            id " . ($isMySQL ? 'BIGINT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT') . ",
            username " . ($isMySQL ? 'VARCHAR(255)' : 'TEXT') . " NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            role {$VARCHAR} NOT NULL DEFAULT '" . \App\Config\AppConfig::ROLE_ADMIN . "',
            systems {$VARCHAR} NOT NULL DEFAULT 'ci,cd',
            email {$VARCHAR} NOT NULL DEFAULT '',
            avatar_url TEXT,
            status " . ($isMySQL ? 'TINYINT' : 'INTEGER') . " NOT NULL DEFAULT 1,
            created_at {$TS_TYPE} DEFAULT ({$NOW}),
            updated_at {$TS_TYPE} DEFAULT ({$NOW})
        ){$ENGINE}");


        // ci_app_settings（应用运行时配置，与缓存分离）
        if ($isMySQL) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS " . \App\Config\AppConfig::TABLE_APP_SETTINGS . " (
                setting_key VARCHAR(255) PRIMARY KEY,
                `value` MEDIUMTEXT NOT NULL,
                updated_at {$TS_TYPE} DEFAULT ({$NOW})
            ){$ENGINE}");
        } else {
            $pdo->exec("CREATE TABLE IF NOT EXISTS " . \App\Config\AppConfig::TABLE_APP_SETTINGS . " (
                setting_key TEXT PRIMARY KEY,
                value TEXT NOT NULL,
                updated_at {$TS_TYPE} DEFAULT ({$NOW})
            )");
        }

        // 迁移：将 cache 表中可能残留的 build_mode 移至 ci_app_settings
        try {
            $old = $pdo->query("SELECT value FROM " . \App\Config\AppConfig::TABLE_CACHE . " WHERE cache_key = 'build_mode'")->fetch();
            if ($old && in_array($old['value'], [\App\Config\AppConfig::BUILD_MODE_JENKINS, \App\Config\AppConfig::BUILD_MODE_GITLAB_CI, \App\Config\AppConfig::BUILD_MODE_GITEA_CI, \App\Config\AppConfig::BUILD_MODE_BOTH])) {
                $exists = $pdo->query("SELECT 1 FROM " . \App\Config\AppConfig::TABLE_APP_SETTINGS . " WHERE setting_key = 'build_mode'")->fetch();
                if (!$exists) {
                    $sql = self::sqlUpsert(\App\Config\AppConfig::TABLE_APP_SETTINGS, 'setting_key, value, updated_at', '?, ?, ' . self::sqlNow());
                    $pdo->prepare($sql)->execute(['build_mode', $old['value']]);
                }
                $pdo->exec("DELETE FROM " . \App\Config\AppConfig::TABLE_CACHE . " WHERE cache_key = 'build_mode'");
            }
        } catch (\Exception $e) {
        }

        // ci_pipeline_build_log：拉取式记录「镜像 Tag」日志兜底的懒解析缓存（sha → tag）。
        // sha 内容寻址、全局唯一，故以 sha 为唯一键；project_key/pipeline_id 仅作溯源参考。
        // provider/project_id/repository 记录 canonical identity + harbor 仓库，供回填 cron 直接把
        // 日志推导的 tag 提升写入 ci_pipeline_artifacts（无需重跑 provider 解析）。
        $pdo->exec("CREATE TABLE IF NOT EXISTS " . \App\Config\AppConfig::TABLE_PIPELINE_BUILD_LOG . " (
            id {$PK},
            project_key {$VARCHAR} DEFAULT '',
            provider {$VARCHAR} DEFAULT '',
            project_id {$VARCHAR} DEFAULT '',
            sha {$VARCHAR} NOT NULL UNIQUE,
            pipeline_id {$VARCHAR} DEFAULT '',
            tag {$VARCHAR} DEFAULT '',
            repository {$VARCHAR} DEFAULT '',
            source {$VARCHAR} DEFAULT 'log',
            created_at {$TS_TYPE} DEFAULT ({$NOW}),
            updated_at {$TS_TYPE} DEFAULT ({$NOW})
        ){$ENGINE}");

        // ci_security_checks（安全扫描审计记录）
        $pdo->exec("CREATE TABLE IF NOT EXISTS " . \App\Config\AppConfig::TABLE_SECURITY_CHECKS . " (
            id {$PK},
            project {$VARCHAR} NOT NULL,
            sha {$VARCHAR} NOT NULL,
            check_type {$VARCHAR} NOT NULL,
            state {$VARCHAR} NOT NULL,
            context {$VARCHAR} NOT NULL,
            description TEXT,
            tag {$VARCHAR} DEFAULT '',
            writeback_status {$VARCHAR} DEFAULT '',
            writeback_message TEXT,
            created_at {$TS_TYPE} DEFAULT ({$NOW})
        ){$ENGINE}");

        // ci_operation_logs（后台操作审计日志，append-only，只增不删）
        $pdo->exec("CREATE TABLE IF NOT EXISTS " . \App\Config\AppConfig::TABLE_OPERATION_LOGS . " (
            id {$PK},
            username {$VARCHAR} NOT NULL,
            action {$VARCHAR} NOT NULL,
            target {$VARCHAR} DEFAULT '',
            detail TEXT,
            ip {$VARCHAR} DEFAULT '',
            operator_type {$VARCHAR} DEFAULT 'admin',
            result {$VARCHAR} DEFAULT 'success',
            created_at {$TS_TYPE} DEFAULT ({$NOW})
        ){$ENGINE}");
        // ci_api_access_logs（API token 调用审计日志，append-only + 定时清理）
        // 安全约束：只记 token 展示名，不记 token/hash/body/query；route 存路由模板。
        // 列宽显式声明并与 database/*_init.sql 保持一致（route/scopes/error_reason 需 500）。
        $S  = $isMySQL ? 'VARCHAR(255)' : 'TEXT';
        $L  = $isMySQL ? 'VARCHAR(500)' : 'TEXT';
        $M  = $isMySQL ? 'VARCHAR(10)'  : 'TEXT';
        $R  = $isMySQL ? 'VARCHAR(20)'  : 'TEXT';
        $IP = $isMySQL ? 'VARCHAR(45)'  : 'TEXT';
        $pdo->exec("CREATE TABLE IF NOT EXISTS " . \App\Config\AppConfig::TABLE_API_ACCESS_LOGS . " (
            id {$PK},
            username {$S} DEFAULT '',
            token_name {$S} DEFAULT '',
            scopes {$L} DEFAULT '',
            method {$M} NOT NULL,
            route {$L} NOT NULL,
            status_code INTEGER NOT NULL DEFAULT 0,
            result {$R} NOT NULL DEFAULT 'success',
            error_reason {$L} DEFAULT '',
            ip {$IP} DEFAULT '',
            duration_ms INTEGER NOT NULL DEFAULT 0,
            created_at {$TS_TYPE} DEFAULT ({$NOW})
        ){$ENGINE}");
        // ── RBAC 权限系统 ──
        // roles
        $pdo->exec("CREATE TABLE IF NOT EXISTS " . \App\Config\AppConfig::TABLE_ROLES . " (
            id {$PK},
            name {$VARCHAR} NOT NULL UNIQUE,
            description TEXT,
            is_system TINYINT NOT NULL DEFAULT 0,
            created_at {$TS_TYPE} DEFAULT ({$NOW})
        ){$ENGINE}");
        // permissions
        if ($isMySQL) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS " . \App\Config\AppConfig::TABLE_PERMISSIONS . " (
                perm_key VARCHAR(128) PRIMARY KEY,
                description TEXT,
                parent_key VARCHAR(128),
                created_at {$TS_TYPE} DEFAULT NULL
            ){$ENGINE}");
        } else {
            $pdo->exec("CREATE TABLE IF NOT EXISTS " . \App\Config\AppConfig::TABLE_PERMISSIONS . " (
                perm_key TEXT PRIMARY KEY,
                description TEXT,
                parent_key TEXT,
                created_at {$TS_TYPE} DEFAULT NULL
            )");
        }
        // role_permissions
        if ($isMySQL) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS " . \App\Config\AppConfig::TABLE_ROLE_PERMISSIONS . " (
                role_id INTEGER NOT NULL,
                perm_key VARCHAR(128) NOT NULL,
                PRIMARY KEY (role_id, perm_key)
            ){$ENGINE}");
        } else {
            $pdo->exec("CREATE TABLE IF NOT EXISTS " . \App\Config\AppConfig::TABLE_ROLE_PERMISSIONS . " (
                role_id INTEGER NOT NULL,
                perm_key TEXT NOT NULL,
                PRIMARY KEY (role_id, perm_key)
            )");
        }

        // implied_rules 表：权限隐含关系（source_key → target_key），数据驱动，运行时可由 CD 项目通过 API 注册
        if ($isMySQL) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS " . \App\Config\AppConfig::TABLE_IMPLIED_RULES . " (
                source_key VARCHAR(128) NOT NULL,
                target_key VARCHAR(128) NOT NULL,
                PRIMARY KEY (source_key, target_key)
            ){$ENGINE}");
        } else {
            $pdo->exec("CREATE TABLE IF NOT EXISTS " . \App\Config\AppConfig::TABLE_IMPLIED_RULES . " (
                source_key TEXT NOT NULL,
                target_key TEXT NOT NULL,
                PRIMARY KEY (source_key, target_key)
            )");
        }

        // user_identities（身份源关联：一个 admin_users.username 可绑 ldap/local 等多个登录方式）
        // 与 admin_users 解耦：admin_users 只保留"用户存在 + 角色 + 系统范围"，
        // 而"通过哪种身份源登录、对应的标识符/凭据"在此，便于未来接入 oauth/saml 等。
        if ($isMySQL) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS " . \App\Config\AppConfig::TABLE_USER_IDENTITIES . " (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(255) NOT NULL,
                provider_type VARCHAR(32) NOT NULL,
                provider_uid VARCHAR(255) NOT NULL,
                credential TEXT,
                email VARCHAR(255) NOT NULL DEFAULT '',
                raw_profile MEDIUMTEXT,
                bound_at {$TS_TYPE} DEFAULT ({$NOW}),
                updated_at {$TS_TYPE} DEFAULT ({$NOW}),
                UNIQUE KEY uniq_provider (provider_type, provider_uid(191)),
                KEY idx_username (username)
            ){$ENGINE}");
        } else {
            $pdo->exec("CREATE TABLE IF NOT EXISTS " . \App\Config\AppConfig::TABLE_USER_IDENTITIES . " (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL,
                provider_type TEXT NOT NULL,
                provider_uid TEXT NOT NULL,
                credential TEXT,
                email TEXT NOT NULL DEFAULT '',
                raw_profile TEXT,
                bound_at TEXT DEFAULT (datetime('now','localtime')),
                updated_at TEXT DEFAULT (datetime('now','localtime')),
                UNIQUE (provider_type, provider_uid)
            )");
        }

        // api_tokens（服务账号 / 第三方调用的 API token，独立于 RBAC 权限体系）
        $pdo->exec("CREATE TABLE IF NOT EXISTS " . \App\Config\AppConfig::TABLE_API_TOKENS . " (
            id {$PK},
            name {$VARCHAR} NOT NULL,
            token_hash " . ($isMySQL ? 'VARCHAR(64) NOT NULL UNIQUE' : 'TEXT NOT NULL UNIQUE') . ",
            scopes TEXT,
            enabled " . ($isMySQL ? 'TINYINT NOT NULL DEFAULT 1' : 'INTEGER NOT NULL DEFAULT 1') . ",
            expires_at INTEGER,
            created_by {$VARCHAR},
            note TEXT,
            created_at {$TS_TYPE} DEFAULT ({$NOW})
        ){$ENGINE}");

        // ── 通用列迁移 ──
        // 列清单统一由 columnMigrations() 提供（写路径与只读预检 pendingChanges() 共用同一份事实，避免两处漂移）。
        // 补列循环：遍历有限映射，天然有界、必然终止（无 while/递归，无需显式 break）。
        // 任一列检测/ALTER 失败会抛 PDOException 一路向上（fail-fast，与上方 CREATE TABLE 一致），
        // 应用启动失败；但因 columnExists 幂等 + 尚未 markSchemaCurrent，下次启动会重跑补齐剩余列，不留半成品。
        foreach (self::columnMigrations() as $table => $columns) {
            foreach ($columns as $column => $definition) {
                if (!self::columnExists($pdo, $table, $column)) {
                    $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
                }
            }
        }

        // 种子数据：RBAC（权限定义 / 隐含规则 / 系统角色 / 角色↔权限），幂等可重复执行
        // seedMode 透传：降级回滚时用 merge（只补不删），避免回滚把系统角色权限收窄。
        self::seedRbac($seedMode);

        // ── 索引（跨驱动幂等：MySQL 无 IF NOT EXISTS，先查 information_schema）──
        self::createIndex('idx_pipeline_artifacts_project_key', \App\Config\AppConfig::TABLE_PIPELINE_ARTIFACTS, 'project_key');
        self::createIndex('idx_pipeline_artifacts_created', \App\Config\AppConfig::TABLE_PIPELINE_ARTIFACTS, 'created_at');
        self::createIndex('idx_job_git_map_current_path', \App\Config\AppConfig::TABLE_JOB_GIT_MAP, 'current_path');
        self::createIndex('idx_security_checks_project', \App\Config\AppConfig::TABLE_SECURITY_CHECKS, 'project, check_type');
        self::createIndex('idx_security_checks_sha', \App\Config\AppConfig::TABLE_SECURITY_CHECKS, 'sha');
        self::createIndex('idx_operation_logs_created', \App\Config\AppConfig::TABLE_OPERATION_LOGS, 'created_at');
        self::createIndex('idx_operation_logs_user', \App\Config\AppConfig::TABLE_OPERATION_LOGS, 'username');
        self::createIndex('idx_operation_logs_action', \App\Config\AppConfig::TABLE_OPERATION_LOGS, 'action');
        self::createIndex('idx_api_access_logs_created', \App\Config\AppConfig::TABLE_API_ACCESS_LOGS, 'created_at');
        self::createIndex('idx_api_access_logs_token', \App\Config\AppConfig::TABLE_API_ACCESS_LOGS, 'token_name');
        self::createIndex('idx_api_access_logs_result', \App\Config\AppConfig::TABLE_API_ACCESS_LOGS, 'result');

        // 一次性 JSON 迁移（仅 SQLite）
        if (!$isMySQL) {
            $baseDir = __DIR__ . '/../../config';
            self::migrateJobGitMap("{$baseDir}/job_git_map.json", $pdo);
            self::migratePlatformVersions("{$baseDir}/platform_versions.json", $pdo);
        }

        // 新 canonical artifact 表的存量迁移：MySQL / SQLite 均需要执行一次。
        self::migratePipelineArtifacts($pdo);

        // 存量脏数据清理：job_name/current_path 去除首尾空白（幂等，TRIM 语义两驱动一致）。
        self::trimJobGitMapWhitespace($pdo);
    }

    /**
     * 清理 ci_job_git_map 中 job_name/current_path 的首尾空白。
     * 幂等：TRIM 后再次执行无变化；仅针对历史脏数据（曾导致 Jenkins `job/ foo` 404）。
     */
    private static function trimJobGitMapWhitespace(\PDO $pdo): void
    {
        try {
            $table = \App\Config\AppConfig::TABLE_JOB_GIT_MAP;
            if (!self::tableExists($pdo, $table)) {
                return;
            }
            $pdo->exec("UPDATE {$table} SET job_name = TRIM(job_name), current_path = TRIM(current_path)");
        } catch (\Throwable $e) {
            // 清理失败不应阻断启动（属优化性修复），仅记录告警
            \App\Helper\Log::error('trimJobGitMapWhitespace 失败', ['error' => $e->getMessage()]);
        }
    }

    /**
     * 跨驱动创建索引（幂等，失败不阻断启动）。
     * MySQL 8.0 不支持 CREATE INDEX IF NOT EXISTS，需先查 information_schema 判断；
     * SQLite 用原生 IF NOT EXISTS 语法。
     */
    private static function createIndex(string $index, string $table, string $columns): void
    {
        try {
            if (self::$driver === 'mysql') {
                $stmt = self::pdo()->prepare(
                    'SELECT COUNT(*) FROM information_schema.statistics'
                    . ' WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?'
                );
                $stmt->execute([$table, $index]);
                if ((int)$stmt->fetchColumn() > 0) {
                    return; // 索引已存在
                }
                self::pdo()->exec("CREATE INDEX {$index} ON {$table} ({$columns})");
            } else {
                self::pdo()->exec("CREATE INDEX IF NOT EXISTS {$index} ON {$table} ({$columns})");
            }
        } catch (\Exception $e) {
            // 索引非关键路径，失败不阻断启动，但记录日志以便排查
            \App\Helper\Log::exception($e);
        }
    }

    // ── RBAC 种子 ──

    private static function seedRbac(string $seedMode = self::SEED_MODE_PRUNE): void
    {
        $pdo = self::pdo();

        /** @var list<array{step:string,key:string,error:string}> $failures 收集部分失败项；循环结束后统一 fail-fast */
        $failures = [];

        // 种子数据：权限定义（含 parent_key）
        $permUpsert = self::sqlUpsert(\App\Config\AppConfig::TABLE_PERMISSIONS, 'perm_key, description, parent_key', '?, ?, ?');
        $permStmt = $pdo->prepare($permUpsert);
        foreach (\App\Config\AppConfig::DEFAULT_PERMISSIONS as $key => $def) {
            $desc = $def['name'];
            $parent = $def['parent'] ?? null;
            try {
                $permStmt->execute([$key, $desc, $parent]);
            } catch (\Exception $e) {
                \App\Helper\Log::error('seedRbac 权限写入失败', ['perm_key' => $key, 'error' => $e->getMessage()]);
                $failures[] = ['step' => 'permission', 'key' => (string)$key, 'error' => $e->getMessage()];
            }
        }

        // 种子数据：隐含规则（与 DEFAULT_PERMISSIONS 一样作 bootstrap，运行时可被 API 覆盖/扩展）
        $ruleUpsert = self::sqlUpsert(\App\Config\AppConfig::TABLE_IMPLIED_RULES, 'source_key, target_key', '?, ?');
        $ruleStmt = $pdo->prepare($ruleUpsert);
        foreach (\App\Config\AppConfig::IMPLIED_PERMISSIONS as $src => $targets) {
            foreach ($targets as $tgt) {
                try {
                    $ruleStmt->execute([$src, $tgt]);
                } catch (\Exception $e) {
                    \App\Helper\Log::error('seedRbac 隐含规则写入失败', ['source' => $src, 'target' => $tgt, 'error' => $e->getMessage()]);
                    $failures[] = ['step' => 'implied_rule', 'key' => $src . '→' . $tgt, 'error' => $e->getMessage()];
                }
            }
        }

        // 种子数据：系统角色（幂等，is_system 由 DEFAULT_SYSTEM_ROLES 决定）
        // 不能用 sqlUpsert（REPLACE INTO / INSERT OR REPLACE）：roles.name 是 UNIQUE，
        // REPLACE 遇冲突会删旧行插新行 → 自增 id 变化 → role_permissions 里引用旧 id
        // 的行全部变成孤儿（历史上每次 bootstrap 都会遗留一组孤儿行）。
        // 改为查得到就 UPDATE（保留 id），查不到才 INSERT。
        $findRoleStmt   = $pdo->prepare("SELECT id FROM " . \App\Config\AppConfig::TABLE_ROLES . " WHERE name = ?");
        $insertRoleStmt = $pdo->prepare("INSERT INTO " . \App\Config\AppConfig::TABLE_ROLES . " (name, description, is_system) VALUES (?, ?, ?)");
        $updateRoleStmt = $pdo->prepare("UPDATE " . \App\Config\AppConfig::TABLE_ROLES . " SET description = ?, is_system = ? WHERE id = ?");
        foreach (\App\Config\AppConfig::DEFAULT_ROLES as $roleName => $perms) {
            // 系统角色描述从 DEFAULT_ROLE_DESCRIPTIONS 取（供 CD 角色目录 / 后台列表展示），自定义角色不在此处种子
            $roleDesc = \App\Config\AppConfig::DEFAULT_ROLE_DESCRIPTIONS[$roleName];
            $isSystem = in_array($roleName, \App\Config\AppConfig::DEFAULT_SYSTEM_ROLES) ? 1 : 0;
            try {
                $findRoleStmt->execute([$roleName]);
                $roleId = $findRoleStmt->fetchColumn();
                if ($roleId === false) {
                    try {
                        $insertRoleStmt->execute([$roleName, $roleDesc, $isSystem]);
                    } catch (\Exception $e) {
                        // 并发下另一进程可能已插入同名角色（roles.name UNIQUE）：回退为 UPDATE，保留其 id。
                        $findRoleStmt->execute([$roleName]);
                        $roleId = $findRoleStmt->fetchColumn();
                        if ($roleId === false) {
                            throw $e; // 并非「被并发插入」场景，交给外层记录真实错误
                        }
                        $updateRoleStmt->execute([$roleDesc, $isSystem, (int)$roleId]);
                    }
                } else {
                    $updateRoleStmt->execute([$roleDesc, $isSystem, (int)$roleId]);
                }
            } catch (\Exception $e) {
                \App\Helper\Log::error('seedRbac 角色写入失败', ['role' => $roleName, 'error' => $e->getMessage()]);
                $failures[] = ['step' => 'role', 'key' => $roleName, 'error' => $e->getMessage()];
            }
        }

        // 种子数据：角色↔权限（只同步系统角色，不碰自定义角色）
        // 整段唯一的破坏性动作就是下面这条 DELETE：prune（默认）先清空该角色的映射再按当前代码重建；
        // merge（降级回滚）跳过它——只补当前代码期望的映射（INSERT IGNORE 天然幂等），保留新版本授予的映射。
        $allPermKeys = array_keys(\App\Config\AppConfig::DEFAULT_PERMISSIONS);
        $delRpStmt = ($seedMode === self::SEED_MODE_PRUNE)
            ? $pdo->prepare("DELETE FROM " . \App\Config\AppConfig::TABLE_ROLE_PERMISSIONS . " WHERE role_id = (SELECT id FROM " . \App\Config\AppConfig::TABLE_ROLES . " WHERE name = ?)")
            : null;
        // INSERT 用 IGNORE 而非裸 INSERT：分布式部署下 web / worker 容器启动时并发跑 seedRbac，
        // 两个进程都先 DELETE 再 INSERT 同一批 (role_id, perm_key)，裸 INSERT 会撞 role_permissions
        // 联合主键报 1062 Duplicate entry。IGNORE 让后到者静默跳过，各进程结果收敛一致。
        $rpStmt = $pdo->prepare(self::sqlInsertIgnore(
            \App\Config\AppConfig::TABLE_ROLE_PERMISSIONS,
            'role_id, perm_key',
            '(SELECT id FROM ' . \App\Config\AppConfig::TABLE_ROLES . ' WHERE name = ?), ?'
        ));
        foreach (\App\Config\AppConfig::DEFAULT_ROLES as $roleName => $perms) {
            if ($delRpStmt !== null) {
                try {
                    $delRpStmt->execute([$roleName]);
                } catch (\Exception $e) {
                    \App\Helper\Log::error('seedRbac 角色权限清理失败', ['role' => $roleName, 'error' => $e->getMessage()]);
                    $failures[] = ['step' => 'role_permissions_prune', 'key' => $roleName, 'error' => $e->getMessage()];
                }
            }
            $permKeys = ($perms === '*') ? $allPermKeys : $perms;
            foreach ($permKeys as $permKey) {
                try {
                    $rpStmt->execute([$roleName, $permKey]);
                } catch (\Exception $e) {
                    \App\Helper\Log::error('seedRbac 角色权限写入失败', ['role' => $roleName, 'perm_key' => $permKey, 'error' => $e->getMessage()]);
                    $failures[] = ['step' => 'role_permission', 'key' => $roleName . ':' . $permKey, 'error' => $e->getMessage()];
                }
            }
        }

        // 部分失败必须 **fail-fast**，而不是"记日志继续"：权限只播了一半的表现是「某个按钮该显示却没显示」，
        // 极易被当成功能 bug 排查半天（这是唯一能掩盖真实问题的一条路径）。
        // 这里与 verifySeed() 走同一条路——抛异常 → bootstrap() 不会 markSchemaCurrent()，版本标记保持旧值，
        // 下次引导会重试整个 ensureTables()（自愈）；文件日志 + 操作日志双写留痕（去重窗口内不重复刷）。
        if ($failures !== []) {
            $sample = array_slice($failures, 0, 5);
            \App\Helper\Log::error('seedRbac 部分失败：种子未播全', ['count' => count($failures), 'sample' => $sample]);
            self::recordSystemAudit(
                self::ACTION_RBAC_SEED_FAILED,
                '',
                ['count' => count($failures), 'sample' => $sample, 'seed_mode' => $seedMode],
                'failure'
            );
            throw new \RuntimeException(
                'RBAC 种子部分失败（' . count($failures) . ' 项，例如 ' . $sample[0]['step'] . ' ' . $sample[0]['key'] . '）：'
                . '种子未播全，本次不标记版本，下次引导将重试'
            );
        }

        // 防御：清扫孤儿行（旧版 REPLACE INTO 换 id 遗留的 role_id 悬空引用）。
        // 角色种子已在上方完成，此刻 roles 表必然非空；role_id NOT IN (roles.id)
        // 正是孤儿定义，不会误删有效行。随每次种子执行，存量库会在下次发版自愈。
        $pdo->exec(
            "DELETE FROM " . \App\Config\AppConfig::TABLE_ROLE_PERMISSIONS
            . " WHERE role_id NOT IN (SELECT id FROM " . \App\Config\AppConfig::TABLE_ROLES . ")"
        );
    }

    // ── 管理员种子 ──

    private static function seedAdmin(): void
    {
        AdminUserRepository::seedAdminFromEnv(self::pdo());
    }

    /**
     * 自检：种子数据是否真的写进去了。
     *
     * 历史 bug 是种子调用链被重构断掉后静默失败（不报错、功能悄悄消失）。
     * 这里对关键不变量做校验，一旦缺失直接抛异常，让问题在启动阶段就暴露，
     * 而不是登录时才发现"改密码"等功能不见了。
     */
    private static function verifySeed(): void
    {
        $pdo = self::pdo();
        $problems = [];

        try {
            $stmt = $pdo->prepare("SELECT count(*) c FROM " . \App\Config\AppConfig::TABLE_ROLES . " WHERE name = ?");
            $stmt->execute([\App\Config\AppConfig::ROLE_SUPER_ADMIN]);
            if ((int)$stmt->fetch()['c'] === 0) {
                $problems[] = 'roles 缺少 super_admin';
            }
        } catch (\Throwable $e) {
            $problems[] = 'roles 查询失败: ' . $e->getMessage();
        }

        try {
            $cnt = (int)$pdo->query("SELECT count(*) c FROM " . \App\Config\AppConfig::TABLE_PERMISSIONS)->fetch()['c'];
            if ($cnt === 0) {
                $problems[] = 'permissions 为空';
            }
        } catch (\Throwable $e) {
            $problems[] = 'permissions 查询失败: ' . $e->getMessage();
        }

        // 仅在配置了 ADMIN_PASSWORD 时才要求管理员账号存在（未设密码属首次初始化，允许为空）
        if (($_ENV['ADMIN_PASSWORD'] ?? '') !== '') {
            try {
                $cnt = (int)$pdo->query("SELECT count(*) c FROM " . \App\Config\AppConfig::TABLE_ADMIN_USERS)->fetch()['c'];
                if ($cnt === 0) {
                    $problems[] = 'admin_users 为空';
                }
            } catch (\Throwable $e) {
                $problems[] = 'admin_users 查询失败: ' . $e->getMessage();
            }
        }

        if (!empty($problems)) {
            throw new \RuntimeException('数据库种子自检未通过: ' . implode('；', $problems));
        }
    }

    // ── JSON 迁移（仅 SQLite 一次性）──

    private static function migrateJobGitMap(string $path, \PDO $pdo): void
    {
        if (!file_exists($path)) {
            return;
        }
        $json = file_get_contents($path);
        if ($json === false) {
            return;
        }
        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data[0])) {
            @unlink($path);
            return;
        }

        $isMySQL = self::$driver === 'mysql';
        $table = \App\Config\AppConfig::TABLE_JOB_GIT_MAP;
        $sql = $isMySQL
            ? "INSERT IGNORE INTO {$table} (job_name,git_platform,build_provider,git_remote,project_id,web_url,current_path,harbor_repository,api_version) VALUES (?,?,?,?,?,?,?,?,?)"
            : "INSERT OR IGNORE INTO {$table} (job_name,git_platform,build_provider,git_remote,project_id,web_url,current_path,harbor_repository,api_version) VALUES (?,?,?,?,?,?,?,?,?)";

        $stmt = $pdo->prepare($sql);
        foreach ($data as $row) {
            if (empty($row['job_name'])) {
                continue;
            }
            $stmt->execute([
                $row['job_name'], $row['git_platform'] ?? null, $row['build_provider'] ?? \App\Config\AppConfig::PROVIDER_JENKINS,
                $row['git_remote'] ?? null, $row['project_id'] ?? null, $row['web_url'] ?? null,
                $row['current_path'] ?? null, $row['harbor_repository'] ?? null, $row['api_version'] ?? null,
            ]);
        }
        @unlink($path);
    }

    private static function migratePlatformVersions(string $path, \PDO $pdo): void
    {
        if (!file_exists($path)) {
            return;
        }
        $json = file_get_contents($path);
        if ($json === false) {
            return;
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            @unlink($path);
            return;
        }

        $isMySQL = self::$driver === 'mysql';
        $table = \App\Config\AppConfig::TABLE_PLATFORM_VERSIONS;
        $sql = $isMySQL
            ? "REPLACE INTO {$table} (platform,version) VALUES (?,?)"
            : "INSERT OR REPLACE INTO {$table} (platform,version) VALUES (?,?)";

        $stmt = $pdo->prepare($sql);
        foreach ($data as $platform => $ver) {
            if (is_string($ver)) {
                $stmt->execute([$platform, $ver]);
            }
        }
        @unlink($path);
    }

    /**
     * 将旧 ci_pipeline_tags 投影迁移到 canonical ci_pipeline_artifacts。
     * 冲突时按 created_at DESC 先写最新记录，避免旧 alias 覆盖较新事实。
     * 幂等：artifact 表的 canonical UNIQUE 防止重复迁移。
     */
    private static function migratePipelineArtifacts(\PDO $pdo): void
    {
        $artifactTable = \App\Config\AppConfig::TABLE_PIPELINE_ARTIFACTS;
        $tagTable      = 'ci_pipeline_tags'; // 遗留表名（迁移源），迁移完成后 DROP
        $mapTable      = \App\Config\AppConfig::TABLE_JOB_GIT_MAP;
        // getPdo() 在某些调用链中会直接执行 ensureTables()，因此这里必须低成本幂等。
        // artifact 已有任意数据 = 迁移已完成；无遗留表 = 全新安装。两种情况都只需清理遗留表。
        $existing = $pdo->query('SELECT 1 FROM ' . $artifactTable . ' LIMIT 1')->fetchColumn();
        if ($existing !== false || !self::tableExists($pdo, $tagTable)) {
            self::dropTableIfExists($pdo, $tagTable);
            return;
        }

        try {
            $rows = $pdo->query(
                'SELECT project, pipeline_iid, tag, harbor_repository, status, created_at
                 FROM ' . $tagTable . ' ORDER BY created_at DESC'
            )->fetchAll();
            if (!$rows) {
                self::dropTableIfExists($pdo, $tagTable);
                return;
            }
            $maps = $pdo->query(
                'SELECT job_name, current_path, build_provider, project_id FROM ' . $mapTable
            )->fetchAll();
            $insert = self::sqlInsertIgnore(
                $artifactTable,
                'provider, project_id, pipeline_iid, project_key, repository, tag, status, source_updated_at, created_at, updated_at',
                '?, ?, ?, ?, ?, ?, ?, ?, ?, ' . self::sqlNow()
            );
            $stmt = $pdo->prepare($insert);
            $started = false;
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
                $started = true;
            }
            foreach ($rows as $row) {
                $project = (string) ($row['project'] ?? '');
                $provider = \App\Config\AppConfig::PROVIDER_JENKINS;
                $projectId = $project;
                foreach ($maps as $m) {
                    $job = (string) ($m['job_name'] ?? '');
                    $cp  = (string) ($m['current_path'] ?? '');
                    if ($job !== $project && $cp !== $project) {
                        continue;
                    }
                    $provider = (string) ($m['build_provider'] ?? $provider);
                    if ($provider === \App\Config\AppConfig::PROVIDER_GITLAB_CI && !empty($m['project_id'])) {
                        $projectId = (string) $m['project_id'];
                    } elseif ($provider !== \App\Config\AppConfig::PROVIDER_JENKINS) {
                        $projectId = $job !== '' ? $job : ($cp !== '' ? $cp : $project);
                    }
                    break;
                }
                if ($project === '' || (int) ($row['pipeline_iid'] ?? 0) <= 0 || ($row['tag'] ?? '') === '') {
                    continue;
                }
                $createdAt = (string) ($row['created_at'] ?? '');
                $createdAt = $createdAt !== '' ? $createdAt : null;
                $stmt->execute([
                    $provider,
                    $projectId,
                    (int) $row['pipeline_iid'],
                    $project,
                    (string) ($row['harbor_repository'] ?? ''),
                    (string) $row['tag'],
                    (string) ($row['status'] ?? ''),
                    $createdAt,
                    $createdAt,
                ]);
            }
            if ($started) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if (isset($started) && $started) {
                $pdo->rollBack();
            }
            // 存量迁移失败必须阻止 schema 被标记为完成，让下次启动继续尝试。
            throw $e;
        }

        // 迁移完成（或遗留表本就为空）后删除遗留表，规范事实只剩 ci_pipeline_artifacts。
        self::dropTableIfExists($pdo, $tagTable);
    }

    private function __construct()
    {
    }
}
