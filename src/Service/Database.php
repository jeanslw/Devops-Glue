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
            'driver'   => $driver,
            'path'     => $_ENV['DB_PATH'] ?? __DIR__ . '/../../config/data/data.db',
            'host'     => $_ENV['DB_HOST'] ?? '127.0.0.1',
            'port'     => $_ENV['DB_PORT'] ?? '3306',
            'database' => $_ENV['DB_NAME'] ?? 'devops_glue',
            'username' => $_ENV['DB_USER'] ?? 'root',
            'password' => $_ENV['DB_PASS'] ?? '',
            'charset'  => 'utf8mb4',
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
            self::ensureTables();
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

        // ci_job_git_map
        $pdo->exec("CREATE TABLE IF NOT EXISTS ci_job_git_map (
            job_name {$TEXT_PK},
            git_platform TEXT,
            build_provider {$VARCHAR} DEFAULT 'jenkins',
            git_remote TEXT,
            project_id INTEGER,
            web_url TEXT,
            current_path TEXT,
            harbor_repository TEXT,
            api_version TEXT,
            status {$VARCHAR} DEFAULT 'active'
        ){$ENGINE}");
        try { $pdo->exec("ALTER TABLE ci_job_git_map ADD COLUMN status {$VARCHAR} DEFAULT 'active'"); } catch (\Exception $e) {}

        // ci_platform_versions
        $pdo->exec("CREATE TABLE IF NOT EXISTS ci_platform_versions (
            platform {$TEXT_PK},
            version TEXT NOT NULL
        ){$ENGINE}");

        // ci_pipeline_tags
        $pdo->exec("CREATE TABLE IF NOT EXISTS ci_pipeline_tags (
            project {$VCHAR255},
            pipeline_iid INTEGER NOT NULL,
            tag {$VARCHAR} NOT NULL,
            harbor_repository TEXT,
            created_at TEXT DEFAULT ({$NOW}),
            PRIMARY KEY (project, pipeline_iid)
        ){$ENGINE}");
        try { $pdo->exec("ALTER TABLE ci_pipeline_tags ADD COLUMN harbor_repository TEXT"); } catch (\Exception $e) {}

        // cache
        if ($isMySQL) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS cache (
                cache_key VARCHAR(255) PRIMARY KEY,
                value MEDIUMTEXT NOT NULL,
                expires_at INTEGER
            ){$ENGINE}");
        } else {
            $pdo->exec("CREATE TABLE IF NOT EXISTS cache (
                cache_key TEXT PRIMARY KEY,
                value TEXT NOT NULL,
                expires_at INTEGER
            )");
        }

        // admin_users
        $pdo->exec("CREATE TABLE IF NOT EXISTS admin_users (
            username {$TEXT_PK},
            password_hash TEXT NOT NULL,
            updated_at TEXT DEFAULT ({$NOW})
        ){$ENGINE}");

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
        $cnt = $pdo->query("SELECT count(*) c FROM admin_users")->fetch()['c'];
        if ($cnt == 0) {
            $user = $_ENV['ADMIN_USER'] ?? 'admin';
            $pass = $_ENV['ADMIN_PASSWORD'] ?? '';
            if (!empty($pass)) {
                $hash = password_hash($pass, PASSWORD_BCRYPT);
                $pdo->prepare("INSERT INTO admin_users (username, password_hash) VALUES (?, ?)")->execute([$user, $hash]);
            }
        }
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
        $sql = $isMySQL
            ? "INSERT IGNORE INTO ci_job_git_map (job_name,git_platform,build_provider,git_remote,project_id,web_url,current_path,harbor_repository,api_version) VALUES (?,?,?,?,?,?,?,?,?)"
            : "INSERT OR IGNORE INTO ci_job_git_map (job_name,git_platform,build_provider,git_remote,project_id,web_url,current_path,harbor_repository,api_version) VALUES (?,?,?,?,?,?,?,?,?)";

        $stmt = $pdo->prepare($sql);
        foreach ($data as $row) {
            if (empty($row['job_name'])) continue;
            $stmt->execute([
                $row['job_name'], $row['git_platform'] ?? null, $row['build_provider'] ?? 'jenkins',
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
        $sql = $isMySQL
            ? "REPLACE INTO ci_platform_versions (platform,version) VALUES (?,?)"
            : "INSERT OR REPLACE INTO ci_platform_versions (platform,version) VALUES (?,?)";

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
        $sql = $isMySQL
            ? "REPLACE INTO ci_pipeline_tags (project,pipeline_iid,tag) VALUES (?,?,?)"
            : "INSERT OR REPLACE INTO ci_pipeline_tags (project,pipeline_iid,tag) VALUES (?,?,?)";

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
