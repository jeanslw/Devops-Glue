<?php

declare(strict_types=1);

namespace App\Service;

/**
 * 由原 Database 拆分出的「DataRepairer」职责（门面转调，行为不变）。
 */
class DataRepairer
{
    private \PDO $pdo;
    private string $driver;

    public function __construct(\PDO $pdo, string $driver)
    {
        $this->pdo = $pdo;
        $this->driver = $driver;
    }

    public function healCdServiceTokenScopes(\PDO $pdo): void
    {
        try {
            $linkTable = \App\Config\AppConfig::TABLE_INTEGRATED_SERVICES;
            if (
                !Database::tableExists($pdo, $linkTable)
                || !Database::tableExists($pdo, \App\Config\AppConfig::TABLE_API_TOKENS)
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

    public function migrateScopeHarborReadToRegistryRead(\PDO $pdo): void
    {
        try {
            if (!Database::tableExists($pdo, \App\Config\AppConfig::TABLE_API_TOKENS)) {
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

    public function migrateSecurityChecksUniqueIndex(): void
    {
        $table = \App\Config\AppConfig::TABLE_SECURITY_CHECKS;
        if (!Database::tableExists($this->pdo, $table)) {
            return;
        }
        // 历史行按映射表回填 canonical pipeline_key；只有在无法解析映射时才退回裸 id，
        // 避免新旧写入使用不同 key 导致同一 Pipeline 出现两条审计且读取取到旧值。
        try {
            $this->backfillPipelineKeys($table);
        } catch (\Throwable $e) {
            \App\Helper\Log::error('backfill pipeline_key 失败', ['error' => $e->getMessage()]);
            return;
        }
        // 按新唯一键去重：同一 Pipeline 的重复审计保留最新一条；
        // 不同 Pipeline 的行不再互相覆盖。升级前请备份 ci_security_checks。
        try {
            $this->pdo->exec(
                "DELETE FROM {$table} WHERE id NOT IN ("
                . "SELECT keep_id FROM ("
                . "SELECT MAX(id) AS keep_id FROM {$table}"
                . " GROUP BY project, sha, check_type, context, COALESCE(pipeline_key, '')"
                . ") x)"
            );
        } catch (\Throwable $e) {
            \App\Helper\Log::error('dedupe ' . $table . ' 失败', ['error' => $e->getMessage()]);
            return;
        }

        $index   = 'uniq_security_check';
        $columns = $this->driver === 'mysql'
            ? 'project(255), sha(64), check_type(64), context(64), pipeline_key(191)'
            : 'project, sha, check_type, context, pipeline_key';
        try {
            $currentColumns = $this->indexColumns($table, $index);
            if ($currentColumns === null) {
                $this->pdo->exec("CREATE UNIQUE INDEX {$index} ON {$table} ({$columns})");
                return;
            }
            if (count($currentColumns) !== 5) {
                // 旧唯一键（4 列）挡住新键创建，先移除再重建；去重已在上面完成。
                if ($this->driver === 'mysql') {
                    $this->pdo->exec("DROP INDEX {$index} ON {$table}");
                } else {
                    $this->pdo->exec("DROP INDEX IF EXISTS {$index}");
                }
                $this->pdo->exec("CREATE UNIQUE INDEX {$index} ON {$table} ({$columns})");
            }
        } catch (\Throwable $e) {
            if (!SchemaMigrator::isDuplicateIndexError($e)) {
                \App\Helper\Log::exception($e);
            }
        }
    }

    private function backfillPipelineKeys(string $table): void
    {
        $mapTable = \App\Config\AppConfig::TABLE_JOB_GIT_MAP;
        $maps = Database::tableExists($this->pdo, $mapTable)
            ? $this->pdo->query("SELECT * FROM {$mapTable}")->fetchAll(\PDO::FETCH_ASSOC) ?: []
            : [];
        $rows = $this->pdo->query(
            "SELECT id, project, pipeline_id FROM {$table}"
            . " WHERE pipeline_key IS NULL OR pipeline_key = ''"
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        if ($rows === []) {
            return;
        }
        $stmt = $this->pdo->prepare("UPDATE {$table} SET pipeline_key = ? WHERE id = ?");
        foreach ($rows as $row) {
            $project = (string)($row['project'] ?? '');
            $iid = (int)($row['pipeline_id'] ?? 0);
            if ($project === '' || $iid <= 0) {
                continue;
            }
            $key = $this->canonicalKeyFromMaps($maps, $project, $iid);
            $stmt->execute([$key !== '' ? $key : (string)$row['pipeline_id'], $row['id']]);
        }
    }

    /**
     * @param array<int,array<string,mixed>> $maps
     */
    private function canonicalKeyFromMaps(array $maps, string $project, int $iid): string
    {
        $matched = null;
        foreach ($maps as $m) {
            if ((string)($m['job_name'] ?? '') === $project) {
                $matched = $m;
                break;
            }
        }
        if ($matched === null) {
            foreach ($maps as $m) {
                if ((string)($m['current_path'] ?? '') === $project) {
                    $matched = $m;
                    break;
                }
            }
        }
        if ($matched === null) {
            return '';
        }
        $provider = trim((string)($matched['build_provider'] ?? ''));
        if ($provider === '') {
            return '';
        }
        $job = trim((string)($matched['job_name'] ?? ''));
        $cp = trim((string)($matched['current_path'] ?? ''));
        $numeric = trim((string)($matched['project_id'] ?? ''));
        if ($provider === \App\Config\AppConfig::PROVIDER_GITLAB_CI && $numeric !== '') {
            $projectId = $numeric;
        } elseif ($provider === \App\Config\AppConfig::PROVIDER_JENKINS) {
            $projectId = $job !== '' ? $job : $project;
        } else {
            $projectId = $job !== '' ? $job : ($cp !== '' ? $cp : $project);
        }
        return (new PipelineIdentity($provider, $projectId, $iid))->storageKey();
    }

    /** @return list<string>|null 索引列名；索引不存在返回 null */
    private function indexColumns(string $table, string $index): ?array
    {
        if ($this->driver === 'mysql') {
            $stmt = $this->pdo->prepare(
                'SELECT COLUMN_NAME AS col FROM information_schema.statistics'
                . ' WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?'
                . ' ORDER BY SEQ_IN_INDEX'
            );
            $stmt->execute([$table, $index]);
            $cols = array_column($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [], 'col');
            return $cols ?: null;
        }
        $rows = $this->pdo->query("PRAGMA index_info({$index})")->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $cols = array_column($rows, 'name');
        return $cols ?: null;
    }

    public function renameLegacyTables(\PDO $pdo): void
    {
        foreach (\App\Config\AppConfig::LEGACY_TABLE_NAMES as $old => $new) {
            if (!Database::tableExists($pdo, $old)) {
                continue;
            }
            if (Database::tableExists($pdo, $new)) {
                \App\Helper\Log::warning('改名迁移跳过：新旧表并存，以新表为准', ['old' => $old, 'new' => $new]);
                continue;
            }
            $pdo->exec("ALTER TABLE {$old} RENAME TO {$new}");

            \App\Helper\Log::warning('表名收口迁移完成', ['old' => $old, 'new' => $new]);
        }
    }

    public function normalizeAbortedSecurityStates(\PDO $pdo): void
    {
        $pdo->exec(
            "UPDATE " . \App\Config\AppConfig::TABLE_SECURITY_CHECKS . " SET state = 'failed' WHERE state = 'aborted'"
        );
    }

    public function trimJobGitMapWhitespace(\PDO $pdo): void
    {
        try {
            $table = \App\Config\AppConfig::TABLE_JOB_GIT_MAP;
            if (!Database::tableExists($pdo, $table)) {
                return;
            }
            $pdo->exec("UPDATE {$table} SET job_name = TRIM(job_name), current_path = TRIM(current_path)");
        } catch (\Throwable $e) {
            \App\Helper\Log::error('trimJobGitMapWhitespace 失败', ['error' => $e->getMessage()]);
        }
    }

    public function migrateJobGitMap(string $path, \PDO $pdo): void
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

        $isMySQL = $this->driver === 'mysql';
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

    public function migratePlatformVersions(string $path, \PDO $pdo): void
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

        $isMySQL = $this->driver === 'mysql';
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

    public function migratePipelineArtifacts(\PDO $pdo): void
    {
        $artifactTable = \App\Config\AppConfig::TABLE_PIPELINE_ARTIFACTS;
        $tagTable      = 'ci_pipeline_tags';
        $mapTable      = \App\Config\AppConfig::TABLE_JOB_GIT_MAP;

        $existing = $pdo->query('SELECT 1 FROM ' . $artifactTable . ' LIMIT 1')->fetchColumn();
        if ($existing !== false || !Database::tableExists($pdo, $tagTable)) {
            Database::dropTableIfExists($pdo, $tagTable);
            return;
        }

        try {
            $hasDigestCol = false;
            try {
                $colStmt = $pdo->query('SELECT * FROM ' . $tagTable . ' LIMIT 0');
                for ($c = 0, $n = $colStmt->columnCount(); $c < $n; $c++) {
                    $meta = $colStmt->getColumnMeta($c);
                    if (isset($meta['name']) && strtolower((string) $meta['name']) === 'digest') {
                        $hasDigestCol = true;
                        break;
                    }
                }
            } catch (\Throwable $e) {
                $hasDigestCol = false;
            }
            $selectCols = $hasDigestCol
                ? 'project, pipeline_iid, tag, digest, harbor_repository, status, created_at'
                : 'project, pipeline_iid, tag, harbor_repository, status, created_at';
            $rows = $pdo->query(
                'SELECT ' . $selectCols . ' FROM ' . $tagTable . ' ORDER BY created_at DESC'
            )->fetchAll();
            if (!$rows) {
                Database::dropTableIfExists($pdo, $tagTable);
                return;
            }
            $maps = $pdo->query(
                'SELECT job_name, current_path, build_provider, project_id FROM ' . $mapTable
            )->fetchAll();
            $insert = Database::sqlInsertIgnore(
                $artifactTable,
                'provider, project_id, pipeline_iid, project_key, repository, tag, digest, status, source_updated_at, created_at, updated_at',
                '?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ' . Database::sqlNow()
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
                $legacyDigest = $hasDigestCol ? trim((string) ($row['digest'] ?? '')) : '';
                $stmt->execute([
                    $provider,
                    $projectId,
                    (int) $row['pipeline_iid'],
                    $project,
                    (string) ($row['harbor_repository'] ?? ''),
                    (string) $row['tag'],
                    $legacyDigest !== '' ? $legacyDigest : null,
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

        Database::dropTableIfExists($pdo, $tagTable);
    }
}
