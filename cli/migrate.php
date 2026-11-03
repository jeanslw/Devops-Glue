<?php

/**
 * 数据库迁移 CLI（建缺失表 + 存量库幂等补列 + 索引 + RBAC/管理员种子 + 自检 + 标记 schema 当前）。
 *
 * 本脚本在 Database::migrateNow() 之外，额外幂等处理「本次发布」的约束迁移与数据回填：
 *   1) ci_build_tag_cache 唯一键由旧「单列 sha」改为「(provider, project_id, pipeline_id)」，
 *      以支撑「镜像 Tag」按流水线维度去重（同 sha 多构建各显各的 tag）。MySQL / SQLite 均兼容。
 *      注意：ensureTables()/columnMigrations() 只增列、不动约束，故在此单独处理。
 *   2) ci_pipeline_artifacts.digest 缺失时补列，并从镜像仓库按 repository+tag 回填（原 migrate-artifact-digests.php 已并入）。
 * 用法：
 *   php cli/migrate.php              执行迁移
 *   php cli/migrate.php --dry-run    只读预检：列出将补的表与列以及版本状态，不建表、不写数据
 *                                    （SQLite 下若库文件不存在，连接会创建一个空文件，但不会建表或写数据）
 *   php cli/migrate.php -h|--help    显示本帮助
 *
 * 退出码：
 *   0 = 迁移完成；或 --dry-run 判定无需迁移
 *   2 = --dry-run 判定需要迁移（有结构变更待补，或版本标记未对齐），供流水线决定是否执行迁移
 *   1 = 致命错误（库连不上 / 建表或种子失败 / 迁移后仍缺表 / 参数无法识别）
 */

require __DIR__ . '/bootstrap.php';

use App\Config\AppConfig;
use App\Service\AppSettingRepository;
use App\Service\Database;
use App\Service\HarborService;
use App\Service\I18nService;
use App\Service\OperationLogRepository;
use App\Service\PlatformConnectionRepository;
use App\Service\Registry\DockerRegistryProvider;
use App\Service\Registry\HarborProvider;
use GuzzleHttp\Client;

/**
 * ci_build_tag_cache 是否需要把唯一键从旧「单列 sha」迁移为「(provider, project_id, pipeline_id)」。
 *
 * 背景：本次发布把「镜像 Tag」缓存的写入去重维度从 sha 改为「按流水线」（同 sha 多构建各显各的 tag）。
 * upsert 使用 INSERT OR REPLACE / REPLACE INTO，会命中任意唯一约束；存量库是 CREATE TABLE IF NOT EXISTS
 * 建出的，旧 sha UNIQUE 不会被自动改，导致同 sha 不同构建仍 REPLACE 塌缩。ensureTables()/columnMigrations()
 * 只增列、不动约束，故在此单独幂等处理。MySQL 与 SQLite 均兼容。
 *
 * @return bool true=需要迁移
 */
function ciBuildTagCacheNeedsKeyMigration(\PDO $pdo, string $driver): bool
{
    $table = \App\Config\AppConfig::TABLE_BUILD_TAG_CACHE;
    if (!\App\Service\Database::tableExists($pdo, $table)) {
        return false;
    }
    if ($driver === 'mysql') {
        $stmt = $pdo->prepare(
            "SELECT 1 FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND NON_UNIQUE = 0
               AND COLUMN_NAME IN ('provider','project_id','pipeline_id')
             GROUP BY INDEX_NAME HAVING COUNT(DISTINCT COLUMN_NAME) = 3 LIMIT 1"
        );
        $stmt->execute([$table]);
        return $stmt->fetchColumn() === false;
    }
    // SQLite：检查是否已存在覆盖 (provider, project_id, pipeline_id) 的唯一索引
    $indexes = $pdo->query("PRAGMA index_list({$table})")->fetchAll(\PDO::FETCH_ASSOC);
    foreach ($indexes as $idx) {
        if (empty($idx['unique'])) {
            continue;
        }
        $cols = $pdo->query("PRAGMA index_info({$idx['name']})")->fetchAll(\PDO::FETCH_ASSOC);
        $names = array_map(static fn (array $c): string => (string) $c['name'], $cols);
        if (
            in_array('provider', $names, true)
            && in_array('project_id', $names, true)
            && in_array('pipeline_id', $names, true)
            && count($names) === 3
        ) {
            return false;
        }
    }
    return true;
}

