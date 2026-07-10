<?php
namespace App\Service;

class Database
{
    private static ?\PDO $pdo = null;
    private static string $dbPath = '';

    public static function init(string $path = null): void
    {
        self::$dbPath = $path ?? __DIR__ . '/../../config/data/data.db';
    }

    public static function getPdo(): \PDO
    {
        if (self::$pdo === null) {
            if (empty(self::$dbPath)) self::init();
            $dir = dirname(self::$dbPath);
            if (!is_dir($dir)) @mkdir($dir, 0777, true);
            self::$pdo = new \PDO('sqlite:' . self::$dbPath, null, null, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]);
            self::$pdo->exec('PRAGMA journal_mode=WAL');
            self::$pdo->exec('PRAGMA foreign_keys=ON');
            self::ensureTables();
        }
        return self::$pdo;
    }

    public static function reset(): void
    {
        self::$pdo = null;
    }

    private static function ensureTables(): void
    {
        $pdo = self::$pdo;

        $pdo->exec("CREATE TABLE IF NOT EXISTS job_git_map (
            job_name TEXT PRIMARY KEY,
            git_platform TEXT,
            build_provider TEXT DEFAULT 'jenkins',
            git_remote TEXT,
            project_id INTEGER,
            web_url TEXT,
            current_path TEXT,
            harbor_repository TEXT,
            api_version TEXT
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS platform_versions (
            platform TEXT PRIMARY KEY,
            version TEXT NOT NULL
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS pipeline_tags (
            project TEXT NOT NULL,
            pipeline_iid INTEGER NOT NULL,
            tag TEXT NOT NULL,
            created_at TEXT DEFAULT (datetime('now','localtime')),
            PRIMARY KEY (project, pipeline_iid)
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS cache (
            cache_key TEXT PRIMARY KEY,
            value TEXT NOT NULL,
            expires_at INTEGER
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS admin_users (
            username TEXT PRIMARY KEY,
            password_hash TEXT NOT NULL,
            updated_at TEXT DEFAULT (datetime('now','localtime'))
        )");

        // 从 .env 迁移管理员账号到数据库（一次性，仅在表为空时）
        $cnt = $pdo->query("SELECT count(*) c FROM admin_users")->fetch()['c'];
        if ($cnt == 0) {
            $user = $_ENV['ADMIN_USER'] ?? 'admin';
            $pass = $_ENV['ADMIN_PASSWORD'] ?? '';
            if (!empty($pass)) {
                $hash = password_hash($pass, PASSWORD_BCRYPT);
                $pdo->prepare("INSERT INTO admin_users (username, password_hash) VALUES (?, ?)")->execute([$user, $hash]);
            }
        }

        // 一次性 JSON 迁移
        $baseDir = __DIR__ . '/../../config';
        self::migrateJobGitMap("{$baseDir}/job_git_map.json", $pdo);
        self::migratePlatformVersions("{$baseDir}/platform_versions.json", $pdo);
        self::migratePipelineTags("{$baseDir}/pipeline_tags.json", $pdo);
    }

    private static function migrateJobGitMap(string $path, \PDO $pdo): void
    {
        if (!file_exists($path)) return;
        $json = file_get_contents($path);
        if ($json === false) return;
        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data[0])) { @unlink($path); return; }
        $stmt = $pdo->prepare("INSERT OR IGNORE INTO job_git_map (job_name,git_platform,build_provider,git_remote,project_id,web_url,current_path,harbor_repository,api_version) VALUES (?,?,?,?,?,?,?,?,?)");
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
        $stmt = $pdo->prepare("INSERT OR REPLACE INTO platform_versions (platform,version) VALUES (?,?)");
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
        $stmt = $pdo->prepare("INSERT OR REPLACE INTO pipeline_tags (project,pipeline_iid,tag) VALUES (?,?,?)");
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
