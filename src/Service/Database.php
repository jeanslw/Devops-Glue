<?php
namespace App\Service;

class Database
{
    private static ?\PDO $pdo = null;
    private static string $driver = 'sqlite';
    private static array $config = [];

    // ── 初始化 ──

    public static function init(array $config = null): void
    {
        self::$config = $config ?? self::defaultConfig();
        self::$driver = self::$config['driver'] ?? 'sqlite';
    }

    private static function defaultConfig(): array
    {
        $driver = strtolower($_ENV['DB_DRIVER'] ?? '');
        if (!in_array($driver, ['sqlite', 'mysql'])) {
            throw new \RuntimeException('DB_DRIVER 必须设为 sqlite 或 mysql，当前: ' . ($driver ?: '未设置'));
        }
        return [
            'driver'       => $driver,
            'path'         => $_ENV['DB_PATH'] ?? __DIR__ . '/../../config/data/data.db',
            'host'         => $_ENV['DB_HOST'] ?? '127.0.0.1',
            'port'         => $_ENV['DB_PORT'] ?? '3306',
            'database'     => $_ENV['DB_NAME'] ?? 'devops_glue',
            'username'     => $_ENV['DB_USER'] ?? 'root',
            'password'     => $_ENV['DB_PASS'] ?? '',
            'charset'      => 'utf8mb4',
            'auto_migrate' => ($_ENV['DB_AUTO_MIGRATE'] ?? 'true') !== 'false',
        ];
    }

    // ── PDO 连接 ──

    public static function getPdo(): \PDO
    {
        if (self::$pdo === null) {
            if (empty(self::$config)) self::init();

            if (self::$driver === 'mysql') {
                self::$pdo = self::connectMysql();
            } else {
                self::$pdo = self::connectSqlite();
            }

            self::$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            self::$pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
            if (self::$config['auto_migrate'] ?? true) {
                self::ensureTables();
            }
            self::seedAdmin();
        }
        return self::$pdo;
    }

    private static function connectSqlite(): \PDO
    {
        $path = self::$config['path'];
        $dir  = dirname($path);
        if (!is_dir($dir)) @mkdir($dir, 0777, true);

        $pdo = new \PDO('sqlite:' . $path);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA foreign_keys=ON');
        return $pdo;
    }

