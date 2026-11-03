<?php

declare(strict_types=1);

namespace App\Service;

/**
 * 由原 Database 拆分出的「SchemaMigrator」职责（门面转调，行为不变）。
 */
class SchemaMigrator
{
    private \PDO $pdo;
    private string $driver;
    private ?RbacSeeder $seeder = null;
    private ?DataRepairer $repairer = null;
    /** @var array<array{table: string, ok: bool, error: string|null}> */
    private array $tableBuildLog = [];
    private bool $columnReconcileDone = false;

    public function __construct(\PDO $pdo, string $driver, ?RbacSeeder $seeder = null, ?DataRepairer $repairer = null)
    {
        $this->pdo = $pdo;
        $this->driver = $driver;
        $this->seeder = $seeder;
        $this->repairer = $repairer;
        $this->tableBuildLog = [];
        $this->columnReconcileDone = false;
    }

    public function ensureTables(string $seedMode = Database::SEED_MODE_PRUNE): void
    {
        if ($this->repairer === null || $this->seeder === null) {
            throw new \LogicException('SchemaMigrator requires seeder and repairer for ensureTables');
        }
        $repairer = $this->repairer;
        $seeder = $this->seeder;
        $pdo = $this->pdo;
        $isMySQL = $this->driver === 'mysql';
        $this->tableBuildLog = [];

        $repairer->renameLegacyTables($pdo);

        $PK       = $isMySQL ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $TEXT_PK  = $isMySQL ? 'VARCHAR(255) PRIMARY KEY'      : 'TEXT PRIMARY KEY';
        $VARCHAR  = $isMySQL ? 'VARCHAR(255)'                   : 'TEXT';
        $VCHAR255 = $isMySQL ? 'VARCHAR(255) NOT NULL'          : 'TEXT NOT NULL';
        $NOW      = Database::sqlNow();
        $ENGINE   = $isMySQL ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';

        $TS_TYPE  = $isMySQL ? 'DATETIME' : 'TEXT';

        $this->buildTable($pdo, \App\Config\AppConfig::TABLE_JOB_GIT_MAP, "CREATE TABLE IF NOT EXISTS " . \App\Config\AppConfig::TABLE_JOB_GIT_MAP . " (
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

        $this->buildTable($pdo, \App\Config\AppConfig::TABLE_PLATFORM_VERSIONS, "CREATE TABLE IF NOT EXISTS " . \App\Config\AppConfig::TABLE_PLATFORM_VERSIONS . " (
            platform {$TEXT_PK},
            version TEXT NOT NULL
        ){$ENGINE}");

        $this->buildTable($pdo, \App\Config\AppConfig::TABLE_PIPELINE_ARTIFACTS, "CREATE TABLE IF NOT EXISTS " . \App\Config\AppConfig::TABLE_PIPELINE_ARTIFACTS . " (
            id {$PK},
            provider {$VARCHAR} NOT NULL,
            project_id {$VARCHAR} NOT NULL,
            pipeline_iid INTEGER NOT NULL,
            project_key {$VCHAR255},
            repository TEXT NOT NULL,
            tag {$VARCHAR} NOT NULL,
            digest {$VARCHAR} DEFAULT NULL,
            status {$VARCHAR} DEFAULT '',
            source_updated_at {$TS_TYPE} DEFAULT NULL,
            created_at {$TS_TYPE} DEFAULT ({$NOW}),
            updated_at {$TS_TYPE} DEFAULT ({$NOW}),
            UNIQUE (provider, project_id, pipeline_iid)
        ){$ENGINE}");

        $this->buildTable($pdo, \App\Config\AppConfig::TABLE_CUSTOM_BUILDS, "CREATE TABLE IF NOT EXISTS " . \App\Config\AppConfig::TABLE_CUSTOM_BUILDS . " (
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
            $this->buildTable($pdo, \App\Config\AppConfig::TABLE_CACHE, "CREATE TABLE IF NOT EXISTS " . \App\Config\AppConfig::TABLE_CACHE . " (
                cache_key VARCHAR(255) PRIMARY KEY,
                `value` MEDIUMTEXT NOT NULL,
                expires_at INTEGER
            ){$ENGINE}");
        } else {
            $this->buildTable($pdo, \App\Config\AppConfig::TABLE_CACHE, "CREATE TABLE IF NOT EXISTS " . \App\Config\AppConfig::TABLE_CACHE . " (
                cache_key TEXT PRIMARY KEY,
                value TEXT NOT NULL,
                expires_at INTEGER
            )");
        }

        $this->buildTable($pdo, \App\Config\AppConfig::TABLE_ADMIN_USERS, "CREATE TABLE IF NOT EXISTS " . \App\Config\AppConfig::TABLE_ADMIN_USERS . " (
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
            $this->buildTable($pdo, \App\Config\AppConfig::TABLE_APP_SETTINGS, "CREATE TABLE IF NOT EXISTS " . \App\Config\AppConfig::TABLE_APP_SETTINGS . " (
                setting_key VARCHAR(255) PRIMARY KEY,
                `value` MEDIUMTEXT NOT NULL,
                updated_at {$TS_TYPE} DEFAULT ({$NOW})
            ){$ENGINE}");
        } else {
            $this->buildTable($pdo, \App\Config\AppConfig::TABLE_APP_SETTINGS, "CREATE TABLE IF NOT EXISTS " . \App\Config\AppConfig::TABLE_APP_SETTINGS . " (
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
                    $sql = Database::sqlUpsert(\App\Config\AppConfig::TABLE_APP_SETTINGS, 'setting_key, value, updated_at', '?, ?, ' . Database::sqlNow());
                    $pdo->prepare($sql)->execute(['build_mode', $old['value']]);
                }
                $pdo->exec("DELETE FROM " . \App\Config\AppConfig::TABLE_CACHE . " WHERE cache_key = 'build_mode'");
            }
        } catch (\Exception $e) {
        }

        $this->buildTable($pdo, \App\Config\AppConfig::TABLE_BUILD_TAG_CACHE, "CREATE TABLE IF NOT EXISTS " . \App\Config\AppConfig::TABLE_BUILD_TAG_CACHE . " (
            id {$PK},
            project_key {$VARCHAR} DEFAULT '',
            provider {$VARCHAR} DEFAULT '',
            project_id {$VARCHAR} DEFAULT '',
            sha {$VARCHAR} DEFAULT '',
            pipeline_id {$VARCHAR} DEFAULT '',
            tag {$VARCHAR} DEFAULT '',
            repository {$VARCHAR} DEFAULT '',
            digest {$VARCHAR} DEFAULT NULL,
            source {$VARCHAR} DEFAULT 'log',
            status {$VARCHAR} DEFAULT '',
            created_at {$TS_TYPE} DEFAULT ({$NOW}),
            updated_at {$TS_TYPE} DEFAULT ({$NOW}),
            UNIQUE(provider, project_id, pipeline_id)
        ){$ENGINE}");

        $this->buildTable($pdo, \App\Config\AppConfig::TABLE_SECURITY_CHECKS, "CREATE TABLE IF NOT EXISTS " . \App\Config\AppConfig::TABLE_SECURITY_CHECKS . " (
            id {$PK},
            project {$VARCHAR} NOT NULL,
            sha {$VARCHAR} NOT NULL,
            pipeline_id {$VARCHAR} DEFAULT '',
            check_type {$VARCHAR} NOT NULL,
            state {$VARCHAR} NOT NULL,
            context {$VARCHAR} NOT NULL,
            description TEXT,
            tag {$VARCHAR} DEFAULT '',
            writeback_status {$VARCHAR} DEFAULT '',
            writeback_message TEXT,
            created_at {$TS_TYPE} DEFAULT ({$NOW})
        ){$ENGINE}");

        $this->buildTable($pdo, \App\Config\AppConfig::TABLE_OPERATION_LOGS, "CREATE TABLE IF NOT EXISTS " . \App\Config\AppConfig::TABLE_OPERATION_LOGS . " (
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
        $this->buildTable($pdo, \App\Config\AppConfig::TABLE_API_ACCESS_LOGS, "CREATE TABLE IF NOT EXISTS " . \App\Config\AppConfig::TABLE_API_ACCESS_LOGS . " (
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


        $this->buildTable($pdo, \App\Config\AppConfig::TABLE_ROLES, "CREATE TABLE IF NOT EXISTS " . \App\Config\AppConfig::TABLE_ROLES . " (
            id {$PK},
            name {$VARCHAR} NOT NULL UNIQUE,
            description TEXT,
            is_system TINYINT NOT NULL DEFAULT 0,
            created_at {$TS_TYPE} DEFAULT ({$NOW})
        ){$ENGINE}");

        if ($isMySQL) {
            $this->buildTable($pdo, \App\Config\AppConfig::TABLE_PERMISSIONS, "CREATE TABLE IF NOT EXISTS " . \App\Config\AppConfig::TABLE_PERMISSIONS . " (
                perm_key VARCHAR(128) PRIMARY KEY,
                description TEXT,
                parent_key VARCHAR(128),
                created_at {$TS_TYPE} DEFAULT NULL
            ){$ENGINE}");
        } else {
            $this->buildTable($pdo, \App\Config\AppConfig::TABLE_PERMISSIONS, "CREATE TABLE IF NOT EXISTS " . \App\Config\AppConfig::TABLE_PERMISSIONS . " (
                perm_key TEXT PRIMARY KEY,
                description TEXT,
                parent_key TEXT,
                created_at {$TS_TYPE} DEFAULT NULL
            )");
        }

        if ($isMySQL) {
            $this->buildTable($pdo, \App\Config\AppConfig::TABLE_ROLE_PERMISSIONS, "CREATE TABLE IF NOT EXISTS " . \App\Config\AppConfig::TABLE_ROLE_PERMISSIONS . " (
                role_id INTEGER NOT NULL,
                perm_key VARCHAR(128) NOT NULL,
                PRIMARY KEY (role_id, perm_key)
            ){$ENGINE}");
        } else {
            $this->buildTable($pdo, \App\Config\AppConfig::TABLE_ROLE_PERMISSIONS, "CREATE TABLE IF NOT EXISTS " . \App\Config\AppConfig::TABLE_ROLE_PERMISSIONS . " (
                role_id INTEGER NOT NULL,
                perm_key TEXT NOT NULL,
                PRIMARY KEY (role_id, perm_key)
            )");
        }

        if ($isMySQL) {
            $this->buildTable($pdo, \App\Config\AppConfig::TABLE_IMPLIED_RULES, "CREATE TABLE IF NOT EXISTS " . \App\Config\AppConfig::TABLE_IMPLIED_RULES . " (
                source_key VARCHAR(128) NOT NULL,
                target_key VARCHAR(128) NOT NULL,
                PRIMARY KEY (source_key, target_key)
            ){$ENGINE}");
        } else {
            $this->buildTable($pdo, \App\Config\AppConfig::TABLE_IMPLIED_RULES, "CREATE TABLE IF NOT EXISTS " . \App\Config\AppConfig::TABLE_IMPLIED_RULES . " (
                source_key TEXT NOT NULL,
                target_key TEXT NOT NULL,
                PRIMARY KEY (source_key, target_key)
            )");
        }

        if ($isMySQL) {
            $this->buildTable($pdo, \App\Config\AppConfig::TABLE_USER_IDENTITIES, "CREATE TABLE IF NOT EXISTS " . \App\Config\AppConfig::TABLE_USER_IDENTITIES . " (
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
            $this->buildTable($pdo, \App\Config\AppConfig::TABLE_USER_IDENTITIES, "CREATE TABLE IF NOT EXISTS " . \App\Config\AppConfig::TABLE_USER_IDENTITIES . " (
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

        $this->buildTable($pdo, \App\Config\AppConfig::TABLE_API_TOKENS, "CREATE TABLE IF NOT EXISTS " . \App\Config\AppConfig::TABLE_API_TOKENS . " (
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
            $this->buildTable($pdo, $isvcTable, "CREATE TABLE IF NOT EXISTS {$isvcTable} (
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
            $this->buildTable($pdo, $isvcTable, "CREATE TABLE IF NOT EXISTS {$isvcTable} (
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
            $this->buildTable($pdo, $oauthClientsTable, "CREATE TABLE IF NOT EXISTS {$oauthClientsTable} (
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
            $this->buildTable($pdo, $oauthClientsTable, "CREATE TABLE IF NOT EXISTS {$oauthClientsTable} (
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
            $this->buildTable($pdo, $platformConnectionsTable, "CREATE TABLE IF NOT EXISTS {$platformConnectionsTable} (
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
            $this->buildTable($pdo, $platformConnectionsTable, "CREATE TABLE IF NOT EXISTS {$platformConnectionsTable} (
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



        foreach ($this->columnMigrations() as $table => $columns) {
            foreach ($columns as $column => $definition) {
                if (!Database::columnExists($pdo, $table, $column)) {
                    $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
                }
            }
        }

        if (
            Database::columnExists($pdo, \App\Config\AppConfig::TABLE_JOB_GIT_MAP, 'harbor_repository')
            && Database::columnExists($pdo, \App\Config\AppConfig::TABLE_JOB_GIT_MAP, 'registry_repository')
        ) {
            $pdo->exec(
                'UPDATE ' . \App\Config\AppConfig::TABLE_JOB_GIT_MAP
                . " SET registry_repository = harbor_repository"
                . " WHERE (registry_repository IS NULL OR registry_repository = '')"
                . " AND harbor_repository IS NOT NULL AND harbor_repository != ''"
            );
        }

        if (Database::columnExists($pdo, \App\Config\AppConfig::TABLE_JOB_GIT_MAP, 'harbor_repository')) {
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

        $this->reconcileColumns($pdo, true);
        $seeder->seedRbac($seedMode);
        $this->createIndex('idx_pipeline_artifacts_project_key', \App\Config\AppConfig::TABLE_PIPELINE_ARTIFACTS, 'project_key');
        $this->createIndex('idx_pipeline_artifacts_created', \App\Config\AppConfig::TABLE_PIPELINE_ARTIFACTS, 'created_at');
        $this->createIndex('idx_pipeline_artifacts_digest', \App\Config\AppConfig::TABLE_PIPELINE_ARTIFACTS, 'digest');
        $this->createIndex('idx_job_git_map_current_path', \App\Config\AppConfig::TABLE_JOB_GIT_MAP, $isMySQL ? 'current_path(255)' : 'current_path');
        $this->createIndex('idx_security_checks_project', \App\Config\AppConfig::TABLE_SECURITY_CHECKS, 'project, check_type');
        $this->createIndex('idx_security_checks_sha', \App\Config\AppConfig::TABLE_SECURITY_CHECKS, 'sha');
        $this->createIndex('idx_security_checks_filter', \App\Config\AppConfig::TABLE_SECURITY_CHECKS, 'state, check_type, writeback_status, created_at');
        $this->createIndex('idx_security_checks_created', \App\Config\AppConfig::TABLE_SECURITY_CHECKS, 'created_at');
        $repairer->migrateSecurityChecksUniqueIndex();
        $this->createIndex('idx_custom_builds_job', \App\Config\AppConfig::TABLE_CUSTOM_BUILDS, 'job_name');
        $this->createIndex('idx_custom_builds_status', \App\Config\AppConfig::TABLE_CUSTOM_BUILDS, 'status');
        $this->createIndex('idx_operation_logs_created', \App\Config\AppConfig::TABLE_OPERATION_LOGS, 'created_at');
        $this->createIndex('idx_operation_logs_user', \App\Config\AppConfig::TABLE_OPERATION_LOGS, 'username');
        $this->createIndex('idx_operation_logs_action', \App\Config\AppConfig::TABLE_OPERATION_LOGS, 'action');
        $this->createIndex('idx_api_access_logs_created', \App\Config\AppConfig::TABLE_API_ACCESS_LOGS, 'created_at');
        $this->createIndex('idx_api_access_logs_token', \App\Config\AppConfig::TABLE_API_ACCESS_LOGS, 'token_name');
        $this->createIndex('idx_api_access_logs_result', \App\Config\AppConfig::TABLE_API_ACCESS_LOGS, 'result');

        if (!$isMySQL) {
            $this->createIndex('idx_user_identities_username', \App\Config\AppConfig::TABLE_USER_IDENTITIES, 'username');
        }

        if (!$isMySQL) {
            $baseDir = __DIR__ . '/../../config';
            $repairer->migrateJobGitMap("{$baseDir}/job_git_map.json", $pdo);
            $repairer->migratePlatformVersions("{$baseDir}/platform_versions.json", $pdo);
        }

        $repairer->migratePipelineArtifacts($pdo);

        $repairer->trimJobGitMapWhitespace($pdo);
        $repairer->normalizeAbortedSecurityStates($pdo);
    }

    public function buildTable(\PDO $pdo, string $table, string $ddl): void
    {
        try {
            Database::buildTable($pdo, $table, $ddl);
            $this->tableBuildLog[] = ['table' => $table, 'ok' => true, 'error' => null];
        } catch (\Throwable $e) {
            $this->tableBuildLog[] = ['table' => $table, 'ok' => false, 'error' => $e->getMessage()];
            throw $e;
        }
    }

    /**
     * 最近一次 ensureTables() 的逐表建表结果（成功/失败 + 错误信息）。
     *
     * @return array<array{table:string, ok:bool, error:string|null}>
     */
    public function lastTableBuildLog(): array
    {
        return $this->tableBuildLog;
    }

    public function createIndex(string $index, string $table, string $columns): void
    {
        try {
            if ($this->driver === 'mysql') {
                $stmt = $this->pdo->prepare(
                    'SELECT COUNT(*) FROM information_schema.statistics'
                    . ' WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?'
                );
                $stmt->execute([$table, $index]);
                if ((int)$stmt->fetchColumn() > 0) {
                    return;
                }
                $this->pdo->exec("CREATE INDEX {$index} ON {$table} ({$columns})");
            } else {
                $this->pdo->exec("CREATE INDEX IF NOT EXISTS {$index} ON {$table} ({$columns})");
            }
        } catch (\Exception $e) {
            \App\Helper\Log::exception($e);
        }
    }

    /**
     * 存量库增列映射：表 => 列 => 该驱动的列定义。新增列时在此追加一项。
     *
     * 由 ensureTables() 的补列循环与 pendingChanges() 的只读预检共用，避免两处清单漂移；
     * 类型按驱动解析（SQLite 无 VARCHAR 概念）。
     *
     * @return array<string, array<string, string>>
     */
    public function columnMigrations(): array
    {
        $isMySQL = $this->driver === 'mysql';
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
                'pipeline_id'       => "{$VARCHAR} DEFAULT ''",
            ],
            \App\Config\AppConfig::TABLE_PIPELINE_ARTIFACTS => [
                'digest' => "{$VARCHAR} DEFAULT NULL",
            ],
            \App\Config\AppConfig::TABLE_BUILD_TAG_CACHE => [
                'provider'   => "{$VARCHAR} DEFAULT ''",
                'project_id' => "{$VARCHAR} DEFAULT ''",
                'repository' => "{$VARCHAR} DEFAULT ''",
                'digest'     => "{$VARCHAR} DEFAULT NULL",
                'status'     => "{$VARCHAR} DEFAULT ''",
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
     * 只读对账：列存在但实际类型与 columnSpecs() 期望不一致的清单。
     *
     * @param \PDO $pdo 调用方自建连接
     * @return list<array{table:string, column:string, actual:string, expected:string, definition:string, safe:bool}>
     */
    public function columnDrift(\PDO $pdo): array
    {
        if ($this->driver !== 'mysql') {
            return [];
        }
        $drift = [];
        foreach (Database::columnSpecs() as $table => $columns) {
            if (!Database::tableExists($pdo, $table)) {
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
                $actualType   = Database::normalizeColumnType((string) $actual);
                $expectedType = Database::normalizeColumnType(Database::firstTypeToken($definition));
                if ($actualType === $expectedType) {
                    continue;
                }
                $drift[] = [
                    'table'      => $table,
                    'column'     => $column,
                    'actual'     => $actualType,
                    'expected'   => $expectedType,
                    'definition' => $definition,
                    'safe'       => Database::isWidenVarchar($actualType, $expectedType),
                ];
            }
        }
        return $drift;
    }

    /**
     * 列结构自愈：对 columnDrift() 中 safe=true 的漂移执行 MODIFY COLUMN。
     * 每进程只执行一次；表/列名来自代码常量与白名单清单，不接受外部输入。
     * strict=true：任一 MODIFY 失败抛出，与 ADD COLUMN 循环同为
     * fail-fast，尚未 markSchemaCurrent，下次启动重试。
     * strict=false：失败仅记 error 日志，不阻断业务请求；
     *
     * @return list<array{table:string, column:string, actual:string, expected:string}> 本次已对齐的列
     */
    public function reconcileColumns(\PDO $pdo, bool $strict): array
    {
        if ($this->columnReconcileDone) {
            return [];
        }
        $this->columnReconcileDone = true;

        $fixed = [];
        foreach ($this->columnDrift($pdo) as $d) {
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

    public function recordedVersion(string $key): ?string
    {
        try {
            return Database::readAppSetting($this->pdo, $key);
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function isVersionCurrent(?string $recorded): bool
    {
        return $recorded === \App\Config\AppConfig::APP_VERSION;
    }

    public function markSchemaCurrent(): void
    {
        $sql = Database::sqlUpsert(
            \App\Config\AppConfig::TABLE_APP_SETTINGS,
            'setting_key, value, updated_at',
            '?, ?, ' . Database::sqlNow()
        );
        $this->pdo->prepare($sql)->execute([Database::SCHEMA_VERSION_KEY, \App\Config\AppConfig::APP_VERSION]);
    }

    public function markSeedCurrent(): void
    {
        $sql = Database::sqlUpsert(
            \App\Config\AppConfig::TABLE_APP_SETTINGS,
            'setting_key, value, updated_at',
            '?, ?, ' . Database::sqlNow()
        );
        $this->pdo->prepare($sql)->execute([Database::SEED_VERSION_KEY, \App\Config\AppConfig::APP_VERSION]);
    }

    public function noteVersionTransition(string $key, ?string $before, string $seedMode, string $scope): void
    {
        $isDowngrade = $before !== null && $this->isVersionAhead($before);
        $action      = $isDowngrade
            ? Database::ACTION_SCHEMA_DOWNGRADE
            : ($before === null ? Database::ACTION_SCHEMA_INIT : Database::ACTION_SCHEMA_UPGRADE);

        if ($isDowngrade) {
            try {
                \App\Helper\Log::warning(
                    $seedMode === Database::SEED_MODE_MERGE
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

        $this->recordVersionTransition($action, $key, $before, $seedMode, $scope, $isDowngrade ? 'failure' : 'success');
    }

    public function recordVersionTransition(
        string $action,
        string $key,
        ?string $before,
        string $seedMode,
        string $scope,
        string $result
    ): void {
        Database::recordSystemAudit($action, $before ?? '', [
            'key'       => $key,
            'scope'     => $scope,
            'previous'  => $before,
            'current'   => \App\Config\AppConfig::APP_VERSION,
            'seed_mode' => $seedMode,
            'channel'   => 'bootstrap',
        ], $result, 'bootstrap');
    }

    /**
     * 显式迁移（后台同步库结构、cli/migrate.php）在降级方向的留痕。
     *
     * @param array<string,string|null> $recorded key => 改写前的记录值（null = 此前无记录）
     */
    public function noteExplicitDowngrade(array $recorded): void
    {
        $ahead = array_filter($recorded, function ($v): bool {
            return $v !== null && $this->isVersionAhead($v);
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
            function (string $carry, string $v): string {
                return version_compare($v, $carry, '>') ? $v : $carry;
            },
            (string) reset($ahead)
        );
        Database::recordSystemAudit(
            Database::ACTION_SCHEMA_DOWNGRADE,
            $maxAhead,
            [
                'keys'      => array_keys($ahead),
                'previous'  => $maxAhead,
                'current'   => \App\Config\AppConfig::APP_VERSION,
                'seed_mode' => Database::SEED_MODE_PRUNE,
                'channel'   => 'explicit_migrate',
            ],
            'failure',
            'explicit_migrate'
        );
    }

    /**
     * 迁移后的完整状态快照（含表清单、schema 列表漂移、列漂移）。
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
     *         items: list<array{table:string, column:string, actual:string, expected:string, definition:string, safe:bool}>
     *     }
     * }
     */
    public function schemaStatus(): array
    {
        $status = [];
        foreach (Database::schemaTables() as $t) {
            $status[$t] = Database::tableExists($this->pdo, $t);
        }
        $recorded = null;
        try {
            $stmt = $this->pdo->prepare(
                "SELECT value FROM " . \App\Config\AppConfig::TABLE_APP_SETTINGS . " WHERE setting_key = ?"
            );
            $stmt->execute([Database::SCHEMA_VERSION_KEY]);
            $row = $stmt->fetchColumn();
            $recorded = ($row === false) ? null : (string) $row;
        } catch (\Throwable $e) {
            $recorded = null;
        }
        $columnDrift = $this->columnDrift($this->pdo);
        $safeCount   = count(array_filter($columnDrift, fn (array $d): bool => $d['safe']));
        return [
            'driver'         => $this->driver,
            'schema_version' => $recorded,
            'app_version'    => \App\Config\AppConfig::APP_VERSION,
            'is_current'     => ($recorded !== null && $recorded === \App\Config\AppConfig::APP_VERSION),

            'auto_migrate'   => Database::isAutoMigrateEnabled(),
            'tables'         => $status,

            'schema_list_drift' => Database::schemaListDrift(),

            'column_drift' => [
                'safe_count'   => $safeCount,
                'unsafe_count' => count($columnDrift) - $safeCount,
                'items'        => $columnDrift,
            ],
        ];
    }

    /**
     * 读取 schema / 种子版本并判定是否与当前代码对齐（供 /healthz 探针一次查询拿到全部版本语义）
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
    public function schemaState(\PDO $pdo): array
    {
        $stmt = $pdo->prepare(
            'SELECT setting_key, value FROM ' . \App\Config\AppConfig::TABLE_APP_SETTINGS . ' WHERE setting_key IN (?, ?)'
        );
        $stmt->execute([Database::SCHEMA_VERSION_KEY, Database::SEED_VERSION_KEY]);

        $schemaVersion = null;
        $seedVersion   = null;
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $key = (string)($row['setting_key'] ?? '');
            if ($key === Database::SCHEMA_VERSION_KEY) {
                $schemaVersion = (string)($row['value'] ?? '');
            } elseif ($key === Database::SEED_VERSION_KEY) {
                $seedVersion = (string)($row['value'] ?? '');
            }
        }

        return [
            'schema_version' => $schemaVersion,
            'seed_version'   => $seedVersion,
            'schema_current' => $this->isVersionAligned($schemaVersion),
            'schema_ahead'   => $this->isVersionAhead($schemaVersion),
        ];
    }

    public function isVersionAhead(?string $recorded): bool
    {
        return $recorded !== null
            && version_compare($recorded, \App\Config\AppConfig::APP_VERSION, '>');
    }

    public function isVersionAligned(?string $schemaVersion): ?bool
    {
        if ($schemaVersion !== null) {
            return $schemaVersion === \App\Config\AppConfig::APP_VERSION;
        }
        return null;
    }

    /**
     * 缺失的核心表清单（/healthz 在判定版本未对齐时用它定位原因）。
     * 逐表探测要跑 N 次查询，故正常路径不调用，只在已降级时调用一次，保持探针毫秒级。
     *
     * @param \PDO $pdo 调用方自建连接
     * @return list<string>
     */
    public function missingTables(\PDO $pdo): array
    {
        $missing = [];
        foreach (Database::schemaTables() as $table) {
            if (!Database::tableExists($pdo, $table)) {
                $missing[] = $table;
            }
        }
        return $missing;
    }

    /**
     * 只读预检：计算若现在迁移会补哪些表 / 哪些列（不建连、不 bootstrap、不写库）。
     *
     * @param \PDO $pdo 调用方自建连接
     * @return array{
     *     missing_tables: list<string>,
     *     missing_columns: array<string, list<string>>,
     *     mismatched_columns: list<array{table:string, column:string, actual:string, expected:string, safe:bool}>
     * }
     */
    public function pendingChanges(\PDO $pdo): array
    {
        $missingColumns = [];
        foreach ($this->columnMigrations() as $table => $columns) {
            if (!Database::tableExists($pdo, $table)) {
                continue;
            }
            foreach (array_keys($columns) as $column) {
                if (!Database::columnExists($pdo, $table, (string) $column)) {
                    $missingColumns[$table][] = $column;
                }
            }
        }

        return [
            'missing_tables'     => $this->missingTables($pdo),
            'missing_columns'    => $missingColumns,
            'mismatched_columns' => $this->columnDrift($pdo),
        ];
    }

    /**
     * 手动触发迁移：建缺失表 + 补列 + 安全扩容列对齐 + RBAC/管理员种子 + 自检 + 标记 schema 当前。
     * 入口：后台同步库结构按钮（super_admin 专用）、cli/migrate.php自带连接，空库或手动建库模式漏跑脚本时也能一次建全。
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
     *     column_drift: array{safe_count:int, unsafe_count:int, items:list<array{table:string, column:string, actual:string, expected:string, definition:string, safe:bool}>},
     *     table_build_log: array<array{table: string, ok: bool, error: string|null}>
     * }
     */
    public function migrateNow(): array
    {
        if ($this->repairer === null || $this->seeder === null) {
            throw new \LogicException('SchemaMigrator requires seeder and repairer for migrateNow');
        }
        $repairer   = $this->repairer;
        $seeder     = $this->seeder;
        $prevSchema = $this->recordedVersion(Database::SCHEMA_VERSION_KEY);
        $prevSeed   = $this->recordedVersion(Database::SEED_VERSION_KEY);
        $this->ensureTables();
        $repairer->healCdServiceTokenScopes($this->pdo);
        $repairer->migrateScopeHarborReadToRegistryRead($this->pdo);
        $seeder->seedAdmin();
        $seeder->verifySeed();
        $this->markSchemaCurrent();
        $this->markSeedCurrent();
        $this->noteExplicitDowngrade([Database::SCHEMA_VERSION_KEY => $prevSchema, Database::SEED_VERSION_KEY => $prevSeed]);
        $status = $this->schemaStatus();

        $status['table_build_log'] = $this->lastTableBuildLog();
        return $status;
    }
}