/** 执行 ci_build_tag_cache 唯一键迁移（幂等，已判断过 needs 后再调用）。 */
function ciBuildTagCacheMigrateKey(\PDO $pdo, string $driver): void
{
    $table = \App\Config\AppConfig::TABLE_BUILD_TAG_CACHE;
    if (!\App\Service\Database::tableExists($pdo, $table)) {
        return;
    }
    if ($driver === 'mysql') {
        // 删除旧的、单列 sha 的唯一索引（inline UNIQUE 在 MySQL 中索引名即列名，但以防万一按列探测）
        $old = $pdo->prepare(
            "SELECT INDEX_NAME FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND NON_UNIQUE = 0 AND COLUMN_NAME = 'sha'
             GROUP BY INDEX_NAME HAVING COUNT(*) = 1"
        );
        $old->execute([$table]);
        $oldName = $old->fetchColumn();
        if ($oldName !== false) {
            $pdo->exec("ALTER TABLE `{$table}` DROP INDEX `{$oldName}`");
            echo "  [✓] 已删除 ci_build_tag_cache 旧唯一索引 `{$oldName}`（单列 sha）\n";
        }
        $has = $pdo->prepare(
            "SELECT 1 FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND NON_UNIQUE = 0
               AND COLUMN_NAME IN ('provider','project_id','pipeline_id')
             GROUP BY INDEX_NAME HAVING COUNT(DISTINCT COLUMN_NAME) = 3 LIMIT 1"
        );
        $has->execute([$table]);
        if ($has->fetchColumn() === false) {
            $pdo->exec("ALTER TABLE `{$table}` ADD UNIQUE KEY `uq_build_tag_cache` (`provider`, `project_id`, `pipeline_id`)");
            echo "  [✓] 已添加 ci_build_tag_cache 复合唯一键 uq_build_tag_cache (provider, project_id, pipeline_id)\n";
        } else {
            echo "  [-] ci_build_tag_cache 复合唯一键已存在，跳过\n";
        }
        return;
    }
    // SQLite：重建表（SQLite 不便直接 DROP 命名 UNIQUE 约束），保留每 (provider, project_id, pipeline_id) 最新一行
    // 注意：不写 created_at/updated_at 的 DEFAULT(datetime(...))，旧版 SQLite 不支持非常量默认值；
    // 下面 SELECT 会从旧表搬运这两列的值，重建后不影响既有数据。status 列需一并保留（migrateNow 已补列，重建不能丢）。
    $pdo->exec("CREATE TABLE `{$table}_new` (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        project_key TEXT DEFAULT '',
        provider TEXT DEFAULT '',
        project_id TEXT DEFAULT '',
        sha TEXT DEFAULT '',
        pipeline_id TEXT DEFAULT '',
        tag TEXT DEFAULT '',
        repository TEXT DEFAULT '',
        digest TEXT DEFAULT NULL,
        source TEXT DEFAULT 'log',
        status TEXT DEFAULT '',
        created_at TEXT,
        updated_at TEXT,
        UNIQUE(provider, project_id, pipeline_id)
    )");
    $pdo->exec("INSERT INTO `{$table}_new` (id, project_key, provider, project_id, sha, pipeline_id, tag, repository, digest, source, status, created_at, updated_at)
        SELECT t.id, t.project_key, t.provider, t.project_id, t.sha, t.pipeline_id, t.tag, t.repository, t.digest, t.source, t.status, t.created_at, t.updated_at
        FROM `{$table}` t
        WHERE t.id = (SELECT MAX(id) FROM `{$table}` WHERE provider = t.provider AND project_id = t.project_id AND pipeline_id = t.pipeline_id)");
    $pdo->exec("DROP TABLE `{$table}`");
    $pdo->exec("ALTER TABLE `{$table}_new` RENAME TO `{$table}`");
    echo "  [✓] 已重建 ci_build_tag_cache，唯一键改为 (provider, project_id, pipeline_id)（重复行按最新保留）\n";
}

/**
 * ci_pipeline_artifacts 是否已存在 digest 列（MySQL/SQLite）。
 */
function ciPipelineArtifactsDigestColumnExists(\PDO $pdo, string $driver, string $table): bool
{
    if ($driver === 'mysql') {
        $c = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}' AND COLUMN_NAME = 'digest'"
        )->fetchColumn();
        return (int) $c > 0;
    }
    $cols = $pdo->query("PRAGMA table_info({$table})")->fetchAll();
    return in_array('digest', array_map(static fn (array $r): string => (string) ($r['name'] ?? ''), $cols), true);
}

