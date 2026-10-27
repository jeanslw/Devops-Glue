<?php
/**
 * 数据库迁移 CLI（建缺失表 + 存量库幂等补列 + 索引 + RBAC/管理员种子 + 自检 + 标记 schema 当前）。
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
use App\Service\Database;
use App\Service\OperationLogRepository;

// ── 参数解析 ──
$dryRun = false;
foreach (array_slice($_SERVER['argv'] ?? [], 1) as $arg) {
    if ($arg === '--dry-run' || $arg === '-n') {
        $dryRun = true;
    } elseif ($arg === '--help' || $arg === '-h') {
        echo "用法：php cli/migrate.php [--dry-run|-n] [-h|--help]\n"
            . "  （无参数）   执行迁移：建缺失表 + 补列 + 索引 + 种子 + 自检 + 标记 schema 当前\n"
            . "  --dry-run    只读预检：打印将补的表/列与版本状态，不写库\n"
            . "  -h,--help    显示本帮助\n"
            . "退出码：0=完成/无需迁移；2=dry-run 判定需要迁移；1=致命错误\n";
        exit(0);
    } else {
        fwrite(STDERR, "错误：无法识别的参数 {$arg}\n用法：php cli/migrate.php [--dry-run|-n] [-h|--help]\n");
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
    $pendingCount   = count($missingTables) + $columnCount + count($safeMismatch);
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
