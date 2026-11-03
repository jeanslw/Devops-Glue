<?php

declare(strict_types=1);

namespace App\Service;

/**
 * 数据库访问与迁移门面（静态）。
 *
 * 为降低单文件复杂度，schema 迁移 / RBAC 播种 / 历史数据修复 / 分布式锁 / 启动编排等职责拆分到
 * SchemaMigrator、RbacSeeder、DataRepairer、DistributedLock、BootstrapOrchestrator。
 * 本类持有连接态与组件实例，所有 Database:: 调用点行为保持不变。
 */
class Database
{
    public const SCHEMA_VERSION_KEY = 'schema_version';
    public const BOOT_WARN_KEY_PREFIX = 'boot_warn_';
    public const OIDC_ENV_SEED_KEY = 'oidc_env_seed_done';
    public const SEED_VERSION_KEY = 'seed_version';
    public const SEED_MODE_PRUNE = 'prune';
    public const SEED_MODE_MERGE = 'merge';
    public const ACTION_SCHEMA_INIT = 'schema_init';
    public const ACTION_SCHEMA_UPGRADE = 'schema_upgrade';
    public const ACTION_SCHEMA_DOWNGRADE = 'schema_downgrade';
    public const ACTION_RBAC_SEED_FAILED = 'rbac_seed_failed';
    private const SYSTEM_AUDIT_DEDUPE_WINDOW = 600;
    public const MIGRATION_LOCK_KEY = 'schema_migration';
    public const MIGRATION_LOCK_TTL = 300;
    public const MIGRATION_POLL_INTERVAL_SECONDS = 0.5;
    public const MIGRATION_WAIT_TIMEOUT_SECONDS = 30;

    private static ?\PDO $pdo = null;
    private static string $driver = 'sqlite';
    /** @var array<string,mixed> */
    private static array $config = [];
    private static bool $bootstrapped = false;
    private static ?SchemaMigrator $migrator = null;
    private static ?RbacSeeder $seeder = null;
    private static ?DistributedLock $lock = null;
    private static ?BootstrapOrchestrator $orchestrator = null;
    public static ?int $migrationWaitOverride = null;
    public static ?float $migrationPollOverride = null;
    /** @var array<string,string> */
    public static array $warnStateSeen = [];

    private static function migrator(): SchemaMigrator
    {
        if (self::$migrator === null) {
            self::buildComponents();
        }
        if (self::$migrator === null) {
            throw new \RuntimeException('Database not bootstrapped');
        }
        return self::$migrator;
    }
    private static function seeder(): RbacSeeder
    {
        if (self::$seeder === null) {
            self::buildComponents();
        }
        if (self::$seeder === null) {
            throw new \RuntimeException('Database not bootstrapped');
        }
        return self::$seeder;
    }
    private static function lock(): DistributedLock
    {
        if (self::$lock === null) {
            self::buildComponents();
        }
        if (self::$lock === null) {
            throw new \RuntimeException('Database not bootstrapped');
        }
        return self::$lock;
    }
    private static function buildComponents(): void
    {
        if (self::$pdo === null) {
            throw new \RuntimeException('Database not bootstrapped');
        }
        $seeder = new RbacSeeder(self::$pdo);
        $repairer = new DataRepairer(self::$pdo, self::$driver);
        $migrator = new SchemaMigrator(self::$pdo, self::$driver, $seeder, $repairer);
        $lock = new DistributedLock(self::$pdo, self::$driver);
        self::$orchestrator = new BootstrapOrchestrator(self::$pdo, $migrator, $seeder, $repairer, $lock);
        self::$seeder = $seeder;
        self::$migrator = $migrator;
        self::$lock = $lock;
    }

    private static function pdo(): \PDO
    {
        if (self::$pdo === null) {
            throw new \RuntimeException('Database not bootstrapped: call Database::getPdo() or bootstrap() first');
        }
        return self::$pdo;
    }

    /**
     * @param array<string,mixed>|null $config
     */
    public static function init(array $config = null): void
    {
        self::$config = $config ?? self::defaultConfig();
        self::$driver = self::$config['driver'] ?? 'sqlite';
    }