/** 确保 ci_pipeline_artifacts 存在 digest 列（幂等，MySQL/SQLite）。 */
function ciPipelineArtifactsEnsureDigestColumn(\PDO $pdo, string $driver, string $table): void
{
    if (ciPipelineArtifactsDigestColumnExists($pdo, $driver, $table)) {
        return;
    }
    if ($driver === 'mysql') {
        $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN digest VARCHAR(255) DEFAULT NULL");
        echo "  [✓] 已添加 ci_pipeline_artifacts.digest 列 (MySQL)\n";
    } else {
        $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN digest TEXT DEFAULT NULL");
        echo "  [✓] 已添加 ci_pipeline_artifacts.digest 列 (SQLite)\n";
    }
}

/**
 * 回填 ci_pipeline_artifacts 缺失的 digest（从镜像仓库按 repository+tag 解析）。幂等：仅更新 digest 为空且 tag/repo 非空的行。
 *
 * @param int  $limit  0 = 不限制
 */
function ciPipelineArtifactsBackfillDigests(\PDO $pdo, bool $dryRun, int $limit): void
{
    $table = \App\Config\AppConfig::TABLE_PIPELINE_ARTIFACTS;
    if (!\App\Service\Database::tableExists($pdo, $table)) {
        echo "  [-] ci_pipeline_artifacts 表不存在，跳过 digest 回填\n";
        return;
    }
    $driver = \App\Service\Database::driver();
    if (!$dryRun) {
        ciPipelineArtifactsEnsureDigestColumn($pdo, $driver, $table);
    }

    $registry = null;
    $regType = (new \App\Service\AppSettingRepository($pdo))->getRegistryType();
    $platformKey = $regType === 'registry' ? 'registry' : 'harbor';
    $registryCfg = (new \App\Service\PlatformConnectionRepository($pdo))->resolveConnections()[$platformKey] ?? [];
    $registryUrl = rtrim((string) ($registryCfg['url'] ?? ''), '/');
    $i18n = new \App\Service\I18nService(__DIR__ . '/../lang');
    if ($registryUrl !== '') {
        if ((new \App\Service\AppSettingRepository($pdo))->getRegistryType() === 'registry') {
            $registryClient = new \GuzzleHttp\Client([
                'base_uri'        => $registryUrl . '/',
                'headers'         => ['Accept' => 'application/json'],
                'connect_timeout' => 5,
                'timeout'         => 15,
                'http_errors'     => false,
            ]);
            $registry = new \App\Service\Registry\DockerRegistryProvider(
                $registryClient,
                $i18n,
                (string) ($registryCfg['username'] ?? ''),
                (string) ($registryCfg['password'] ?? ''),
                null
            );
        } else {
            $harborClient = new \GuzzleHttp\Client([
                'base_uri'        => $registryUrl,
                'auth'            => [
                    $registryCfg['username'] ?? 'admin',
                    $registryCfg['password'] ?? '',
                ],
                'headers'         => ['Accept' => 'application/json'],
                'connect_timeout' => 5,
                'timeout'         => 15,
            ]);
            $registry = new \App\Service\Registry\HarborProvider(
                new \App\Service\HarborService($harborClient, $pdo, $i18n, null)
            );
        }
    }

    if ($registry === null) {
        echo "  [-] 镜像仓库未配置（平台连接 URL 为空），跳过 digest 回填\n";
        return;
    }

    $sql = 'SELECT id, repository, tag FROM ' . $table
        . " WHERE (digest IS NULL OR digest = '') AND tag != '' AND repository != ''"
        . ' ORDER BY id ASC';
    if ($limit > 0) {
        $sql .= ' LIMIT ' . (int) $limit;
    }

    $rows = $pdo->query($sql)->fetchAll();
    $total = count($rows);
    if ($dryRun) {
        echo "  [-] ci_pipeline_artifacts 待回填 digest 行数: {$total}（dry-run 不发起仓库解析）\n";
        return;
    }

    $updated = 0;
    $skipped = 0;
    $failed  = 0;
    echo "待回填 digest 行数: {$total}\n";
    $updateStmt = $pdo->prepare(
        'UPDATE ' . $table . ' SET digest = ?, updated_at = ' . \App\Service\Database::sqlNow() . ' WHERE id = ?'
    );
    foreach ($rows as $row) {
        $id   = (int) $row['id'];
        $repo = (string) $row['repository'];
        $tag  = (string) $row['tag'];
        $digest = '';
        try {
            $details = $registry->getTagDetails($repo);
            if (!isset($details['error'])) {
                foreach ($details as $d) {
                    if (!is_array($d)) {
                        continue;
                    }
                    $name = (string) ($d['name'] ?? $d['tag'] ?? '');
                    if ($name === $tag) {
                        $digest = trim((string) ($d['digest'] ?? ''));
                        break;
                    }
                }
            }
        } catch (\Throwable $e) {
            $failed++;
            fwrite(STDERR, "失败 id={$id} {$repo}:{$tag} — {$e->getMessage()}\n");
            continue;
        }

        if ($digest === '') {
            $skipped++;
            echo "跳过 id={$id} {$repo}:{$tag}（仓库无此 tag 或无 digest）\n";
            continue;
        }

        $updateStmt->execute([$digest, $id]);
        $updated++;
        echo "已更新 id={$id} {$repo}:{$tag} → {$digest}\n";
    }
    echo "digest 回填完成：updated={$updated} skipped={$skipped} failed={$failed} total={$total}\n";
}

