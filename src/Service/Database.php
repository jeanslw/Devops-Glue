<?php

namespace App\Service;

class Database
{
    private static ?\PDO $pdo = null;
    private static string $driver = 'sqlite';
    /** @var array<string,mixed> */
    private static array $config = [];
    private static bool $bootstrapped = false;
    /** 探测清单漂移只在本进程内记一次日志（bootstrap() 未置位时会重跑，不去重将反复刷同一条）。 */
    private static bool $schemaListDriftLogged = false;

    /** 列结构对账每进程只执行一次（版本相等短路时也要跑，不能每请求重复 MODIFY）。 */
    private static bool $columnReconcileDone = false;

    /** 返回已引导的 PDO；未引导时抛异常，避免在 null 上调用方法。 */
    private static function pdo(): \PDO
    {
        if (self::$pdo === null) {
            throw new \RuntimeException('Database not bootstrapped: call Database::getPdo() or bootstrap() first');
        }
        return self::$pdo;
    }

    /** ci_app_settings 中记录已应用表结构版本的键（/healthz 探针经 schemaState() 读取，故为 public） */
    public const SCHEMA_VERSION_KEY = 'schema_version';

    /**
     * 手动建库模式（DB_AUTO_MIGRATE=false）下记录种子数据已应用版本的键。
     *
     * 与 SCHEMA_VERSION_KEY 分开：该模式的 DDL 与补列由运维负责，复用 schema_version
     * 会把种子已补齐当成表结构已最新。
     */
    private const SEED_VERSION_KEY = 'seed_version';

    /** 播种模式：按当前代码定义收敛，清掉系统角色的多余映射（升级与显式迁移使用）。 */
    private const SEED_MODE_PRUNE = 'prune';

    /**
     * 播种模式：只补不删，保留新版本授予的映射并补齐当前代码期望的映射，
     * 用于降级回滚时避免收窄系统角色权限。
     */
    private const SEED_MODE_MERGE = 'merge';

    /** 操作日志中版本跃迁的动作名（i18n 键：oplog.act_schema_init / _upgrade / _downgrade）。 */
    private const ACTION_SCHEMA_INIT      = 'schema_init';
    private const ACTION_SCHEMA_UPGRADE   = 'schema_upgrade';
    private const ACTION_SCHEMA_DOWNGRADE = 'schema_downgrade';

    /** 操作日志中 RBAC 种子部分失败的动作名（i18n 键：oplog.act_rbac_seed_failed）。 */
    private const ACTION_RBAC_SEED_FAILED = 'rbac_seed_failed';

    /**
     * 系统级审计在操作日志中的去重窗口（秒）：同 (action, target, channel) 在窗口内只记一条，
     * 避免自动路径（引导 / 建表 / 播种）反复重试时把审计表刷满。
     */
    private const SYSTEM_AUDIT_DEDUPE_WINDOW = 600;

    /** 自动迁移互斥锁（分布式变体）：锁名 / 租约 TTL（16 表 + ~50 条语句，秒级；崩租自动过期防死锁） */
    private const MIGRATION_LOCK_KEY = 'schema_migration';
    private const MIGRATION_LOCK_TTL = 300;

    /** 竞争者等待参数：轮询间隔 / 等待上限——对齐即跳过、超时降级硬跑（幂等） */
    private const MIGRATION_POLL_INTERVAL_SECONDS = 0.5;
    private const MIGRATION_WAIT_TIMEOUT_SECONDS  = 30;