    public static function warnOnceOnStateChange(
        string $name,
        string $fingerprint,
        callable $emit,
        ?\PDO $pdo = null
    ): void {
        if ((self::$warnStateSeen[$name] ?? null) === $fingerprint) {
            return;
        }
        self::$warnStateSeen[$name] = $fingerprint;

        try {
            $pdo = $pdo ?? self::pdo();
            $key = self::BOOT_WARN_KEY_PREFIX . $name;
            if (self::readAppSetting($pdo, $key) === $fingerprint) {
                return;
            }
            self::writeAppSetting($pdo, $key, $fingerprint);
        } catch (\Throwable $e) {
        }

        $emit();
    }

    public static function readAppSetting(\PDO $pdo, string $key): ?string
    {
        $stmt = $pdo->prepare(
            'SELECT value FROM ' . \App\Config\AppConfig::TABLE_APP_SETTINGS . ' WHERE setting_key = ?'
        );
        $stmt->execute([$key]);
        $row = $stmt->fetchColumn();
        return $row === false ? null : (string) $row;
    }

    public static function recordedVersion(\PDO $pdo, string $key): ?string
    {
        return self::readAppSetting($pdo, $key);
    }

    public static function isVersionCurrent(?string $recorded): bool
    {
        return $recorded === \App\Config\AppConfig::APP_VERSION;
    }

    public static function buildTable(\PDO $pdo, string $table, string $ddl): void
    {
        $pdo->exec($ddl);
    }

    public static function writeAppSetting(\PDO $pdo, string $key, string $value): void
    {
        $sql = self::sqlUpsert(
            \App\Config\AppConfig::TABLE_APP_SETTINGS,
            'setting_key, value, updated_at',
            '?, ?, ' . self::sqlNow()
        );
        $pdo->prepare($sql)->execute([$key, $value]);
    }

