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

    /**
     * 最近一次 ensureTables() 的逐表建表结果（成功/失败），供迁移 CLI/后台渲染过程列表。
     *
     * @var list<array{table:string, ok:bool, error:string|null}>
     */
    private static array $tableBuildLog = [];

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

        if (self::$bootstrapped) {
            return;
        }

        self::logSchemaListDriftIfAny();
        self::$pdo = $pdo;
        if (empty(self::$config)) {
            self::init();
        }

        $needMark     = false;
        $needSeedMark = false;
        $seedMode     = self::SEED_MODE_PRUNE;
        $recorded     = null;
        if (self::$config['auto_migrate'] ?? true) {
            $recorded = self::recordedVersion(self::SCHEMA_VERSION_KEY);
            if (!self::isVersionCurrent($recorded)) {
                $seedMode = self::isVersionAhead($recorded) ? self::SEED_MODE_MERGE : self::SEED_MODE_PRUNE;
                self::runExclusively(self::SCHEMA_VERSION_KEY, function () use ($seedMode): void {

                    if (self::isVersionCurrent(self::recordedVersion(self::SCHEMA_VERSION_KEY))) {
                        return;
                    }
                    self::ensureTables($seedMode);
                });
                $needMark = true;
            }
        } else {
            $recorded = self::recordedVersion(self::SEED_VERSION_KEY);
            if (!self::isVersionCurrent($recorded)) {
                $seedMode = self::isVersionAhead($recorded) ? self::SEED_MODE_MERGE : self::SEED_MODE_PRUNE;
                self::runExclusively(self::SEED_VERSION_KEY, function () use ($seedMode): void {

                    if (self::isVersionCurrent(self::recordedVersion(self::SEED_VERSION_KEY))) {
                        return;
                    }
                    self::seedRbac($seedMode);
                });
                $needSeedMark = true;
            }
        }

        self::healCdServiceTokenScopes(self::pdo());
        self::migrateScopeHarborReadToRegistryRead(self::pdo());

        if (self::$config['auto_migrate'] ?? true) {
            self::reconcileColumns(self::pdo(), false);
        }
        self::seedAdmin();
        self::verifySeed();

        if ($needMark) {
            self::markSchemaCurrent();
            self::noteVersionTransition(self::SCHEMA_VERSION_KEY, $recorded, $seedMode, 'schema');
        }
        if ($needSeedMark) {
            self::markSeedCurrent();
            self::noteVersionTransition(self::SEED_VERSION_KEY, $recorded, $seedMode, 'seed');
        }
        self::assertDataEncKeyPresent(self::pdo());
        self::warnDeprecatedEnvKeys();
        self::$bootstrapped = true;
    }

    /**
     * 敏感字段加密的启动守卫（fail-closed）：
     * 库里已存在密文（enc:v1:）但 DATA_ENC_KEY 未配置时直接抛异常拒绝服务——
     * 有密文无 key 意味着读不出任何平台凭据，与其「服务起来了、业务却坏」不如明确报错。
     */
    private static function assertDataEncKeyPresent(\PDO $pdo): void
    {
        if (\App\Support\EnvFileLoader::envVal(\App\Service\Encryptor::ENV_KEY, '') !== '') {
            return;
        }
        $checks = [
            \App\Config\AppConfig::TABLE_PLATFORM_CONNECTIONS => ['token', 'username', 'password'],
            \App\Config\AppConfig::TABLE_OAUTH_CLIENTS         => ['secret'],
        ];
        foreach ($checks as $table => $cols) {
            $like = implode(' OR ', array_map(static fn($c) => "{$c} LIKE 'enc:v1:%'", $cols));
            try {
                $count = $pdo->query("SELECT COUNT(*) FROM {$table} WHERE {$like}")->fetchColumn();
            } catch (\Throwable $e) {
                continue;
            }
            if ((int) $count > 0) {
                throw new \RuntimeException(
                    '检测到已加密字段但未配置 DATA_ENC_KEY，无法解密平台凭据（fail-closed）。'
                    . '请在 config/app.env（或根 .env / k8s Secret）配置 32 字节 base64 密钥后重启'
                    . '（生成：php -r "echo base64_encode(random_bytes(32));"）'
                );
            }
        }
    }

    /**
     * 连接与 OIDC 配置已迁入 DB（v2.10.0 起），下列 env 键应用不再读取。
     * 若仍有非空残留值，说明旧配置尚未迁移（或迁移后未清理），打 warning 提示运维，
     * 避免「升级后连接/OIDC 静默失效、查无凭据」。
     */
    private static function warnDeprecatedEnvKeys(): void
    {
        $deprecated = [

            'GITLAB_BASE_URL', 'GIT_BASE_URL', 'GITLAB_TOKEN', 'GIT_TOKEN',
            'GITHUB_BASE_URL', 'GITHUB_TOKEN',
            'GITEE_BASE_URL', 'GITEE_TOKEN',
            'GITEA_BASE_URL', 'GITEA_TOKEN',
            'HARBOR_BASE_URL', 'HARBOR_USER', 'HARBOR_PASSWORD',
            'JENKINS_BASE_URL', 'JENKINS_USER', 'JENKINS_TOKEN',

            'OIDC_ISSUER', 'OIDC_ID_TOKEN_TTL',
            'JENKINS_OIDC_SECRET', 'JENKINS_OIDC_REDIRECT_URI',
            'CD_OIDC_SECRET', 'CD_OIDC_REDIRECT_URI', 'CD_OIDC_SYSTEM',
        ];
        $leftover = [];
        foreach ($deprecated as $key) {
            if (trim(\App\Support\EnvFileLoader::envVal($key, '')) !== '') {
                $leftover[] = $key;
            }
        }
        if ($leftover !== []) {
            \App\Helper\Log::warning(
                '检测到已废弃的连接/OIDC env 键（v2.10.0 起应用不再读取）：' . implode(', ', $leftover)
                . '。若从旧版升级，请运行 cli/migrate-platform-connections.php 与 cli/migrate-oidc.php 迁移；'
                . '迁移确认无误后可删除这些键。',
                ['deprecated_env_keys' => $leftover]
            );
        }
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
            return null;
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
                return;
            }

            (new OperationLogRepository($pdo))->record('system', $action, $target, $detail, '', $result, 'system');
        } catch (\Throwable $e) {
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
            return;
        }
        \App\Helper\Log::warning(
            '检测到数据库版本高于当前代码版本（疑似代码降级回滚）：本次显式迁移将把「'
            . implode('、', array_keys($ahead)) . '」标记改写为当前代码版本，'
            . '并按当前（旧）代码定义收敛系统角色权限（prune）。',
            [
                'previous' => $ahead,
                'current'  => \App\Config\AppConfig::APP_VERSION,
                'channel'  => 'explicit_migrate',
            ]
        );

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

            \App\Config\AppConfig::TABLE_API_ACCESS_LOGS,

            \App\Config\AppConfig::TABLE_INTEGRATED_SERVICES,

            \App\Config\AppConfig::TABLE_OAUTH_CLIENTS,

            \App\Config\AppConfig::TABLE_PLATFORM_CONNECTIONS,
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

        $owned = [];
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

            'auto_migrate'   => self::isAutoMigrateEnabled(),
            'tables'         => $status,

            'schema_list_drift' => self::schemaListDrift(),

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
                continue;
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
        $VARCHAR = $isMySQL ? 'VARCHAR(255)' : 'TEXT';
        $TS_TYPE = $isMySQL ? 'DATETIME' : 'TEXT';

        return [
            \App\Config\AppConfig::TABLE_JOB_GIT_MAP => [
                'status' => "{$VARCHAR} DEFAULT '" . \App\Config\AppConfig::STATUS_ACTIVE . "'",
                'registry_repository' => 'TEXT',
            ],
            \App\Config\AppConfig::TABLE_SECURITY_CHECKS => [
                'tag'               => "{$VARCHAR} DEFAULT ''",
                'writeback_status'  => "{$VARCHAR} DEFAULT ''",
                'writeback_message' => 'TEXT',
            ],
            \App\Config\AppConfig::TABLE_BUILD_TAG_CACHE => [
                'provider'   => "{$VARCHAR} DEFAULT ''",
                'project_id' => "{$VARCHAR} DEFAULT ''",
                'repository' => "{$VARCHAR} DEFAULT ''",
            ],
            \App\Config\AppConfig::TABLE_PERMISSIONS => [
                'parent_key' => $isMySQL ? 'VARCHAR(128)' : 'TEXT',
                'created_at' => "{$TS_TYPE} DEFAULT NULL",
            ],
            \App\Config\AppConfig::TABLE_OPERATION_LOGS => [
                'operator_type' => "{$VARCHAR} DEFAULT 'admin'",
            ],
            \App\Config\AppConfig::TABLE_ADMIN_USERS => [
                'email'      => "{$VARCHAR} NOT NULL DEFAULT ''",
                'avatar_url' => 'TEXT',
                'status'     => ($isMySQL ? 'TINYINT' : 'INTEGER') . " NOT NULL DEFAULT 1",
                'created_at' => "{$TS_TYPE} NULL DEFAULT NULL",
                'must_change_password' => ($isMySQL ? 'TINYINT' : 'INTEGER') . ' NOT NULL DEFAULT 0',

            ],

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
                continue;
            }
            $stmt = $pdo->prepare(
                'SELECT COLUMN_TYPE FROM information_schema.COLUMNS '
                . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
            );
            foreach ($columns as $column => $definition) {
                $stmt->execute([$table, $column]);
                $actual = $stmt->fetchColumn();
                if ($actual === false) {
                    continue;
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
        self::connect();

        $prevSchema = self::recordedVersion(self::SCHEMA_VERSION_KEY);
        $prevSeed   = self::recordedVersion(self::SEED_VERSION_KEY);
        self::ensureTables();
        self::healCdServiceTokenScopes(self::pdo());
        self::migrateScopeHarborReadToRegistryRead(self::pdo());
        self::seedAdmin();
        self::verifySeed();
        self::markSchemaCurrent();
        self::markSeedCurrent();
        self::noteExplicitDowngrade([self::SCHEMA_VERSION_KEY => $prevSchema, self::SEED_VERSION_KEY => $prevSeed]);
        $status = self::schemaStatus();

        $status['table_build_log'] = self::lastTableBuildLog();
        return $status;
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

        if (in_array($driverCode, [1044, 1142, 1227], true)) {
            return 'ddl_denied';
        }

        if (in_array($driverCode, [1045, 1049, 2002, 2003], true)) {
            return 'unreachable';
        }
        $lower = strtolower($message);

        if (str_contains($lower, 'readonly') || str_contains($lower, 'attempt to write')) {
            return 'ddl_denied';
        }
        if (
            str_contains($lower, 'unable to open database file')
            || str_contains($lower, 'open_basedir')
            || str_contains($lower, 'connection refused')
            || str_contains($lower, 'unknown database')
            || str_contains($lower, 'no such file')
            || str_contains($lower, 'getaddrinfo')
        ) {
            return 'unreachable';
        }

        if (str_starts_with($sqlState, '42000')) {
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
                self::$pdo = null;
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
        self::$tableBuildLog = [];
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
            $critical();
            return;
        }
        $timeout  = self::$migrationWaitOverride ?? self::MIGRATION_WAIT_TIMEOUT_SECONDS;
        $interval = self::$migrationPollOverride ?? self::MIGRATION_POLL_INTERVAL_SECONDS;
        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            usleep((int) round($interval * 1000000));
            if (self::isVersionCurrent(self::recordedVersion($waitKey))) {
                return;
            }
            $token = self::tryAcquireLock(self::MIGRATION_LOCK_KEY, self::MIGRATION_LOCK_TTL);
            if ($token !== null) {
                try {
                    $critical();
                } finally {
                    self::releaseLock(self::MIGRATION_LOCK_KEY, $token);
                }
                return;
            }
        }
        $critical();
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

    /**
     * 执行单张表的建表 DDL 并记录结果到 tableBuildLog（供迁移过程逐表展示）。
     *
     * 失败时先记录「该表失败」再原样抛出，保持 ensureTables 的 fail-fast 语义；
     * 调用方（migrateNow / CLI / 后台）经 lastTableBuildLog() 拿到完整过程列表，
     * 据此渲染「表1 成功 / 表2 失败」而不是一句笼统的 500。
     */
    private static function buildTable(\PDO $pdo, string $table, string $ddl): void
    {
        try {
            $pdo->exec($ddl);
            self::$tableBuildLog[] = ['table' => $table, 'ok' => true, 'error' => null];
        } catch (\Throwable $e) {
            self::$tableBuildLog[] = ['table' => $table, 'ok' => false, 'error' => $e->getMessage()];
            throw $e;
        }
    }

    /**
     * 最近一次 ensureTables() 的逐表建表结果（成功/失败 + 错误信息）。
     *
     * @return list<array{table:string, ok:bool, error:string|null}>
     */
    public static function lastTableBuildLog(): array
    {
        return self::$tableBuildLog;
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

    /** 表是否已存在（MySQL / SQLite）。供仓储在自动迁移关闭、表尚未创建时安全降级。 */
    public static function tableExists(\PDO $pdo, string $table): bool
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
        self::$tableBuildLog = [];

        self::renameLegacyTables($pdo);

        $PK       = $isMySQL ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $TEXT_PK  = $isMySQL ? 'VARCHAR(255) PRIMARY KEY'      : 'TEXT PRIMARY KEY';
        $VARCHAR  = $isMySQL ? 'VARCHAR(255)'                   : 'TEXT';
        $VCHAR255 = $isMySQL ? 'VARCHAR(255) NOT NULL'          : 'TEXT NOT NULL';
        $NOW      = self::sqlNow();
        $ENGINE   = $isMySQL ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';

        $TS_TYPE  = $isMySQL ? 'DATETIME' : 'TEXT';

        self::buildTable($pdo, \App\Config\AppConfig::TABLE_JOB_GIT_MAP, "CREATE TABLE IF NOT EXISTS " . \App\Config\AppConfig::TABLE_JOB_GIT_MAP . " (
            job_name {$TEXT_PK},
            git_platform TEXT,
            build_provider {$VARCHAR},
            git_remote TEXT,
            project_id INTEGER,
            web_url TEXT,
            current_path TEXT,
            registry_repository TEXT,
            api_version TEXT,
            status {$VARCHAR} DEFAULT '" . \App\Config\AppConfig::STATUS_ACTIVE . "'
        ){$ENGINE}");

        self::buildTable($pdo, \App\Config\AppConfig::TABLE_PLATFORM_VERSIONS, "CREATE TABLE IF NOT EXISTS " . \App\Config\AppConfig::TABLE_PLATFORM_VERSIONS . " (
            platform {$TEXT_PK},
            version TEXT NOT NULL
        ){$ENGINE}");

        self::buildTable($pdo, \App\Config\AppConfig::TABLE_PIPELINE_ARTIFACTS, "CREATE TABLE IF NOT EXISTS " . \App\Config\AppConfig::TABLE_PIPELINE_ARTIFACTS . " (
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

        self::buildTable($pdo, \App\Config\AppConfig::TABLE_CUSTOM_BUILDS, "CREATE TABLE IF NOT EXISTS " . \App\Config\AppConfig::TABLE_CUSTOM_BUILDS . " (
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

        if ($isMySQL) {
            self::buildTable($pdo, \App\Config\AppConfig::TABLE_CACHE, "CREATE TABLE IF NOT EXISTS " . \App\Config\AppConfig::TABLE_CACHE . " (
                cache_key VARCHAR(255) PRIMARY KEY,
                `value` MEDIUMTEXT NOT NULL,
                expires_at INTEGER
            ){$ENGINE}");
        } else {
            self::buildTable($pdo, \App\Config\AppConfig::TABLE_CACHE, "CREATE TABLE IF NOT EXISTS " . \App\Config\AppConfig::TABLE_CACHE . " (
                cache_key TEXT PRIMARY KEY,
                value TEXT NOT NULL,
                expires_at INTEGER
            )");
        }

        self::buildTable($pdo, \App\Config\AppConfig::TABLE_ADMIN_USERS, "CREATE TABLE IF NOT EXISTS " . \App\Config\AppConfig::TABLE_ADMIN_USERS . " (
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

        if ($isMySQL) {
            self::buildTable($pdo, \App\Config\AppConfig::TABLE_APP_SETTINGS, "CREATE TABLE IF NOT EXISTS " . \App\Config\AppConfig::TABLE_APP_SETTINGS . " (
                setting_key VARCHAR(255) PRIMARY KEY,
                `value` MEDIUMTEXT NOT NULL,
                updated_at {$TS_TYPE} DEFAULT ({$NOW})
            ){$ENGINE}");
        } else {
            self::buildTable($pdo, \App\Config\AppConfig::TABLE_APP_SETTINGS, "CREATE TABLE IF NOT EXISTS " . \App\Config\AppConfig::TABLE_APP_SETTINGS . " (
                setting_key TEXT PRIMARY KEY,
                value TEXT NOT NULL,
                updated_at {$TS_TYPE} DEFAULT ({$NOW})
            )");
        }

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

        self::buildTable($pdo, \App\Config\AppConfig::TABLE_BUILD_TAG_CACHE, "CREATE TABLE IF NOT EXISTS " . \App\Config\AppConfig::TABLE_BUILD_TAG_CACHE . " (
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

        self::buildTable($pdo, \App\Config\AppConfig::TABLE_SECURITY_CHECKS, "CREATE TABLE IF NOT EXISTS " . \App\Config\AppConfig::TABLE_SECURITY_CHECKS . " (
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

        self::buildTable($pdo, \App\Config\AppConfig::TABLE_OPERATION_LOGS, "CREATE TABLE IF NOT EXISTS " . \App\Config\AppConfig::TABLE_OPERATION_LOGS . " (
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

        $S  = $isMySQL ? 'VARCHAR(255)' : 'TEXT';
        $L  = $isMySQL ? 'VARCHAR(500)' : 'TEXT';
        $M  = $isMySQL ? 'VARCHAR(10)'  : 'TEXT';
        $R  = $isMySQL ? 'VARCHAR(20)'  : 'TEXT';
        $IP = $isMySQL ? 'VARCHAR(45)'  : 'TEXT';
        self::buildTable($pdo, \App\Config\AppConfig::TABLE_API_ACCESS_LOGS, "CREATE TABLE IF NOT EXISTS " . \App\Config\AppConfig::TABLE_API_ACCESS_LOGS . " (
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


        self::buildTable($pdo, \App\Config\AppConfig::TABLE_ROLES, "CREATE TABLE IF NOT EXISTS " . \App\Config\AppConfig::TABLE_ROLES . " (
            id {$PK},
            name {$VARCHAR} NOT NULL UNIQUE,
            description TEXT,
            is_system TINYINT NOT NULL DEFAULT 0,
            created_at {$TS_TYPE} DEFAULT ({$NOW})
        ){$ENGINE}");

        if ($isMySQL) {
            self::buildTable($pdo, \App\Config\AppConfig::TABLE_PERMISSIONS, "CREATE TABLE IF NOT EXISTS " . \App\Config\AppConfig::TABLE_PERMISSIONS . " (
                perm_key VARCHAR(128) PRIMARY KEY,
                description TEXT,
                parent_key VARCHAR(128),
                created_at {$TS_TYPE} DEFAULT NULL
            ){$ENGINE}");
        } else {
            self::buildTable($pdo, \App\Config\AppConfig::TABLE_PERMISSIONS, "CREATE TABLE IF NOT EXISTS " . \App\Config\AppConfig::TABLE_PERMISSIONS . " (
                perm_key TEXT PRIMARY KEY,
                description TEXT,
                parent_key TEXT,
                created_at {$TS_TYPE} DEFAULT NULL
            )");
        }

        if ($isMySQL) {
            self::buildTable($pdo, \App\Config\AppConfig::TABLE_ROLE_PERMISSIONS, "CREATE TABLE IF NOT EXISTS " . \App\Config\AppConfig::TABLE_ROLE_PERMISSIONS . " (
                role_id INTEGER NOT NULL,
                perm_key VARCHAR(128) NOT NULL,
                PRIMARY KEY (role_id, perm_key)
            ){$ENGINE}");
        } else {
            self::buildTable($pdo, \App\Config\AppConfig::TABLE_ROLE_PERMISSIONS, "CREATE TABLE IF NOT EXISTS " . \App\Config\AppConfig::TABLE_ROLE_PERMISSIONS . " (
                role_id INTEGER NOT NULL,
                perm_key TEXT NOT NULL,
                PRIMARY KEY (role_id, perm_key)
            )");
        }

        if ($isMySQL) {
            self::buildTable($pdo, \App\Config\AppConfig::TABLE_IMPLIED_RULES, "CREATE TABLE IF NOT EXISTS " . \App\Config\AppConfig::TABLE_IMPLIED_RULES . " (
                source_key VARCHAR(128) NOT NULL,
                target_key VARCHAR(128) NOT NULL,
                PRIMARY KEY (source_key, target_key)
            ){$ENGINE}");
        } else {
            self::buildTable($pdo, \App\Config\AppConfig::TABLE_IMPLIED_RULES, "CREATE TABLE IF NOT EXISTS " . \App\Config\AppConfig::TABLE_IMPLIED_RULES . " (
                source_key TEXT NOT NULL,
                target_key TEXT NOT NULL,
                PRIMARY KEY (source_key, target_key)
            )");
        }

        if ($isMySQL) {
            self::buildTable($pdo, \App\Config\AppConfig::TABLE_USER_IDENTITIES, "CREATE TABLE IF NOT EXISTS " . \App\Config\AppConfig::TABLE_USER_IDENTITIES . " (
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
            self::buildTable($pdo, \App\Config\AppConfig::TABLE_USER_IDENTITIES, "CREATE TABLE IF NOT EXISTS " . \App\Config\AppConfig::TABLE_USER_IDENTITIES . " (
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

        self::buildTable($pdo, \App\Config\AppConfig::TABLE_API_TOKENS, "CREATE TABLE IF NOT EXISTS " . \App\Config\AppConfig::TABLE_API_TOKENS . " (
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

        $isvcTable = \App\Config\AppConfig::TABLE_INTEGRATED_SERVICES;
        if ($isMySQL) {
            self::buildTable($pdo, $isvcTable, "CREATE TABLE IF NOT EXISTS {$isvcTable} (
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
            self::buildTable($pdo, $isvcTable, "CREATE TABLE IF NOT EXISTS {$isvcTable} (
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

        $oauthClientsTable = \App\Config\AppConfig::TABLE_OAUTH_CLIENTS;
        if ($isMySQL) {
            self::buildTable($pdo, $oauthClientsTable, "CREATE TABLE IF NOT EXISTS {$oauthClientsTable} (
                client_id VARCHAR(64) PRIMARY KEY,
                secret TEXT,
                redirect_uri TEXT,
                `system` VARCHAR(16) NOT NULL DEFAULT '" . \App\Config\AppConfig::SYSTEM_CI . "',
                enabled TINYINT NOT NULL DEFAULT 1,
                description VARCHAR(255) DEFAULT '',
                created_at {$TS_TYPE} DEFAULT ({$NOW}),
                updated_at {$TS_TYPE} DEFAULT ({$NOW})
            ){$ENGINE}");
        } else {
            self::buildTable($pdo, $oauthClientsTable, "CREATE TABLE IF NOT EXISTS {$oauthClientsTable} (
                client_id TEXT PRIMARY KEY,
                secret TEXT,
                redirect_uri TEXT,
                system TEXT NOT NULL DEFAULT '" . \App\Config\AppConfig::SYSTEM_CI . "',
                enabled INTEGER NOT NULL DEFAULT 1,
                description TEXT DEFAULT '',
                created_at {$TS_TYPE} DEFAULT ({$NOW}),
                updated_at {$TS_TYPE} DEFAULT ({$NOW})
            )");
        }

        $platformConnectionsTable = \App\Config\AppConfig::TABLE_PLATFORM_CONNECTIONS;
        if ($isMySQL) {
            self::buildTable($pdo, $platformConnectionsTable, "CREATE TABLE IF NOT EXISTS {$platformConnectionsTable} (
                platform VARCHAR(32) PRIMARY KEY,
                url TEXT,
                token TEXT,
                username TEXT,
                password TEXT,
                description VARCHAR(255) DEFAULT '',
                created_at {$TS_TYPE} DEFAULT ({$NOW}),
                updated_at {$TS_TYPE} DEFAULT ({$NOW})
            ){$ENGINE}");
        } else {
            self::buildTable($pdo, $platformConnectionsTable, "CREATE TABLE IF NOT EXISTS {$platformConnectionsTable} (
                platform TEXT PRIMARY KEY,
                url TEXT,
                token TEXT,
                username TEXT,
                password TEXT,
                description TEXT DEFAULT '',
                created_at {$TS_TYPE} DEFAULT ({$NOW}),
                updated_at {$TS_TYPE} DEFAULT ({$NOW})
            )");
        }



        foreach (self::columnMigrations() as $table => $columns) {
            foreach ($columns as $column => $definition) {
                if (!self::columnExists($pdo, $table, $column)) {
                    $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
                }
            }
        }

        if (
            self::columnExists($pdo, \App\Config\AppConfig::TABLE_JOB_GIT_MAP, 'harbor_repository')
            && self::columnExists($pdo, \App\Config\AppConfig::TABLE_JOB_GIT_MAP, 'registry_repository')
        ) {
            $pdo->exec(
                'UPDATE ' . \App\Config\AppConfig::TABLE_JOB_GIT_MAP
                . " SET registry_repository = harbor_repository"
                . " WHERE (registry_repository IS NULL OR registry_repository = '')"
                . " AND harbor_repository IS NOT NULL AND harbor_repository != ''"
            );
        }

        if (self::columnExists($pdo, \App\Config\AppConfig::TABLE_JOB_GIT_MAP, 'harbor_repository')) {
            try {
                $pdo->exec('ALTER TABLE ' . \App\Config\AppConfig::TABLE_JOB_GIT_MAP . ' DROP COLUMN harbor_repository');
            } catch (\Throwable $e) {
                \App\Helper\Log::warning('旧列 harbor_repository 删除失败，已保留（不影响功能，可忽略）', [
                    'table'      => \App\Config\AppConfig::TABLE_JOB_GIT_MAP,
                    'sqlite_ver' => $pdo->getAttribute(\PDO::ATTR_SERVER_VERSION),
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        self::reconcileColumns($pdo, true);

        self::seedRbac($seedMode);


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

        if (!$isMySQL) {
            self::createIndex('idx_user_identities_username', \App\Config\AppConfig::TABLE_USER_IDENTITIES, 'username');
        }

        if (!$isMySQL) {
            $baseDir = __DIR__ . '/../../config';
            self::migrateJobGitMap("{$baseDir}/job_git_map.json", $pdo);
            self::migratePlatformVersions("{$baseDir}/platform_versions.json", $pdo);
        }

        self::migratePipelineArtifacts($pdo);

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
                continue;
            }
            if (self::tableExists($pdo, $new)) {
                \App\Helper\Log::warning('改名迁移跳过：新旧表并存，以新表为准', ['old' => $old, 'new' => $new]);
                continue;
            }
            $pdo->exec("ALTER TABLE {$old} RENAME TO {$new}");

            \App\Helper\Log::warning('表名收口迁移完成', ['old' => $old, 'new' => $new]);
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
            \App\Helper\Log::error('healCdServiceTokenScopes 失败', ['error' => $e->getMessage()]);
        }
    }

    /**
     * 存量 token 的 harbor.read 重命名为 registry.read。harbor.scan 不变。
     *
     * @param \PDO $pdo
     */
    private static function migrateScopeHarborReadToRegistryRead(\PDO $pdo): void
    {
        try {
            if (!self::tableExists($pdo, \App\Config\AppConfig::TABLE_API_TOKENS)) {
                return;
            }
            $pdo->exec(
                'UPDATE ' . \App\Config\AppConfig::TABLE_API_TOKENS
                . " SET scopes = REPLACE(scopes, 'harbor.read', 'registry.read')"
                . " WHERE scopes IS NOT NULL AND scopes LIKE '%harbor.read%'"
            );
        } catch (\Throwable $e) {
            \App\Helper\Log::error('migrateScopeHarborReadToRegistryRead 失败', ['error' => $e->getMessage()]);
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
                    return;
                }
                self::pdo()->exec("CREATE INDEX {$index} ON {$table} ({$columns})");
            } else {
                self::pdo()->exec("CREATE INDEX IF NOT EXISTS {$index} ON {$table} ({$columns})");
            }
        } catch (\Exception $e) {
            \App\Helper\Log::exception($e);
        }
    }

    // ── RBAC 种子 ──

    private static function seedRbac(string $seedMode = self::SEED_MODE_PRUNE): void
    {
        $pdo = self::pdo();


        $failures = [];

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

        $findRoleStmt   = $pdo->prepare("SELECT id FROM " . \App\Config\AppConfig::TABLE_ROLES . " WHERE name = ?");
        $insertRoleStmt = $pdo->prepare("INSERT INTO " . \App\Config\AppConfig::TABLE_ROLES . " (name, description, is_system) VALUES (?, ?, ?)");
        $updateRoleStmt = $pdo->prepare("UPDATE " . \App\Config\AppConfig::TABLE_ROLES . " SET description = ?, is_system = ? WHERE id = ?");
        foreach (\App\Config\AppConfig::DEFAULT_ROLES as $roleName => $perms) {
            $roleDesc = \App\Config\AppConfig::DEFAULT_ROLE_DESCRIPTIONS[$roleName];
            $isSystem = in_array($roleName, \App\Config\AppConfig::DEFAULT_SYSTEM_ROLES) ? 1 : 0;
            try {
                $findRoleStmt->execute([$roleName]);
                $roleId = $findRoleStmt->fetchColumn();
                if ($roleId === false) {
                    try {
                        $insertRoleStmt->execute([$roleName, $roleDesc, $isSystem]);
                    } catch (\Exception $e) {
                        $findRoleStmt->execute([$roleName]);
                        $roleId = $findRoleStmt->fetchColumn();
                        if ($roleId === false) {
                            throw $e;
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

        $allPermKeys = array_keys(\App\Config\AppConfig::DEFAULT_PERMISSIONS);
        $delRpStmt = ($seedMode === self::SEED_MODE_PRUNE)
            ? $pdo->prepare("DELETE FROM " . \App\Config\AppConfig::TABLE_ROLE_PERMISSIONS . " WHERE role_id = (SELECT id FROM " . \App\Config\AppConfig::TABLE_ROLES . " WHERE name = ?)")
            : null;

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
            ? "INSERT IGNORE INTO {$table} (job_name,git_platform,build_provider,git_remote,project_id,web_url,current_path,registry_repository,api_version) VALUES (?,?,?,?,?,?,?,?,?)"
            : "INSERT OR IGNORE INTO {$table} (job_name,git_platform,build_provider,git_remote,project_id,web_url,current_path,registry_repository,api_version) VALUES (?,?,?,?,?,?,?,?,?)";

        $stmt = $pdo->prepare($sql);
        foreach ($data as $row) {
            if (empty($row['job_name'])) {
                continue;
            }

            $stmt->execute([
                $row['job_name'], $row['git_platform'] ?? null, $row['build_provider'] ?? \App\Config\AppConfig::PROVIDER_JENKINS,
                $row['git_remote'] ?? null, $row['project_id'] ?? null, $row['web_url'] ?? null,
                $row['current_path'] ?? null, $row['registry_repository'] ?? $row['harbor_repository'] ?? null, $row['api_version'] ?? null,
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
        $tagTable      = 'ci_pipeline_tags';
        $mapTable      = \App\Config\AppConfig::TABLE_JOB_GIT_MAP;

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

            throw $e;
        }

        self::dropTableIfExists($pdo, $tagTable);
    }

    private function __construct()
    {
    }
}