    /** @internal 仅单测缩短竞争等待窗口用（业务代码勿调用）；null = 用上面的默认常量 */
    private static ?int $migrationWaitOverride = null;
    private static ?float $migrationPollOverride = null;

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
     * 职责边界：只管初始化，不管连接（连接由 createPdo() 负责）。
     * 想一次拿齐连接与初始化用 getPdo()；需要拆分二者的场景（如 restore 分阶段）才单独调本方法。
     */
    public static function bootstrap(\PDO $pdo): void
    {
        // 幂等标记：重复调用直接返回，避免重复写库与校验。
        // 仅在全部成功后才置位，中途异常保持可重试。
        if (self::$bootstrapped) {
            return;
        }
        // 一致性自检（纯反射，不写库）：表常量与探测清单漂移时留下日志。
        // 只记日志、不抛异常：清单笔误只影响可见性，建表由 ensureTables() 负责。
        self::logSchemaListDriftIfAny();
        self::$pdo = $pdo;
        if (empty(self::$config)) {
            self::init();
        }
        // 一次读取同时判断是否当前版本与是否降级，后者决定播种模式。
        $needMark     = false;
        $needSeedMark = false;
        $seedMode     = self::SEED_MODE_PRUNE;
        $recorded     = null; // 跃迁前的记录值（null = 首次初始化），供跃迁留痕使用
        if (self::$config['auto_migrate'] ?? true) {
            // 仅在 schema/种子版本与当前代码版本不一致时执行建表 + 种子，
            // 避免每个请求都重复跑 ensureTables/seedRbac 的写库操作。
            $recorded = self::recordedVersion(self::SCHEMA_VERSION_KEY);
            if (!self::isVersionCurrent($recorded)) {
                // 降级（记录版本高于代码版本）用合并播种：只补不删；显式迁移仍走 prune 收敛。
                $seedMode = self::isVersionAhead($recorded) ? self::SEED_MODE_MERGE : self::SEED_MODE_PRUNE;
                self::runExclusively(self::SCHEMA_VERSION_KEY, function () use ($seedMode): void {
                    // 持锁后**重读**标记：另一节点可能刚完成迁移并释放了锁——本次就无需再跑（双检）。
                    if (self::isVersionCurrent(self::recordedVersion(self::SCHEMA_VERSION_KEY))) {
                        return;
                    }
                    self::ensureTables($seedMode); // 建表 + RBAC 种子 + 索引 + JSON 迁移
                });
                $needMark = true;
            }
        } else {
            // 手动建库脚本模式（DB_AUTO_MIGRATE=false）：表由运维脚本创建，应用只补种子。
            // 用独立键 seed_version 短路，避免每请求重复播种；补表或补列请跑 cli/migrate.php
            // 或后台的同步库结构按钮。
            $recorded = self::recordedVersion(self::SEED_VERSION_KEY);
            if (!self::isVersionCurrent($recorded)) {
                $seedMode = self::isVersionAhead($recorded) ? self::SEED_MODE_MERGE : self::SEED_MODE_PRUNE;
                self::runExclusively(self::SEED_VERSION_KEY, function () use ($seedMode): void {
                    // 持锁后重读标记（双检）：另一节点可能刚完成播种并释放了锁——本次就无需再跑
                    if (self::isVersionCurrent(self::recordedVersion(self::SEED_VERSION_KEY))) {
                        return;
                    }
                    self::seedRbac($seedMode); // 表已存在，补/同步种子数据
                });
                $needSeedMark = true;
            }
        }
        // 自愈与种子走公共段：手动建库模式（上方 else 分支只补种子）同样需要把 CD 服务凭据
        // scope 补齐到当前契约，否则手动模式升级后 CD 调新增 scope 的端点会 403。
        self::healCdServiceTokenScopes(self::pdo());
        // 列结构对账只在自动模式自愈：同版本内迭代列定义时，schema_version 已相等会短路掉
        // ensureTables()，没有这一步存量库的列宽漂移永远不会被修。只放宽（安全扩容）不收紧。
        // 手动建库模式 DDL 归运维，不在引导期动结构，漂移由后台系统信息面板暴露。
        if (self::$config['auto_migrate'] ?? true) {
            self::reconcileColumns(self::pdo(), false);
        }
        self::seedAdmin();
        self::verifySeed();
        // 自检通过后才标记版本并记一条跃迁审计；自检异常则不标记、不写审计，下次引导重试。
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
     * 读取版本键的已记录值（ci_app_settings 缺表或缺键时返回 null）。
     *
     * bootstrap() 用一次读取同时判断是否当前版本与是否降级，故本方法只取值、不判方向。
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

    /** 已记录版本是否等于当前代码版本（未记录或不同均为 false，需要建表或补种子）。 */
    private static function isVersionCurrent(?string $recorded): bool
    {
        return $recorded === \App\Config\AppConfig::APP_VERSION;
    }

    /** 标记表结构已应用到当前代码版本。 */
    private static function markSchemaCurrent(): void
    {
        $sql = self::sqlUpsert(
            \App\Config\AppConfig::TABLE_APP_SETTINGS,
            'setting_key, value, updated_at',
            '?, ?, ' . self::sqlNow()
        );
        self::pdo()->prepare($sql)->execute([self::SCHEMA_VERSION_KEY, \App\Config\AppConfig::APP_VERSION]);
    }

    /** 标记当前代码版本的种子已应用（手动建库模式专用，不影响系统信息面板的 schema 版本）。 */
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
     * 记录一次版本跃迁（升级 / 降级 / 首次初始化）：降级时额外写一条文件 WARNING，并统一写操作日志。
     *
     * 只覆盖自动引导产生的跃迁；显式迁移（后台按钮、cli/migrate.php）记录 migrate_schema
     * 操作行，降级方向另由 noteExplicitDowngrade() 留痕。
     *
     * @param string      $key      被改写的版本键（schema_version / seed_version）
     * @param string|null $before   跃迁前的记录值；null = 首次初始化
     * @param string      $seedMode 本次播种模式（merge / prune）
     * @param string      $scope    schema（结构 + 种子）/ seed（手动建库模式，仅补种子）
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
     * 把一次版本跃迁写进操作日志（ci_operation_logs）。
     *
     * 首次初始化与升级记为 success，降级回滚记为 failure；detail 带 scope 与 seed_mode，
     * 便于事后核对当时的权限处理方式。
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
     * 写一条系统级操作日志（操作人 system），按 (action, target, channel) 在
     * SYSTEM_AUDIT_DEDUPE_WINDOW 秒内去重；写入失败不影响引导与迁移。
     *
     * channel 参与去重键：自动引导降级与显式迁移降级后果不同，不应互相吞掉审计行。
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
            // 审计写入失败不影响引导 / 迁移
        }
    }

    /**
     * 显式迁移（后台同步库结构、cli/migrate.php）在降级方向的留痕。
     *
     * 库中版本标记高于当前代码时，迁移会把标记改写回旧版本并按旧定义 prune 系统角色权限，
     * 此处写文件 WARNING 与 schema_downgrade 审计行（detail.channel=explicit_migrate）。
     *
     * 多个版本键同时领先时聚合为一行：WARNING 给出各键版本，审计行 detail.keys 列出全部键，
     * target 取最高版本。
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
        // target 取最高被降级版本，兼作去重键
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
     * 当前代码版本定义的全部数据表（系统信息面板与探针按此清单逐表探测）。
     *
     * 手写清单，新增应用表时必须同步补一行，否则后台数据表清单与 /healthz 的探测结果
     * 会少一张表（DatabaseBootstrapTest 中有对应断言）。
     *
     * @return list<string>
     */
    private static function schemaTables(): array
    {
        return [
            \App\Config\AppConfig::TABLE_JOB_GIT_MAP,
            \App\Config\AppConfig::TABLE_PIPELINE_ARTIFACTS,
            \App\Config\AppConfig::TABLE_BUILD_TAG_CACHE,
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
            // API token 调用审计日志（与 ci_operation_logs 同为 append-only 审计表，
            // ensureTables() 会创建、cleanup-api-access-logs.php 会清理，故必须纳入探测清单）
            \App\Config\AppConfig::TABLE_API_ACCESS_LOGS,
            // 集成服务启用契约表（CI 属主；ensureTables() 真实创建，CD 只读 enabled/provision）
            \App\Config\AppConfig::TABLE_INTEGRATED_SERVICES,
        ];
    }

    /**
     * 一致性自检：探测清单（{@see schemaTables()}）是否与 AppConfig 的表常量对齐。
     *
     * 纯函数：只做反射与集合比较，不建连、不写库、不抛异常。
     *
     * @param list<string>|null $probeList 待比较的探测清单；null 表示使用当前代码的 schemaTables()
     * @return array{
     *     ok: bool,
     *     tables: list<string>,
     *     missing: list<string>,
     *     probe_unknown: list<string>
     * }
     *   - ok            两项漂移均为空
     *   - tables        受影响表名的并集（按 missing、probe_unknown 排列）
     *   - missing       应用自有表未进探测清单，后台表清单与探针会少这张表
     *   - probe_unknown 探测清单中不是任何 TABLE_* 常量值的项
     */
    public static function schemaListDrift(?array $probeList = null): array
    {
        $probe = $probeList ?? self::schemaTables();

        $owned = []; // 应用自有表：AppConfig 全部 TABLE_* 常量
        $ref   = new \ReflectionClass(\App\Config\AppConfig::class);
        foreach ($ref->getConstants() as $name => $value) {
            if (!str_starts_with((string)$name, 'TABLE_') || !is_string($value)) {
                continue;
            }
            $owned[$value] = true;
        }

        $missing      = [];
        foreach (array_keys($owned) as $table) {
            if (!in_array($table, $probe, true)) {
                $missing[] = $table;
            }
        }
        $probeUnknown = [];
        foreach ($probe as $table) {
            if (!isset($owned[$table])) {
                $probeUnknown[] = $table;
            }
        }

        return [
            'ok'            => $missing === [] && $probeUnknown === [],
            'tables'        => array_merge($missing, $probeUnknown),
            'missing'       => $missing,
            'probe_unknown' => $probeUnknown,
        ];
    }

    /**
     * 探测清单漂移时记一条 error 日志（进程内只记一次），不抛异常：
     * 漂移只影响清单可见性，不影响建表。
     */
    private static function logSchemaListDriftIfAny(): void
    {
        if (self::$schemaListDriftLogged) {
            return;
        }
        $drift = self::schemaListDrift();
        if ($drift['ok']) {
            return;
        }
        self::$schemaListDriftLogged = true;
        \App\Helper\Log::error('schema 探测清单与 AppConfig 表常量不一致（后台「数据表清单」可能少表）', [
            'missing_from_probe' => $drift['missing'],
            'probe_unknown'      => $drift['probe_unknown'],
            'hint'               => '请在 Database::schemaTables() 补上 AppConfig 中缺失的表（新增应用表时必须同步）；'
                . 'CI 由 DatabaseBootstrapTest::testSchemaProbeListCoversEveryAppOwnedTable 守着该不变量',
        ]);
    }

    /**
     * 系统信息：DB 驱动 + schema 版本 + 各核心表存在性（供后台系统信息面板只读查询）
     *
     * @return array{
     *     driver: string,
     *     schema_version: string|null,
     *     app_version: string,
     *     is_current: bool,
     *     auto_migrate: bool,
     *     tables: array<string, bool>,
     *     schema_list_drift: array{
     *         ok: bool,
     *         tables: list<string>,
     *         missing: list<string>,
     *         probe_unknown: list<string>
     *     },
     *     column_drift: array{
     *         safe_count: int,
     *         unsafe_count: int,
     *         items: list<array{table:string, column:string, actual:string, expected:string, safe:bool}>
     *     }
     * }
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
        $columnDrift = self::columnDrift(self::pdo());
        $safeCount   = count(array_filter($columnDrift, static fn (array $d): bool => $d['safe']));
        return [
            'driver'         => self::$driver,
            'schema_version' => $recorded,
            'app_version'    => \App\Config\AppConfig::APP_VERSION,
            'is_current'     => ($recorded !== null && $recorded === \App\Config\AppConfig::APP_VERSION),
            // DB_AUTO_MIGRATE 实际生效值：后台系统信息的迁移模式行按此渲染
            'auto_migrate'   => self::isAutoMigrateEnabled(),
            'tables'         => $status,
            // 探测清单自检结果（纯反射，与库无关）：后台系统信息的数据表一致性行按此渲染。
            'schema_list_drift' => self::schemaListDrift(),
            // 列结构对账（查实际库）：safe 漂移会在自动模式引导时自愈，unsafe 仅提示人工评估。
            'column_drift' => [
                'safe_count'   => $safeCount,
                'unsafe_count' => count($columnDrift) - $safeCount,
                'items'        => $columnDrift,
            ],
        ];
    }

    /**
     * 读取 schema / 种子版本并判定是否与当前代码对齐（供 /healthz 探针一次查询拿到全部版本语义）。
     *
     * 不建连、不 bootstrap（PDO 由调用方传入）；ci_app_settings 缺失时抛异常，由调用方判定库不可用。
     *
     * schema_current 只反映表结构：有 schema_version 时与 APP_VERSION 等值比较，无记录时为 null（未知）。
     * 手动建库模式下 seed_version 仅证明种子数据已写入，不能代表新增列已就绪，故不作为依据。
     *
     * schema_ahead 是方向位，与 schema_current 组合可区分两种未对齐：
     * - schema_current=false + schema_ahead=false：代码比库新，需执行结构迁移；
     * - schema_current=false + schema_ahead=true ：库比代码新，典型场景是代码回滚。
     *
     * @param \PDO $pdo 调用方自建连接
     * @return array{
     *     schema_version: string|null,
     *     seed_version: string|null,
     *     schema_current: bool|null,
     *     schema_ahead: bool
     * }
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
     * 已记录的结构版本是否高于当前代码版本（方向位，/healthz 与 bootstrap() 共用）。
     *
     * 无记录时返回 false。用 version_compare 比较，避免 '2.20.0' 与 '2.3.0' 的字典序问题。
     */
    private static function isVersionAhead(?string $recorded): bool
    {
        return $recorded !== null
            && version_compare($recorded, \App\Config\AppConfig::APP_VERSION, '>');
    }

    /**
     * 已应用的表结构是否对齐当前代码版本。
     *
     * 只认 schema_version（仅由执行过 DDL 的路径写入），无记录时返回 null（未知），不接收 seed_version。
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
     * 只读预检：计算若现在迁移会补哪些表 / 哪些列（不建连、不 bootstrap、不写库）。
     *
     * 与 ensureTables() 同源（columnMigrations() + schemaTables() + columnSpecs()），结论与实跑一致；
     * 供 cli/migrate.php --dry-run 使用。覆盖缺失表、缺失列与列类型漂移（mismatched_columns）：
     * 其中 safe=true 的安全扩容会随迁移对齐，unsafe 仅由 dry-run 提示人工评估，迁移不改动。
     * 索引与数据搬迁随迁移幂等执行，表不存在时整表新建，其列不单独报告。
     *
     * @param \PDO $pdo 调用方自建连接
     * @return array{
     *     missing_tables: list<string>,
     *     missing_columns: array<string, list<string>>,
     *     mismatched_columns: list<array{table:string, column:string, actual:string, expected:string, safe:bool}>
     * }
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
            'missing_tables'     => self::missingTables($pdo),
            'missing_columns'    => $missingColumns,
            'mismatched_columns' => self::columnDrift($pdo),
        ];
    }

    /**
     * 存量库增列映射：表 => 列 => 该驱动的列定义。新增列时在此追加一项。
     *
     * 由 ensureTables() 的补列循环与 pendingChanges() 的只读预检共用，避免两处清单漂移；
     * 类型按驱动解析（SQLite 无 VARCHAR 概念）。
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
            \App\Config\AppConfig::TABLE_BUILD_TAG_CACHE => [
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
                'must_change_password' => ($isMySQL ? 'TINYINT' : 'INTEGER') . ' NOT NULL DEFAULT 0', // 首次登录强制改密（seed 建号置 1）
                // 注：id 主键不通过此 ALTER 自动补——MySQL 中 AUTO_INCREMENT 主键列无法幂等补加、
                // 且涉及旧主键（username）变更风险；存量库 id 主键由部署方手动迁移。
            ],

            // user_identities 为 v2.6.3 新增整表，不存在存量列；若后续给该表新加列再据此追加
            \App\Config\AppConfig::TABLE_USER_IDENTITIES => [
            ],
        ];
    }

    /**
     * 列结构对账清单（仅 MySQL）：表 => 列 => 完整 MODIFY 列子句。
     *
     * columnMigrations() 解决「列不存在」（ADD COLUMN），本清单解决「列存在但类型/宽度落后于
     * 建表定义」——典型场景：未发布版本内多轮 DDL，schema_version 已与代码相等，bootstrap 短路，
     * 存量库的列定义停留在旧宽度（varchar(32) vs 建表脚本 varchar(255)）。
     *
     * 维护约定：
     *  - 改 mysql_init.sql 中既有列的类型/宽度时，在此同步登记；SQLite 无长度语义，不登记；
     *  - 只登记可安全放宽的变更（如 VARCHAR 扩容）。缩容、跨类型族即使登记也只在面板告警，
     *    不会自动执行（safe=false），杜绝数据截断；
     *  - 登记的期望类型必须与 mysql_init.sql 的 CREATE TABLE 一致，由
     *    DatabaseBootstrapTest 守护（清单与脚本漂移则测试失败）。
     *
     * @return array<string, array<string, string>>
     */
    private static function columnSpecs(): array
    {
        return [
            \App\Config\AppConfig::TABLE_ADMIN_USERS => [
                'role'    => "VARCHAR(255) NOT NULL DEFAULT '" . \App\Config\AppConfig::ROLE_ADMIN . "'",
                'systems' => "VARCHAR(255) NOT NULL DEFAULT 'ci,cd'",
            ],
        ];
    }

    /**
     * 只读对账：列存在但实际类型与 columnSpecs() 期望不一致的清单。
     *
     * safe=true 仅当类型族相同且宽度只增（如 varchar(32)→varchar(255)）；其余漂移
     * （缩容 varchar(512)→varchar(255)、跨类型族 text→varchar 等）safe=false，只报告。
     * SQLite 无 VARCHAR 长度概念（类型亲和且不截断），列宽漂移无实际危害，返回空集。
     *
     * @param \PDO $pdo 调用方自建连接
     * @return list<array{table:string, column:string, actual:string, expected:string, definition:string, safe:bool}>
     */
    public static function columnDrift(\PDO $pdo): array
    {
        if (self::$driver !== 'mysql') {
            return [];
        }
        $drift = [];
        foreach (self::columnSpecs() as $table => $columns) {
            if (!self::tableExists($pdo, $table)) {
                continue; // 整表缺失由 missing_tables 兜住
            }
            $stmt = $pdo->prepare(
                'SELECT COLUMN_TYPE FROM information_schema.COLUMNS '
                . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
            );
            foreach ($columns as $column => $definition) {
                $stmt->execute([$table, $column]);
                $actual = $stmt->fetchColumn();
                if ($actual === false) {
                    continue; // 列不存在由 missing_columns / ADD COLUMN 循环兜住
                }
                $actualType   = self::normalizeColumnType((string) $actual);
                $expectedType = self::normalizeColumnType(self::firstTypeToken($definition));
                if ($actualType === $expectedType) {
                    continue;
                }
                $drift[] = [
                    'table'      => $table,
                    'column'     => $column,
                    'actual'     => $actualType,
                    'expected'   => $expectedType,
                    'definition' => $definition,
                    'safe'       => self::isWidenVarchar($actualType, $expectedType),
                ];
            }
        }
        return $drift;
    }

    /** 取列定义子句的首个 token（类型），如 "VARCHAR(255) NOT NULL DEFAULT 'x'" → "VARCHAR(255)"。 */
    private static function firstTypeToken(string $definition): string
    {
        $parts = explode(' ', trim($definition), 2);
        return $parts[0];
    }

    /** 归一化类型 token：小写、去空白（varchar(255) 各部分间可能有空格）。 */
    private static function normalizeColumnType(string $type): string
    {
        return strtolower(preg_replace('/\s+/', '', $type) ?? $type);
    }

    /**
     * 是否为同类 VARCHAR 扩容（actual → expected，长度只增不减）。
     * 非 varchar 或期望长度更小（缩容）均返回 false。
     */
    private static function isWidenVarchar(string $actual, string $expected): bool
    {
        if (
            !preg_match('/^varchar\((\d+)\)$/', $actual, $a)
            || !preg_match('/^varchar\((\d+)\)$/', $expected, $b)
        ) {
            return false;
        }
        return (int) $b[1] >= (int) $a[1];
    }

    /**
     * 列结构自愈：对 columnDrift() 中 safe=true 的漂移执行 MODIFY COLUMN。
     *
     * 每进程只执行一次（$columnReconcileDone）；表/列名来自代码常量与白名单清单，不接受外部输入。
     * strict=true（ensureTables / 显式迁移链路）：任一 MODIFY 失败抛出，与 ADD COLUMN 循环同为
     * fail-fast，尚未 markSchemaCurrent，下次启动重试。
     * strict=false（bootstrap 公共段，含版本相等短路路径）：失败仅记 error 日志，不阻断业务请求；
     * 成功对齐记 warning 留痕（结构被自动变更，运维应可见），漂移仍在系统信息面板展示。
     *
     * @return list<array{table:string, column:string, actual:string, expected:string}> 本次已对齐的列
     */
    public static function reconcileColumns(\PDO $pdo, bool $strict): array
    {
        if (self::$columnReconcileDone) {
            return [];
        }
        self::$columnReconcileDone = true;

        $fixed = [];
        foreach (self::columnDrift($pdo) as $d) {
            if (!$d['safe']) {
                \App\Helper\Log::warning('列结构存在不可自动修复的漂移（需人工评估，未执行任何 DDL）', [
                    'table' => $d['table'],
                    'column' => $d['column'],
                    'actual' => $d['actual'],
                    'expected' => $d['expected'],
                ]);
                continue;
            }
            try {
                $pdo->exec("ALTER TABLE {$d['table']} MODIFY COLUMN {$d['column']} {$d['definition']}");
                $fixed[] = [
                    'table' => $d['table'],
                    'column' => $d['column'],
                    'actual' => $d['actual'],
                    'expected' => $d['expected'],
                ];
            } catch (\Throwable $e) {
                if ($strict) {
                    throw $e;
                }
                \App\Helper\Log::error('列结构自动对齐失败，不阻断启动（可在后台手动同步库结构重试）', [
                    'table' => $d['table'],
                    'column' => $d['column'],
                    'error' => $e->getMessage(),
                ]);
            }
        }
        if ($fixed !== []) {
            \App\Helper\Log::warning('列结构自动对齐完成（安全扩容 MODIFY）', ['fixed' => $fixed]);
        }
        return $fixed;
    }

    /**
     * 手动触发迁移：建缺失表 + 补列 + 安全扩容列对齐 + RBAC/管理员种子 + 自检 + 标记 schema 当前。
     *
     * 入口：后台同步库结构按钮（super_admin 专用）、cli/migrate.php（发布流水线，无视
     * DB_AUTO_MIGRATE）。自带连接，空库或手动建库模式漏跑脚本时也能一次建全。
     * 返回值同 schemaStatus()（含迁移后重新对账的 column_drift，正常应为零项）。
     *
     * @return array{
     *     driver: string,
     *     schema_version: string|null,
     *     app_version: string,
     *     is_current: bool,
     *     auto_migrate: bool,
     *     tables: array<string, bool>,
     *     schema_list_drift: array{ok:bool, tables:list<string>, missing:list<string>, probe_unknown:list<string>},
     *     column_drift: array{safe_count:int, unsafe_count:int, items:list<array<string, mixed>>}
     * }
     */
    public static function migrateNow(): array
    {
        self::connect();           // 迁移入口自带连接：不依赖既有 bootstrap 状态（空库/手动模式也能直接迁移）
        // 改写标记前先取跃迁前的记录值：库标记高于代码版本（回滚后按 SOP 用旧代码迁移）
        // 属于降级方向的显式动作，需与自动引导同等留痕。
        $prevSchema = self::recordedVersion(self::SCHEMA_VERSION_KEY);
        $prevSeed   = self::recordedVersion(self::SEED_VERSION_KEY);
        self::ensureTables();      // 建表 + 旧表名 RENAME + RBAC 种子 + 索引 + JSON 迁移
        self::healCdServiceTokenScopes(self::pdo()); // CD 服务凭据 scope 自愈（与 bootstrap 公共段一致）
        self::seedAdmin();          // 补管理员种子（与 bootstrap() 一致，缺管理员时自愈）
        self::verifySeed();         // 自检种子不变量，缺失直接抛异常，不标记当前
        self::markSchemaCurrent();  // 自检通过后才标记当前版本
        self::markSeedCurrent();    // 同步种子版本：手动模式随后的首次引导即短路，不再重复播种
        self::noteExplicitDowngrade([self::SCHEMA_VERSION_KEY => $prevSchema, self::SEED_VERSION_KEY => $prevSeed]);
        return self::schemaStatus();
    }

    /**
     * 迁移失败归因：区分账号无 DDL 权限与其它失败，便于调用方给出可操作的提示。
     *
     * 纯函数，便于单测；调用方传 PDOException 的 getMessage() / getCode()（SQLSTATE）/ errorInfo[1]。
     *
     * @param string $message    驱动错误消息（大小写不敏感）
     * @param string $sqlState   SQLSTATE（\PDOException::getCode()）
     * @param int    $driverCode 驱动错误码（\PDOException::errorInfo[1]）
     * @return string 'ddl_denied'（无 DDL 权限）| 'unreachable'（库不可达或凭据被拒）| 'other'
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
        // SQLite：只读库 / 只读文件，与缺 DDL 权限同类处置
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
        // 无驱动码时退回 SQLSTATE 兜底。42000 同时覆盖语法错误、重复列、表已存在与权限拒绝，
        // 必须再用消息关键字确认是权限类，否则会把普通迁移失败误判为账号缺权限。
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

    /** 读取配置：优先 $_ENV（phpdotenv 填充），其次 getenv()（variables_order 不含 E 时仍可用）。 */
    private static function envValue(string $key, string $default = ''): string
    {
        if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
            return (string)$_ENV[$key];
        }
        $v = getenv($key);
        return $v === false ? $default : (string)$v;
    }

    /** 解析 DB_AUTO_MIGRATE：0/false/no/off/空 视为关闭，其余视为开启。 */
    private static function parseAutoMigrate(): bool
    {
        return !in_array(strtolower(trim(self::envValue('DB_AUTO_MIGRATE', 'true'))), ['0', 'false', 'no', 'off', ''], true);
    }

    /**
     * @return array{
     *     driver: string,
     *     path: string,
     *     host: string,
     *     port: string,
     *     database: string,
     *     username: string,
     *     password: string,
     *     charset: string,
     *     auto_migrate: bool
     * }
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
     * 返回已初始化的连接：createPdo() + bootstrap()，进程内只执行一次。
     *
     * 连接与驱动由 init() 的静态配置决定，缓存不区分连接参数；同一进程切换库（测试 / CLI）
     * 须先 reset()。
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
     * 仅建立连接并保存为内部句柄，不执行 bootstrap()。
     *
     * 供迁移入口使用：表尚未就绪时必须先拿到连接才能建表，走 getPdo() 会在补种子阶段抛异常。
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
        self::$schemaListDriftLogged = false;
        self::$columnReconcileDone = false;
    }

    public static function driver(): string
    {
        return self::$driver;
    }

    /**
     * 当前是否为自动建表模式（DB_AUTO_MIGRATE 未显式关闭）。
     *
     * 供 cli/db-init.php 在初始化失败时区分模式并给出提示；配置未初始化时直接读环境变量。
     */
    public static function isAutoMigrateEnabled(): bool
    {
        if (!empty(self::$config)) {
            return (bool)(self::$config['auto_migrate'] ?? true);
        }
        return self::parseAutoMigrate();
    }

    // ── 分布式锁（基于 ci_cache 的租约锁，用于定时任务单实例化）──

    /**
     * 互斥执行自动迁移（bootstrap 自动分支）：多实例同时发现版本标记过期时，保证同一时刻只有一个
     * 实例跑 DDL / 种子，其余实例等待其完成——消除「补列 check-then-act 撞 1060」「并发 REPLACE
     * 死锁 1213 触发 rbac_seed_failed 误报」这类启动期竞争噪声。
     *
     * 三种路径：
     *  - 抢到锁（持有者）：执行 $critical，finally 释放（TTL 兜底，崩租自动过期）；
     *  - 抢不到且 ci_cache 表存在（竞争者）：轮询 $waitKey 版本标记，对齐即跳过（另一节点已完成）；
     *    每轮顺带重试抢锁（持有者崩溃 / 租约过期后接管）；等待超时降级为硬跑——所有写操作幂等，
     *    行为等同无锁主干，绝不因锁卡死启动；
     *  - 抢不到且 ci_cache 表不存在（空库首启 / 锁表异常，无从互斥）：直接硬跑，不为不可能的
     *    竞争空等 30 秒——与主干单机行为完全一致。
     *
     * $critical 自带双检（持锁后重读版本标记）：接管场景下若上一持有者已完成则跳过。
     *
     * @param string  $waitKey   竞争者轮询对齐的版本键（自动模式 = SCHEMA_VERSION_KEY、手动模式 = SEED_VERSION_KEY）
     * @param \Closure $critical  需要互斥执行的迁移动作
     */
    private static function runExclusively(string $waitKey, \Closure $critical): void
    {
        $token = self::tryAcquireLock(self::MIGRATION_LOCK_KEY, self::MIGRATION_LOCK_TTL);
        if ($token !== null) {
            try {
                $critical();
            } finally {
                self::releaseLock(self::MIGRATION_LOCK_KEY, $token);
            }
            return;
        }
        if (!self::lockTableAvailable()) {
            $critical(); // 空库首启：无从互斥，直接硬跑（幂等）
            return;
        }
        $timeout  = self::$migrationWaitOverride ?? self::MIGRATION_WAIT_TIMEOUT_SECONDS;
        $interval = self::$migrationPollOverride ?? self::MIGRATION_POLL_INTERVAL_SECONDS;
        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            usleep((int) round($interval * 1000000));
            if (self::isVersionCurrent(self::recordedVersion($waitKey))) {
                return; // 另一节点已完成迁移，本次跳过
            }
            $token = self::tryAcquireLock(self::MIGRATION_LOCK_KEY, self::MIGRATION_LOCK_TTL);
            if ($token !== null) {
                try {
                    $critical(); // 接管：上一持有者已崩 / 租约过期（闭包双检决定是否真的要跑）
                } finally {
                    self::releaseLock(self::MIGRATION_LOCK_KEY, $token);
                }
                return;
            }
        }
        $critical(); // 等待超时降级：幂等硬跑，绝不因锁卡死启动
    }

    /** ci_cache 表当前是否可读（区分「锁被他人持有」与「空库首启无从互斥」） */
    private static function lockTableAvailable(): bool
    {
        try {
            self::getPdo()->query('SELECT 1 FROM ' . \App\Config\AppConfig::TABLE_CACHE . ' LIMIT 1');
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @internal 仅单测缩短竞争等待窗口（真实默认 30s/0.5s 会拖慢测试）；传 null 恢复默认。
     */
    public static function setMigrationWaitForTest(?int $timeoutSeconds, ?float $pollSeconds): void
    {
        self::$migrationWaitOverride = $timeoutSeconds;
        self::$migrationPollOverride = $pollSeconds;
    }

    /**
     * 尝试获取分布式锁（租约）：成功返回持有者 token（非空字符串），失败返回 null。
     *
     * 跨实例共享：锁以 ci_cache 的 cache_key 为主键，MySQL 多实例 / SQLite 共享单文件下均生效，
     * 使「单 worker 容器 + DB 锁」的定时任务拆分在多实例部署下仍保证同一时刻只有一个实例执行。
     * 抢占协议：
     *   1) 先删除「已过期」的租约（expires_at <= 当前时间，即持有者异常退出 / 任务超时未续约）；
     *   2) 再用普通 INSERT 写入自己的租约——唯一键冲突说明存在有效锁，返回 null。
     *      （不能用 sqlUpsert/REPLACE：那会在冲突时删除旧行再插入，等于「偷锁」，破坏互斥。）
     *   value 存持有者 token（host:pid:随机），供 releaseLock 精确释放，避免误删他者已接管的锁。
     * ttl 由调用方按单轮任务耗时给出；任务超时后锁自动过期，下个调度周期可被接管，无死锁。
     */
    public static function tryAcquireLock(string $name, int $ttlSeconds = 600): ?string
    {
        try {
            $pdo   = self::getPdo();
            $key   = 'lock:' . $name;
            $now   = time();
            $token = gethostname() . ':' . getmypid() . ':' . bin2hex(random_bytes(4));
            $pdo->prepare("DELETE FROM " . \App\Config\AppConfig::TABLE_CACHE . " WHERE cache_key = ? AND expires_at <= ?")
                ->execute([$key, $now]);
            $pdo->prepare("INSERT INTO " . \App\Config\AppConfig::TABLE_CACHE . " (cache_key, value, expires_at) VALUES (?, ?, ?)")
                ->execute([$key, $token, $now + $ttlSeconds]);
            return $token;
        } catch (\Throwable $e) {
            // 唯一键冲突（已有有效锁）或库异常：安全降级为「不抢锁」。
            return null;
        }
    }

    /**
     * 释放锁：仅当锁仍由本 token 持有时删除（防止任务超时被接管后误删他者的锁）。
     * 释放失败不致命——锁会在 ttl 到期后自动过期。
     */
    public static function releaseLock(string $name, string $token): void
    {
        try {
            $pdo = self::getPdo();
            $pdo->prepare("DELETE FROM " . \App\Config\AppConfig::TABLE_CACHE . " WHERE cache_key = ? AND value = ?")
                ->execute(['lock:' . $name, $token]);
        } catch (\Throwable $e) {
            // 忽略：锁自动过期兜底
        }
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

    /** 列是否已存在（MySQL / SQLite）；PDO 显式传入，便于只读预检复用。 */
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

    /** 表是否已存在（MySQL / SQLite）。 */
    private static function tableExists(\PDO $pdo, string $table): bool
    {
        try {
            $pdo->query('SELECT 1 FROM ' . $table . ' LIMIT 1');
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** 删除遗留表（不存在则跳过）；失败仅记录，下次启动重试。 */
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

        // v2.9.0 表名收口：先把存量旧表名 RENAME 到 ci_ 前缀，再走 CREATE IF NOT EXISTS——
        // 存量库改名后建表全部跳过，新装库无旧表直接建新名，两条路径汇合同一结构。
        self::renameLegacyTables($pdo);

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

        // ci_pipeline_artifacts：Pipeline 到 Primary Artifact（1:1）。
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
            must_change_password " . ($isMySQL ? 'TINYINT' : 'INTEGER') . " NOT NULL DEFAULT 0,
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

        // ci_build_tag_cache：镜像 Tag 日志的懒解析缓存（sha 到 tag）。
        // sha 内容寻址、全局唯一，故为唯一键；project_key/pipeline_id 仅作溯源参考。
        // provider/project_id/repository 记录 canonical identity 与 harbor 仓库，供回填 cron
        // 直接写入 ci_pipeline_artifacts（无需重跑 provider 解析）。
        $pdo->exec("CREATE TABLE IF NOT EXISTS " . \App\Config\AppConfig::TABLE_BUILD_TAG_CACHE . " (
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

        // implied_rules 表：权限隐含关系（source_key 到 target_key），运行时可由 CD 通过 API 注册
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

        // integrated_services：集成服务启用契约表（CI 唯一写入方，CD 只读 enabled/provision）。
        // 在 api_tokens 之后创建：api_token_id 软引用 api_tokens(id)，凭据的签发与撤销在
        // ApiTokenService，本表只登记服务配套的凭据；删除 token 时外键置空，启用行保留。
        // enabled=0 仅冻结集成服务，不删除任何数据。
        $isvcTable = \App\Config\AppConfig::TABLE_INTEGRATED_SERVICES;
        if ($isMySQL) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS {$isvcTable} (
                id {$PK},
                service_name VARCHAR(32) NOT NULL UNIQUE,
                enabled TINYINT NOT NULL DEFAULT 0,
                provision VARCHAR(16) NOT NULL DEFAULT '" . \App\Config\AppConfig::SERVICE_PROVISION_AUTO . "',
                api_token_id INT DEFAULT NULL,
                updated_by VARCHAR(64) DEFAULT '',
                created_at {$TS_TYPE} DEFAULT ({$NOW}),
                updated_at {$TS_TYPE} DEFAULT ({$NOW}),
                CONSTRAINT fk_isvc_token FOREIGN KEY (api_token_id)
                    REFERENCES " . \App\Config\AppConfig::TABLE_API_TOKENS . "(id) ON DELETE SET NULL
            ){$ENGINE}");
        } else {
            $pdo->exec("CREATE TABLE IF NOT EXISTS {$isvcTable} (
                id {$PK},
                service_name TEXT NOT NULL UNIQUE,
                enabled INTEGER NOT NULL DEFAULT 0,
                provision TEXT NOT NULL DEFAULT '" . \App\Config\AppConfig::SERVICE_PROVISION_AUTO . "',
                api_token_id INTEGER DEFAULT NULL,
                updated_by TEXT DEFAULT '',
                created_at {$TS_TYPE} DEFAULT ({$NOW}),
                updated_at {$TS_TYPE} DEFAULT ({$NOW}),
                FOREIGN KEY (api_token_id)
                    REFERENCES " . \App\Config\AppConfig::TABLE_API_TOKENS . "(id) ON DELETE SET NULL
            )");
        }

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

        // 列已存在但类型/宽度落后于建表定义（同版本内迭代 DDL、跨版本升级都可能发生）：
        // 只对 columnSpecs() 登记的安全扩容执行 MODIFY；缩容/跨类型族只在对账结果里告警。
        // 与上方 ADD 循环同属 fail-fast：失败抛出，尚未 markSchemaCurrent，下次引导重试。
        self::reconcileColumns($pdo, true);

        // 种子数据：RBAC（权限定义 / 隐含规则 / 系统角色 / 角色↔权限），幂等可重复执行
        // seedMode 透传：降级回滚时用 merge（只补不删），避免回滚把系统角色权限收窄。
        self::seedRbac($seedMode);

        // ── 索引（跨驱动幂等：MySQL 无 IF NOT EXISTS，先查 information_schema）──
        self::createIndex('idx_pipeline_artifacts_project_key', \App\Config\AppConfig::TABLE_PIPELINE_ARTIFACTS, 'project_key');
        self::createIndex('idx_pipeline_artifacts_created', \App\Config\AppConfig::TABLE_PIPELINE_ARTIFACTS, 'created_at');
        self::createIndex('idx_job_git_map_current_path', \App\Config\AppConfig::TABLE_JOB_GIT_MAP, $isMySQL ? 'current_path(255)' : 'current_path');
        self::createIndex('idx_security_checks_project', \App\Config\AppConfig::TABLE_SECURITY_CHECKS, 'project, check_type');
        self::createIndex('idx_security_checks_sha', \App\Config\AppConfig::TABLE_SECURITY_CHECKS, 'sha');
        self::createIndex('idx_custom_builds_job', \App\Config\AppConfig::TABLE_CUSTOM_BUILDS, 'job_name');
        self::createIndex('idx_custom_builds_status', \App\Config\AppConfig::TABLE_CUSTOM_BUILDS, 'status');
        self::createIndex('idx_operation_logs_created', \App\Config\AppConfig::TABLE_OPERATION_LOGS, 'created_at');
        self::createIndex('idx_operation_logs_user', \App\Config\AppConfig::TABLE_OPERATION_LOGS, 'username');
        self::createIndex('idx_operation_logs_action', \App\Config\AppConfig::TABLE_OPERATION_LOGS, 'action');
        self::createIndex('idx_api_access_logs_created', \App\Config\AppConfig::TABLE_API_ACCESS_LOGS, 'created_at');
        self::createIndex('idx_api_access_logs_token', \App\Config\AppConfig::TABLE_API_ACCESS_LOGS, 'token_name');
        self::createIndex('idx_api_access_logs_result', \App\Config\AppConfig::TABLE_API_ACCESS_LOGS, 'result');
        // user_identities.username 查询索引：MySQL 的 CREATE TABLE 已带内联 KEY idx_username，
        // SQLite 建表语句无索引，需与 sqlite_init.sql 一致单独补建
        if (!$isMySQL) {
            self::createIndex('idx_user_identities_username', \App\Config\AppConfig::TABLE_USER_IDENTITIES, 'username');
        }

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
     * 表名收口迁移：把存量旧表名 RENAME 到新名（幂等；v2.9.0 加 ci_ 前缀、后续正名改名共用）。
     *
     * 触发条件：旧表存在且新表不存在。MySQL / SQLite 同用 ALTER TABLE … RENAME TO 语法；
     * 两驱动的数据、索引随表移动，引用方的外键定义自动跟随改写（integrated_services 的
     * api_token_id → api_tokens 在任一改名顺序下都不会悬空）。
     * 新旧表并存（新表已手工建好而旧表未清）时不改名、记告警，以新表为准。
     * 任一表改名失败即抛出：改名只做一半会让种子/鉴权写进旧表，比启动失败更难排查。
     */
    private static function renameLegacyTables(\PDO $pdo): void
    {
        foreach (\App\Config\AppConfig::LEGACY_TABLE_NAMES as $old => $new) {
            if (!self::tableExists($pdo, $old)) {
                continue; // 新装库 / 已迁移库：无旧表
            }
            if (self::tableExists($pdo, $new)) {
                \App\Helper\Log::warning('改名迁移跳过：新旧表并存，以新表为准', ['old' => $old, 'new' => $new]);
                continue;
            }
            $pdo->exec("ALTER TABLE {$old} RENAME TO {$new}");
            \App\Helper\Log::error('表名收口迁移完成', ['old' => $old, 'new' => $new]);
        }
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
     * 把 CD 契约行绑定的服务凭据 scope 补齐到 AppConfig::CD_SERVICE_TOKEN_SCOPES。
     *
     * 为什么必须自愈：v2.9.0 给 CD 服务凭据新增了 git scope（CD 构建页改走 /api/git/{path}/refs
     * 取 ref 候选值）。2.8.x 签发的存量凭据不含 git，若只改常量，旧部署升级后 CD 调 git 端点会被
     * AuthMiddleware 挡成 403，而管理员并不知道需要手动去「集成服务」页禁用再启用一次。
     *
     * 补齐只增不删（ApiTokenService::addScopes：并集、已是超集则不写库），token 摘要与明文都不动，
     * 因此 CD 侧无需更换 CI_API_TOKEN。未启用过 CD 时没有契约行，直接跳过（不种子、不建凭据）。
     */
    private static function healCdServiceTokenScopes(\PDO $pdo): void
    {
        try {
            $linkTable = \App\Config\AppConfig::TABLE_INTEGRATED_SERVICES;
            if (
                !self::tableExists($pdo, $linkTable)
                || !self::tableExists($pdo, \App\Config\AppConfig::TABLE_API_TOKENS)
            ) {
                return;
            }

            $stmt = $pdo->prepare(
                'SELECT api_token_id FROM ' . $linkTable . ' WHERE service_name = ? LIMIT 1'
            );
            $stmt->execute([\App\Config\AppConfig::SERVICE_CD]);
            $tokenId = $stmt->fetchColumn();
            if ($tokenId === false || (int) $tokenId <= 0) {
                return;
            }

            (new ApiTokenService($pdo))->addScopes((int) $tokenId, \App\Config\AppConfig::CD_SERVICE_TOKEN_SCOPES);
        } catch (\Throwable $e) {
            // 属能力补齐而非结构变更：失败不阻断启动，仅记告警，管理员在集成服务页重新启用即可补齐
            \App\Helper\Log::error('healCdServiceTokenScopes 失败', ['error' => $e->getMessage()]);
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

        /** @var list<array{step:string,key:string,error:string}> 部分失败项；循环结束后统一处理 */
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

        // 种子数据：系统角色（幂等，is_system 由 DEFAULT_SYSTEM_ROLES 决定）。
        // 不能用 sqlUpsert（REPLACE INTO）：roles.name 为 UNIQUE，REPLACE 会删旧行插新行，
        // 自增 id 变化会使 role_permissions 中引用旧 id 的行变成孤儿；
        // 故查得到就 UPDATE（保留 id），查不到才 INSERT。
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
        // 用 INSERT IGNORE 而非裸 INSERT：web / worker 容器并发播种时会同时先 DELETE 再
        // INSERT 同一批 (role_id, perm_key)，裸 INSERT 会撞联合主键报重复；IGNORE 让后到者跳过。
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

        // 部分失败必须抛出异常，而不是记日志继续：权限只播一半表现为按钮缺失，易被当成功能 bug。
        // 抛异常后 bootstrap() 不会标记版本，下次引导会重试整个 ensureTables()，并写文件日志与操作日志。
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

        // 清扫孤儿行：早期 REPLACE INTO 换 id 遗留的 role_id 悬空引用；role_id NOT IN (roles.id)
        // 即孤儿定义，不会误删有效行。每次播种执行，存量库下次发版即被清理。
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
     * 针对关键不变量校验，缺失时直接抛异常，使问题在启动阶段暴露。
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