    /**
     * @param array<string,mixed> $detail
     */
    public static function recordSystemAudit(string $action, string $target, array $detail, string $result, ?string $channel = null): void
    {
        try {
            $pdo   = self::pdo();
            $since = gmdate('Y-m-d H:i:s', time() - self::SYSTEM_AUDIT_DEDUPE_WINDOW);

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

    /** @return list<string> */
    public static function schemaTables(): array
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
     * @param list<string>|null $probeList
     * @return array{ok: bool, tables: list<string>, missing: list<string>, probe_unknown: list<string>}
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

    /** @return array<string,mixed> */
    public static function columnSpecs(): array
    {
        return [
            \App\Config\AppConfig::TABLE_ADMIN_USERS => [
                'role'    => "VARCHAR(255) NOT NULL DEFAULT '" . \App\Config\AppConfig::ROLE_ADMIN . "'",
                'systems' => "VARCHAR(255) NOT NULL DEFAULT 'ci,cd'",
            ],
        ];
    }

    public static function firstTypeToken(string $definition): string
    {
        $parts = explode(' ', trim($definition), 2);
        return $parts[0];
    }

    public static function normalizeColumnType(string $type): string
    {
        return strtolower(preg_replace('/\s+/', '', $type) ?? $type);
    }

    public static function isWidenVarchar(string $actual, string $expected): bool
    {
        if (
            !preg_match('/^varchar\((\d+)\)$/', $actual, $a)
            || !preg_match('/^varchar\((\d+)\)$/', $expected, $b)
        ) {
            return false;
        }
        return (int) $b[1] >= (int) $a[1];
    }

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

    private static function envValue(string $key, string $default = ''): string
    {
        if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
            return (string)$_ENV[$key];
        }
        $v = getenv($key);
        return $v === false ? $default : (string)$v;
    }

    private static function parseAutoMigrate(): bool
    {
        return !in_array(strtolower(trim(self::envValue('DB_AUTO_MIGRATE', 'true'))), ['0', 'false', 'no', 'off', ''], true);
    }

    /** @return array<string,mixed> */
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

    public static function reset(): void
    {
        self::$pdo = null;
        self::$bootstrapped = false;
        self::$migrator = null;
        self::$seeder = null;
        self::$lock = null;
        self::$orchestrator = null;
        self::$warnStateSeen = [];
    }

    public static function driver(): string
    {
        return self::$driver;
    }

    public static function isAutoMigrateEnabled(): bool
    {
        if (!empty(self::$config)) {
            return (bool)(self::$config['auto_migrate'] ?? true);
        }
        return self::parseAutoMigrate();
    }

    public static function setMigrationWaitForTest(?int $timeoutSeconds, ?float $pollSeconds): void
    {
        self::$migrationWaitOverride = $timeoutSeconds;
        self::$migrationPollOverride = $pollSeconds;
    }

    public static function sqlUpsert(string $table, string $columns, string $values): string
    {
        $isMySQL = self::$driver === 'mysql';
        return $isMySQL
            ? "REPLACE INTO {$table} ({$columns}) VALUES ({$values})"
            : "INSERT OR REPLACE INTO {$table} ({$columns}) VALUES ({$values})";
    }

    public static function sqlNow(): string
    {
        return self::$driver === 'mysql' ? 'UTC_TIMESTAMP()' : "datetime('now')";
    }

    public static function sqlInsertIgnore(string $table, string $columns, string $values): string
    {
        $isMySQL = self::$driver === 'mysql';
        return $isMySQL
            ? "INSERT IGNORE INTO {$table} ({$columns}) VALUES ({$values})"
            : "INSERT OR IGNORE INTO {$table} ({$columns}) VALUES ({$values})";
    }

    public static function columnExists(\PDO $pdo, string $table, string $column): bool
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

    public static function tableExists(\PDO $pdo, string $table): bool
    {
        try {
            $pdo->query('SELECT 1 FROM ' . $table . ' LIMIT 1');
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function dropTableIfExists(\PDO $pdo, string $table): void
    {
        try {
            $pdo->exec('DROP TABLE IF EXISTS ' . $table);
        } catch (\Throwable $e) {
            \App\Helper\Log::exception($e);
        }
    }


    public static function bootstrap(\PDO $pdo): void
    {
        if (self::$bootstrapped) {
            return;
        }
        self::$pdo = $pdo;
        if (empty(self::$config)) {
            self::init();
        }
        self::$driver = self::$config['driver'] ?? 'sqlite';
        self::buildComponents();
        $orchestrator = self::$orchestrator;
        if ($orchestrator === null) {
            throw new \RuntimeException('Database not bootstrapped');
        }
        $orchestrator->bootstrap();
        self::$bootstrapped = true;
    }

    /** @return array<string,mixed> */
    public static function schemaStatus(): array
    {
        return self::migrator()->schemaStatus();
    }
    /** @return array<string,mixed> */
    public static function schemaState(\PDO $pdo): array
    {
        return (new SchemaMigrator($pdo, (self::driver() ?: $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME))))->schemaState($pdo);
    }
    /** @return array<string,mixed> */
    public static function pendingChanges(\PDO $pdo): array
    {
        return (new SchemaMigrator($pdo, (self::driver() ?: $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME))))->pendingChanges($pdo);
    }
    /** @return array<string,mixed> */
    public static function migrateNow(): array
    {
        self::connect();
        return self::migrator()->migrateNow();
    }
    public static function seedRbac(string $seedMode): void
    {
        self::seeder()->seedRbac($seedMode);
    }
    /** @return array<mixed> */
    public static function reconcileColumns(\PDO $pdo, bool $strict): array
    {
        return (new SchemaMigrator($pdo, (self::driver() ?: $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME))))->reconcileColumns($pdo, $strict);
    }
    /** @return array<mixed> */
    public static function columnDrift(\PDO $pdo): array
    {
        return (new SchemaMigrator($pdo, (self::driver() ?: $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME))))->columnDrift($pdo);
    }
    /** @return array<mixed> */
    public static function missingTables(\PDO $pdo): array
    {
        return (new SchemaMigrator($pdo, (self::driver() ?: $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME))))->missingTables($pdo);
    }
    /** @return array<mixed> */
    public static function lastTableBuildLog(): array
    {
        return self::migrator()->lastTableBuildLog();
    }
    public static function tryAcquireLock(string $name, int $ttlSeconds = 600): ?string
    {
        return self::lock()->tryAcquireLock($name, $ttlSeconds);
    }
    public static function releaseLock(string $name, string $token): void
    {
        self::lock()->releaseLock($name, $token);
    }
    private function __construct()
    {
    }
}