// ── 参数解析 ──
$dryRun = false;
$limit  = 0;
foreach (array_slice($_SERVER['argv'] ?? [], 1) as $arg) {
    if ($arg === '--dry-run' || $arg === '-n') {
        $dryRun = true;
    } elseif (str_starts_with($arg, '--limit=')) {
        $limit = max(0, (int) substr($arg, 8));
    } elseif ($arg === '--help' || $arg === '-h') {
        echo "用法：php cli/migrate.php [--dry-run|-n] [--limit=N] [-h|--help]\n"
            . "  （无参数）   执行迁移：建缺失表 + 补列 + 索引 + 种子 + 自检 + 标记 schema 当前\n"
            . "  --dry-run    只读预检：打印将补的表/列与版本状态，不写库\n"
            . "  --limit=N    仅回填前 N 条缺失镜像摘要（digest）的行，0=不限制\n"
            . "  -h,--help    显示本帮助\n"
            . "退出码：0=完成/无需迁移；2=dry-run 判定需要迁移；1=致命错误\n";
        exit(0);
    } else {
        fwrite(STDERR, "错误：无法识别的参数 {$arg}\n用法：php cli/migrate.php [--dry-run|-n] [--limit=N] [-h|--help]\n");
        exit(1);
    }
}

$drift = Database::schemaListDrift();
if (!$drift['ok']) {
    fwrite(
        STDERR,
        '⚠️ 警告：Schema 探测清单与 AppConfig 表常量不一致：' . implode(', ', $drift['tables']) . "\n"
        . "         影响：后台「数据管理 → 数据表清单」与 /healthz 的 tables_missing 会漏报这些表。\n"
        . "         处置：在 Database::schemaTables() 补上缺失的应用表（或核对手写/被排除项），并同步 AppConfig。\n"
    );
}

