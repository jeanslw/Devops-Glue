<?php

namespace App\Service;

class Database
{
    private static ?\PDO $pdo = null;
    private static string $driver = 'sqlite';
    private static array $config = [];
    private static bool $bootstrapped = false;

    /** ci_app_settings 中记录「已应用的 schema/种子版本」的 key */
    private const SCHEMA_VERSION_KEY = 'schema_version';

    // ── 初始化 ──

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
        $needMark = false;
        if (self::$config['auto_migrate'] ?? true) {
            // 仅在 schema/种子版本与当前代码版本不一致时执行建表 + 种子，
            // 避免每个请求都重复跑 ensureTables/seedRbac 的写库操作。
            if (!self::isSchemaCurrent()) {
                self::ensureTables(); // 建表 + RBAC 种子 + 索引 + JSON 迁移
                $needMark = true;
            }
        } else {
            self::seedRbac(); // 手动建库脚本模式：表已存在，仍补种子数据
        }
        self::seedAdmin();
        self::verifySeed();
        // 自检通过后才标记 schema 当前（与 migrateNow() 一致）：若自检抛异常，版本号保持旧值，
        // 下次引导会重新走 ensureTables() 自愈，而非「版本已标记当前但种子其实坏了」的假象。
        if ($needMark) {
            self::markSchemaCurrent();
        }
        self::$bootstrapped = true;
    }

    /** 判断 schema/种子是否已应用到当前代码版本（首次启动或版本升级时返回 false） */
    private static function isSchemaCurrent(): bool
    {
        try {
            $stmt = self::$pdo->query(
                "SELECT value FROM " . \App\Config\AppConfig::TABLE_APP_SETTINGS
                . " WHERE setting_key = '" . self::SCHEMA_VERSION_KEY . "'"
            );
            $row = $stmt->fetch();
            return $row !== false && ($row['value'] ?? '') === \App\Config\AppConfig::APP_VERSION;
        } catch (\Throwable $e) {
            return false; // ci_app_settings 表尚未创建 → 视为未初始化
        }
    }

    /** 记录当前代码版本已应用的 schema/种子版本 */
    private static function markSchemaCurrent(): void
    {
        $sql = self::sqlUpsert(
            \App\Config\AppConfig::TABLE_APP_SETTINGS,
            'setting_key, value, updated_at',
            '?, ?, ' . self::sqlNow()
        );
        self::$pdo->prepare($sql)->execute([self::SCHEMA_VERSION_KEY, \App\Config\AppConfig::APP_VERSION]);
    }

    /** 当前代码版本定义的全部数据表（系统信息面板按此清单逐表探测存在性） */
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

    /** 系统信息：DB 驱动 + schema 版本 + 各核心表存在性（供后台「系统信息」面板只读查询） */
    public static function schemaStatus(): array
    {
        $status = [];
        foreach (self::schemaTables() as $t) {
            $status[$t] = self::tableExists(self::$pdo, $t);
        }
        $recorded = null;
        try {
            $row = self::$pdo->query(
                "SELECT value FROM " . \App\Config\AppConfig::TABLE_APP_SETTINGS
                . " WHERE setting_key = '" . self::SCHEMA_VERSION_KEY . "'"
            )->fetchColumn();
            $recorded = ($row === false) ? null : (string) $row;
        } catch (\Throwable $e) {
            $recorded = null;
        }
        return [
            'driver'         => self::$driver,
            'schema_version' => $recorded,
            'app_version'    => \App\Config\AppConfig::APP_VERSION,
            'is_current'     => ($recorded !== null && $recorded === \App\Config\AppConfig::APP_VERSION),
            'tables'         => $status,
        ];
    }

    /** 手动触发迁移：建缺失表 + RBAC/管理员种子 + 自检 + 标记 schema 当前（后台「迁移数据库」按钮，super_admin 专用）。 */
    public static function migrateNow(): array
    {
        self::ensureTables();      // 建表 + RBAC 种子 + 索引 + JSON 迁移
        self::seedAdmin();          // 补管理员种子（与 bootstrap() 一致，缺管理员时自愈）
        self::verifySeed();         // 自检种子不变量，缺失直接抛异常，不标记当前
        self::markSchemaCurrent();  // 自检通过后才标记当前版本
        return self::schemaStatus();
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
            'auto_migrate' => !in_array(strtolower(trim(self::envValue('DB_AUTO_MIGRATE', 'true'))), ['0', 'false', 'no', 'off', ''], true),
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
        return self::$pdo;
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

    // ── 分布式锁（基于 ci_cache 的租约锁，用于定时任务单实例化）──

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

    /** 判断列是否已存在（MySQL/SQLite 双驱动），用于幂等 ALTER TABLE 迁移 */
    private static function columnExists(string $table, string $column): bool
    {
        $pdo = self::$pdo;
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

    private static function ensureTables(): void
    {
        $pdo = self::$pdo;
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
        // 存量库增量补列的唯一来源：新增列时只需在此追加一项（类型按驱动解析），
        // 配合 columnExists 幂等检查，无需再为每列手写 if/ALTER。
        // 新库由上方 CREATE TABLE 直接建全，此映射保证存量库也能补齐同名列。
        $columnMigrations = [
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
        // 补列循环：遍历有限映射，天然有界、必然终止（无 while/递归，无需显式 break）。
        // 任一列检测/ALTER 失败会抛 PDOException 一路向上（fail-fast，与上方 CREATE TABLE 一致），
        // 应用启动失败；但因 columnExists 幂等 + 尚未 markSchemaCurrent，下次启动会重跑补齐剩余列，不留半成品。
        foreach ($columnMigrations as $table => $columns) {
            foreach ($columns as $column => $definition) {
                if (!self::columnExists($table, $column)) {
                    $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
                }
            }
        }

        // 种子数据：RBAC（权限定义 / 隐含规则 / 系统角色 / 角色↔权限），幂等可重复执行
        self::seedRbac();

        // ── 索引（跨驱动幂等：MySQL 无 IF NOT EXISTS，先查 information_schema）──
        self::createIndex('idx_pipeline_artifacts_project_key', \App\Config\AppConfig::TABLE_PIPELINE_ARTIFACTS, 'project_key');
        self::createIndex('idx_pipeline_artifacts_created', \App\Config\AppConfig::TABLE_PIPELINE_ARTIFACTS, 'created_at');
        self::createIndex('idx_job_git_map_current_path', \App\Config\AppConfig::TABLE_JOB_GIT_MAP, 'current_path');
        self::createIndex('idx_security_checks_project', \App\Config\AppConfig::TABLE_SECURITY_CHECKS, 'project, check_type');
        self::createIndex('idx_security_checks_sha', \App\Config\AppConfig::TABLE_SECURITY_CHECKS, 'sha');
        self::createIndex('idx_operation_logs_created', \App\Config\AppConfig::TABLE_OPERATION_LOGS, 'created_at');
        self::createIndex('idx_operation_logs_user', \App\Config\AppConfig::TABLE_OPERATION_LOGS, 'username');
        self::createIndex('idx_operation_logs_action', \App\Config\AppConfig::TABLE_OPERATION_LOGS, 'action');

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
                $stmt = self::$pdo->prepare(
                    'SELECT COUNT(*) FROM information_schema.statistics'
                    . ' WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?'
                );
                $stmt->execute([$table, $index]);
                if ((int)$stmt->fetchColumn() > 0) {
                    return; // 索引已存在
                }
                self::$pdo->exec("CREATE INDEX {$index} ON {$table} ({$columns})");
            } else {
                self::$pdo->exec("CREATE INDEX IF NOT EXISTS {$index} ON {$table} ({$columns})");
            }
        } catch (\Exception $e) {
            // 索引非关键路径，失败不阻断启动，但记录日志以便排查
            \App\Helper\Log::exception($e);
        }
    }

    // ── RBAC 种子 ──

    private static function seedRbac(): void
    {
        $pdo = self::$pdo;

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
            }
        }

        // 种子数据：角色↔权限（只同步系统角色，不碰自定义角色）
        $allPermKeys = array_keys(\App\Config\AppConfig::DEFAULT_PERMISSIONS);
        $delRpStmt = $pdo->prepare("DELETE FROM " . \App\Config\AppConfig::TABLE_ROLE_PERMISSIONS . " WHERE role_id = (SELECT id FROM " . \App\Config\AppConfig::TABLE_ROLES . " WHERE name = ?)");
        // INSERT 用 IGNORE 而非裸 INSERT：分布式部署下 web / worker 容器启动时并发跑 seedRbac，
        // 两个进程都先 DELETE 再 INSERT 同一批 (role_id, perm_key)，裸 INSERT 会撞 role_permissions
        // 联合主键报 1062 Duplicate entry。IGNORE 让后到者静默跳过，各进程结果收敛一致。
        $rpStmt = $pdo->prepare(self::sqlInsertIgnore(
            \App\Config\AppConfig::TABLE_ROLE_PERMISSIONS,
            'role_id, perm_key',
            '(SELECT id FROM ' . \App\Config\AppConfig::TABLE_ROLES . ' WHERE name = ?), ?'
        ));
        foreach (\App\Config\AppConfig::DEFAULT_ROLES as $roleName => $perms) {
            try {
                $delRpStmt->execute([$roleName]);
            } catch (\Exception $e) {
                \App\Helper\Log::error('seedRbac 角色权限清理失败', ['role' => $roleName, 'error' => $e->getMessage()]);
            }
            $permKeys = ($perms === '*') ? $allPermKeys : $perms;
            foreach ($permKeys as $permKey) {
                try {
                    $rpStmt->execute([$roleName, $permKey]);
                } catch (\Exception $e) {
                    \App\Helper\Log::error('seedRbac 角色权限写入失败', ['role' => $roleName, 'perm_key' => $permKey, 'error' => $e->getMessage()]);
                }
            }
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
        AdminUserRepository::seedAdminFromEnv(self::$pdo);
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
        $pdo = self::$pdo;
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