    private static function connectMysql(): \PDO
    {
        $cfg  = self::$config;
        $dsn  = "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['database']};charset={$cfg['charset']}";
        return new \PDO($dsn, $cfg['username'], $cfg['password'], [
            \PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$cfg['charset']}",
        ]);
    }

    // ── 重置 ──

    public static function reset(): void
    {
        self::$pdo = null;
    }

    public static function driver(): string
    {
        return self::$driver;
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
        try { $pdo->exec("ALTER TABLE " . \App\Config\AppConfig::TABLE_JOB_GIT_MAP . " ADD COLUMN status {$VARCHAR} DEFAULT '" . \App\Config\AppConfig::STATUS_ACTIVE . "'"); } catch (\Exception $e) {}

        // ci_platform_versions
        $pdo->exec("CREATE TABLE IF NOT EXISTS " . \App\Config\AppConfig::TABLE_PLATFORM_VERSIONS . " (
            platform {$TEXT_PK},
            version TEXT NOT NULL
        ){$ENGINE}");

        // ci_pipeline_tags
        $pdo->exec("CREATE TABLE IF NOT EXISTS " . \App\Config\AppConfig::TABLE_PIPELINE_TAGS . " (
            project {$VCHAR255},
            pipeline_iid INTEGER NOT NULL,
            tag {$VARCHAR} NOT NULL,
            harbor_repository TEXT,
            status {$VARCHAR} DEFAULT '',
            created_at {$TS_TYPE} DEFAULT ({$NOW}),
            PRIMARY KEY (project, pipeline_iid)
        ){$ENGINE}");
        try { $pdo->exec("ALTER TABLE " . \App\Config\AppConfig::TABLE_PIPELINE_TAGS . " ADD COLUMN harbor_repository TEXT"); } catch (\Exception $e) {}
        try { $pdo->exec("ALTER TABLE " . \App\Config\AppConfig::TABLE_PIPELINE_TAGS . " ADD COLUMN status {$VARCHAR} DEFAULT ''"); } catch (\Exception $e) {}

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

        // admin_users
        $pdo->exec("CREATE TABLE IF NOT EXISTS " . \App\Config\AppConfig::TABLE_ADMIN_USERS . " (
            username {$TEXT_PK},
            password_hash TEXT NOT NULL,
            role {$VARCHAR} NOT NULL DEFAULT '" . \App\Config\AppConfig::ROLE_ADMIN . "',
            systems {$VARCHAR} NOT NULL DEFAULT 'ci,cd',
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
            if ($old && in_array($old['value'], [\App\Config\AppConfig::BUILD_MODE_JENKINS, \App\Config\AppConfig::BUILD_MODE_GITLAB_CI, \App\Config\AppConfig::BUILD_MODE_BOTH])) {
                $exists = $pdo->query("SELECT 1 FROM " . \App\Config\AppConfig::TABLE_APP_SETTINGS . " WHERE setting_key = 'build_mode'")->fetch();
                if (!$exists) {
                    $sql = self::sqlUpsert(\App\Config\AppConfig::TABLE_APP_SETTINGS, 'setting_key, value, updated_at', '?, ?, ' . self::sqlNow());
                    $pdo->prepare($sql)->execute(['build_mode', $old['value']]);
                }
                $pdo->exec("DELETE FROM " . \App\Config\AppConfig::TABLE_CACHE . " WHERE cache_key = 'build_mode'");
            }
        } catch (\Exception $e) {}

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
            created_at {$TS_TYPE} DEFAULT ({$NOW})
        ){$ENGINE}");
        try { $pdo->exec("ALTER TABLE " . \App\Config\AppConfig::TABLE_SECURITY_CHECKS . " ADD COLUMN tag {$VARCHAR} DEFAULT ''"); } catch (\Exception $e) {}

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
                parent_key VARCHAR(128)
            ){$ENGINE}");
        } else {
            $pdo->exec("CREATE TABLE IF NOT EXISTS " . \App\Config\AppConfig::TABLE_PERMISSIONS . " (
                perm_key TEXT PRIMARY KEY,
                description TEXT,
                parent_key TEXT
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

        // parent_key 列（用于权限层级，eg. cd.deploy.single → parent cd.deploy-manage）
        try { $pdo->exec("ALTER TABLE " . \App\Config\AppConfig::TABLE_PERMISSIONS . " ADD COLUMN parent_key VARCHAR(128)"); } catch (\Exception $e) {}

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

        // 种子数据：权限定义（含 parent_key）
        $permUpsert = self::sqlUpsert(\App\Config\AppConfig::TABLE_PERMISSIONS, 'perm_key, description, parent_key', '?, ?, ?');
        $permStmt = $pdo->prepare($permUpsert);
        foreach (\App\Config\AppConfig::DEFAULT_PERMISSIONS as $key => $def) {
            $desc = is_array($def) ? $def['name'] : $def;
            $parent = is_array($def) ? ($def['parent'] ?? null) : null;
            try { $permStmt->execute([$key, $desc, $parent]); } catch (\Exception $e) {}
        }

        // 种子数据：隐含规则（与 DEFAULT_PERMISSIONS 一样作 bootstrap，运行时可被 API 覆盖/扩展）
        $ruleUpsert = self::sqlUpsert(\App\Config\AppConfig::TABLE_IMPLIED_RULES, 'source_key, target_key', '?, ?');
        $ruleStmt = $pdo->prepare($ruleUpsert);
        foreach (\App\Config\AppConfig::IMPLIED_PERMISSIONS as $src => $targets) {
            foreach ($targets as $tgt) {
                try { $ruleStmt->execute([$src, $tgt]); } catch (\Exception $e) {}
            }
        }

        // 种子数据：系统角色（幂等，is_system 由 DEFAULT_SYSTEM_ROLES 决定）
        $roleUpsert = self::sqlUpsert(\App\Config\AppConfig::TABLE_ROLES, 'name, description, is_system', '?, ?, ?');
        $roleStmt = $pdo->prepare($roleUpsert);
        foreach (\App\Config\AppConfig::DEFAULT_ROLES as $roleName => $perms) {
            $roleDesc = ''; // 不硬编码描述，由前端 i18n（user.role_{name}）渲染
            $isSystem = in_array($roleName, \App\Config\AppConfig::DEFAULT_SYSTEM_ROLES) ? 1 : 0;
            try { $roleStmt->execute([$roleName, $roleDesc, $isSystem]); } catch (\Exception $e) {}
        }

        // 种子数据：角色↔权限（只同步系统角色，不碰自定义角色）
        $allPermKeys = array_keys(\App\Config\AppConfig::DEFAULT_PERMISSIONS);
        $delRpStmt = $pdo->prepare("DELETE FROM " . \App\Config\AppConfig::TABLE_ROLE_PERMISSIONS . " WHERE role_id = (SELECT id FROM " . \App\Config\AppConfig::TABLE_ROLES . " WHERE name = ?)");
        $rpStmt = $pdo->prepare("INSERT INTO " . \App\Config\AppConfig::TABLE_ROLE_PERMISSIONS . " (role_id, perm_key) VALUES ((SELECT id FROM " . \App\Config\AppConfig::TABLE_ROLES . " WHERE name = ?), ?)");
        foreach (\App\Config\AppConfig::DEFAULT_ROLES as $roleName => $perms) {
            try { $delRpStmt->execute([$roleName]); } catch (\Exception $e) {}
            $permKeys = ($perms === '*') ? $allPermKeys : $perms;
            foreach ($permKeys as $permKey) {
                try { $rpStmt->execute([$roleName, $permKey]); } catch (\Exception $e) {}
            }
        }

        // ── 索引 ──
        try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_pipeline_tags_project ON " . \App\Config\AppConfig::TABLE_PIPELINE_TAGS . "(project)"); } catch (\Exception $e) {}
        try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_pipeline_tags_created ON " . \App\Config\AppConfig::TABLE_PIPELINE_TAGS . "(created_at)"); } catch (\Exception $e) {}
        try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_job_git_map_current_path ON " . \App\Config\AppConfig::TABLE_JOB_GIT_MAP . "(current_path)"); } catch (\Exception $e) {}
        try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_security_checks_project ON " . \App\Config\AppConfig::TABLE_SECURITY_CHECKS . "(project, check_type)"); } catch (\Exception $e) {}
        try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_security_checks_sha ON " . \App\Config\AppConfig::TABLE_SECURITY_CHECKS . "(sha)"); } catch (\Exception $e) {}

        // 一次性 JSON 迁移（仅 SQLite）
        if (!$isMySQL) {
            $baseDir = __DIR__ . '/../../config';
            self::migrateJobGitMap("{$baseDir}/job_git_map.json", $pdo);
            self::migratePlatformVersions("{$baseDir}/platform_versions.json", $pdo);
            self::migratePipelineTags("{$baseDir}/pipeline_tags.json", $pdo);
        }
    }

    // ── 管理员种子 ──

    private static function seedAdmin(): void
    {
        $pdo = self::$pdo;
        $cnt = $pdo->query("SELECT count(*) c FROM " . \App\Config\AppConfig::TABLE_ADMIN_USERS)->fetch()['c'];
        if ($cnt == 0) {
            $user = $_ENV['ADMIN_USER'] ?? 'admin';
            $pass = $_ENV['ADMIN_PASSWORD'] ?? '';
            if (!empty($pass)) {
                $hash = password_hash($pass, PASSWORD_BCRYPT);
                $pdo->prepare("INSERT INTO " . \App\Config\AppConfig::TABLE_ADMIN_USERS . " (username, password_hash, role, systems) VALUES (?, ?, ?, 'ci,cd')")
                    ->execute([$user, $hash, \App\Config\AppConfig::ROLE_SUPER_ADMIN]);
            }
        }
        // 迁移：已有根管理员 role 从 'admin' 升级为 'super_admin'
        $rootUser = $_ENV['ADMIN_USER'] ?? 'admin';
        $pdo->prepare("UPDATE " . \App\Config\AppConfig::TABLE_ADMIN_USERS . " SET role = ? WHERE username = ? AND role = ?")
            ->execute([\App\Config\AppConfig::ROLE_SUPER_ADMIN, $rootUser, \App\Config\AppConfig::ROLE_ADMIN]);
    }

    // ── JSON 迁移（仅 SQLite 一次性）──

    private static function migrateJobGitMap(string $path, \PDO $pdo): void
    {
        if (!file_exists($path)) return;
        $json = file_get_contents($path);
        if ($json === false) return;
        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data[0])) { @unlink($path); return; }

        $isMySQL = self::$driver === 'mysql';
        $table = \App\Config\AppConfig::TABLE_JOB_GIT_MAP;
        $sql = $isMySQL
            ? "INSERT IGNORE INTO {$table} (job_name,git_platform,build_provider,git_remote,project_id,web_url,current_path,harbor_repository,api_version) VALUES (?,?,?,?,?,?,?,?,?)"
            : "INSERT OR IGNORE INTO {$table} (job_name,git_platform,build_provider,git_remote,project_id,web_url,current_path,harbor_repository,api_version) VALUES (?,?,?,?,?,?,?,?,?)";

        $stmt = $pdo->prepare($sql);
        foreach ($data as $row) {
            if (empty($row['job_name'])) continue;
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
        if (!file_exists($path)) return;
        $json = file_get_contents($path);
        if ($json === false) return;
        $data = json_decode($json, true);
        if (!is_array($data)) { @unlink($path); return; }

        $isMySQL = self::$driver === 'mysql';
        $table = \App\Config\AppConfig::TABLE_PLATFORM_VERSIONS;
        $sql = $isMySQL
            ? "REPLACE INTO {$table} (platform,version) VALUES (?,?)"
            : "INSERT OR REPLACE INTO {$table} (platform,version) VALUES (?,?)";

        $stmt = $pdo->prepare($sql);
        foreach ($data as $platform => $ver) {
            if (is_string($ver)) $stmt->execute([$platform, $ver]);
        }
        @unlink($path);
    }

    private static function migratePipelineTags(string $path, \PDO $pdo): void
    {
        if (!file_exists($path)) return;
        $json = file_get_contents($path);
        if ($json === false) return;
        $data = json_decode($json, true);
        if (!is_array($data)) { @unlink($path); return; }

        $isMySQL = self::$driver === 'mysql';
        $table = \App\Config\AppConfig::TABLE_PIPELINE_TAGS;
        $sql = $isMySQL
            ? "REPLACE INTO {$table} (project,pipeline_iid,tag) VALUES (?,?,?)"
            : "INSERT OR REPLACE INTO {$table} (project,pipeline_iid,tag) VALUES (?,?,?)";

        $stmt = $pdo->prepare($sql);
        foreach ($data as $project => $tags) {
            if (!is_array($tags)) continue;
            foreach ($tags as $iid => $tag) {
                $stmt->execute([$project, (int) $iid, $tag]);
            }
        }
        @unlink($path);
    }

    private function __construct() {}
}