if ($dryRun) {
    try {
        $pdo     = Database::connect();
        $status  = Database::schemaStatus();
        $pending = Database::pendingChanges($pdo);
    } catch (\Throwable $e) {
        fwrite(STDERR, "错误：预检失败（无法连接或读取库结构）: {$e->getMessage()}\n");
        exit(1);
    }

    $missingTables  = $pending['missing_tables'];
    $missingColumns = $pending['missing_columns'];
    $mismatched     = $pending['mismatched_columns'];
    $safeMismatch   = array_values(array_filter($mismatched, static fn (array $d): bool => $d['safe']));
    $unsafeMismatch = array_values(array_filter($mismatched, static fn (array $d): bool => !$d['safe']));
    $columnCount    = 0;
    foreach ($missingColumns as $columns) {
        $columnCount += count($columns);
    }
    $btcNeeded     = ciBuildTagCacheNeedsKeyMigration($pdo, $status['driver']);
    $pendingCount   = count($missingTables) + $columnCount + count($safeMismatch) + ($btcNeeded ? 1 : 0);
    $needsMigration = $pendingCount > 0 || !$status['is_current'];

    echo "（dry-run 只读预检：不建表、不写数据）\n";
    echo 'driver=' . $status['driver']
        . ', app_version=' . $status['app_version']
        . ', schema_version=' . ($status['schema_version'] ?? '(未标记)')
        . ', schema_current=' . ($status['is_current'] ? '是' : '否') . "\n";
    echo '将补的表（' . count($missingTables) . '）：'
        . ($missingTables === [] ? "无\n" : "\n  - " . implode("\n  - ", $missingTables) . "\n");
    if ($columnCount === 0) {
        echo "将补的列（0）：无\n";
    } else {
        echo "将补的列（{$columnCount}）：\n";
        foreach ($missingColumns as $table => $columns) {
            echo "  - {$table}: " . implode(', ', $columns) . "\n";
        }
    }
    echo "ci_build_tag_cache 唯一键："
        . ($btcNeeded
            ? "需由「单列 sha」迁移为「(provider, project_id, pipeline_id)」\n"
            : "已是最新（(provider, project_id, pipeline_id)），无需迁移\n");
    // ci_pipeline_artifacts.digest 回填预检
    $digestTable = \App\Config\AppConfig::TABLE_PIPELINE_ARTIFACTS;
    if (\App\Service\Database::tableExists($pdo, $digestTable)) {
        if (ciPipelineArtifactsDigestColumnExists($pdo, $status['driver'], $digestTable)) {
            $digestTodo = (int) $pdo->query(
                "SELECT COUNT(*) FROM `{$digestTable}` WHERE (digest IS NULL OR digest = '') AND tag != '' AND repository != ''"
            )->fetchColumn();
            echo "ci_pipeline_artifacts 镜像摘要(digest)：列已存在，待回填行数 {$digestTodo}\n";
        } else {
            echo "ci_pipeline_artifacts 镜像摘要(digest)：列缺失，迁移将补列并回填\n";
        }
    } else {
        echo "ci_pipeline_artifacts：表不存在，跳过 digest 回填\n";
    }
    echo "（注：索引与一次性数据搬迁随迁移幂等执行，不在此报告中）\n";
    if ($safeMismatch === []) {
        echo "将对齐的列类型（0）：无\n";
    } else {
        echo "将对齐的列类型（" . count($safeMismatch) . "，仅安全扩容 MODIFY）：\n";
        foreach ($safeMismatch as $d) {
            echo "  - {$d['table']}.{$d['column']}: {$d['actual']} → {$d['expected']}\n";
        }
    }
    foreach ($unsafeMismatch as $d) {
        fwrite(STDERR, "⚠️ 不可自动修复的列漂移（需人工评估，迁移不会改动）："
            . "{$d['table']}.{$d['column']} {$d['actual']} → {$d['expected']}\n");
    }

    if (!$needsMigration) {
        echo "结论：库结构与版本标记均与当前代码一致，无需迁移。\n";
        exit(0);
    }
    echo '结论：需要迁移（结构待补 ' . $pendingCount . ' 项；版本标记'
        . ($status['is_current'] ? '已对齐' : '未对齐（' . ($status['schema_version'] ?? '无记录') . ' → ' . AppConfig::APP_VERSION . '）')
        . "）。\n";
    echo "      确认后执行：php cli/migrate.php（去掉 --dry-run）\n";
    exit(2);
}

try {
    $pdo    = Database::connect();
    $before = Database::schemaStatus()['schema_version'];
} catch (\Throwable $e) {
    fwrite(STDERR, "错误：数据库不可用: {$e->getMessage()}\n");
    exit(1);
}

$recordMigration = static function (string $result, array $detail) use ($pdo, $before): void {
    (new OperationLogRepository($pdo))->record(
        'system',
        'migrate_schema',
        '',
        ['from' => $before, 'to' => AppConfig::APP_VERSION, 'channel' => 'cli'] + $detail,
        '',
        $result,
        'system'
    );
};

$printTableLog = static function (array $log): void {
    if ($log === []) {
        echo "建表过程：无（表均已存在，无需 CREATE）\n";
        return;
    }
    foreach ($log as $row) {
        $line = '  [' . (!empty($row['ok']) ? '✓ 成功' : '✗ 失败') . '] ' . $row['table'];
        if (!empty($row['error'])) {
            $line .= ' — ' . $row['error'];
        }
        echo $line . "\n";
    }
};

try {
    $status = Database::migrateNow();
    // 本次发布额外约束迁移：ci_build_tag_cache 唯一键 sha → (provider, project_id, pipeline_id)
    $driver = Database::driver();
    if (ciBuildTagCacheNeedsKeyMigration($pdo, $driver)) {
        ciBuildTagCacheMigrateKey($pdo, $driver);
    } else {
        echo "  [-] ci_build_tag_cache 唯一键已是 (provider, project_id, pipeline_id)，无需迁移\n";
    }
    // 本次发布额外数据回填：ci_pipeline_artifacts.digest 从镜像仓库按 repository+tag 解析（幂等）
    try {
        ciPipelineArtifactsBackfillDigests($pdo, false, $limit);
    } catch (\Throwable $e) {
        fwrite(STDERR, "警告：digest 回填失败（不影响已完成的表结构迁移）: {$e->getMessage()}\n");
    }
} catch (\Throwable $e) {
    fwrite(STDERR, "错误：数据库迁移失败: {$e->getMessage()}\n");
    $printTableLog(Database::lastTableBuildLog());
    $recordMigration('failure', ['error' => $e->getMessage()]);
    exit(1);
}

$missing = [];
foreach ($status['tables'] as $table => $exists) {
    if (!$exists) {
        $missing[] = $table;
    }
}
if ($missing !== []) {
    fwrite(STDERR, '错误：迁移后仍缺失数据表: ' . implode(', ', $missing) . "\n");
    $recordMigration('failure', ['error' => '迁移后仍缺失数据表', 'missing_tables' => $missing]);
    exit(1);
}

$recordMigration('success', ['driver' => $status['driver'], 'schema_version' => $status['schema_version']]);

echo '✓ 数据库迁移完成（driver=' . $status['driver']
    . ', schema_version=' . ($status['schema_version'] ?? '(未标记)')
    . ', app_version=' . AppConfig::APP_VERSION . "）\n";
$printTableLog(Database::lastTableBuildLog());
exit(0);
