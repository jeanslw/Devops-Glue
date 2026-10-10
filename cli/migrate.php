<?php
/**
 * 数据库迁移 CLI（建缺失表 + 存量库幂等补列 + 索引 + RBAC/管理员种子 + 自检 + 标记 schema 当前）。
 *
 * 与 Web 后台「系统信息 → 数据库」卡片里的「同步库结构」按钮完全等价（都走 Database::migrateNow()），区别在于：
 *   - **无视 DB_AUTO_MIGRATE**：即使设为 false（手动建库模式）也照常执行 DDL；
 *   - 供发布流水线调用（K8s Job / initContainer、Helm pre-upgrade hook、Compose 一次性服务），
 *     可用有 DDL 权限的账号执行，应用运行账号保持只读 DML（最小权限）。
 *
 * 为什么需要它：DB_AUTO_MIGRATE=false 时应用不跑 DDL，而 database/*.sql 只有
 * CREATE TABLE IF NOT EXISTS（对存量表是空操作），因此**存量库的新增列只能靠本命令或后台
 * 按钮的 ensureTables() 补齐**。生产上「应用账号无 DDL 权限」时，Web 按钮会因权限不足失败，
 * 本命令用高权限账号执行即可。
 *
 * 用法：
 *   php cli/migrate.php              执行迁移（写库）
 *   php cli/migrate.php --dry-run    只读预检：列出「将补的表 / 将补的列」与版本状态，不建表、不写数据
 *                                    （SQLite 下若库文件不存在，连接会创建一个空文件，但不会建表/写数据）
 *   php cli/migrate.php -h|--help    显示本帮助
 *
 * 审计：每次执行（成功与失败）都会写一条**操作日志**（`action=migrate_schema`、操作人 `system`，
 * 含 `from → to`），后台「日志中心 → 操作日志」可查——CLI 无前端，这是它唯一 web 可见的途径。
 *
 * 退出码：
 *   0 = 迁移完成；或 --dry-run 判定「无需迁移」
 *   2 = --dry-run 判定「需要迁移」（有结构变更待补，或版本标记未对齐）——供流水线据此决定是否执行迁移
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

// ── dry-run：只读预检（connect() 只建连，不 bootstrap、不建表、不补种子）──
if ($dryRun) {
    try {
        $pdo     = Database::connect();
        $status  = Database::schemaStatus();       // 只读：驱动 / 版本标记 / 各核心表存在性
        $pending = Database::pendingChanges($pdo); // 只读：将补的表与列（与 ensureTables() 同源）
    } catch (\Throwable $e) {
        fwrite(STDERR, "错误：预检失败（无法连接或读取库结构）: {$e->getMessage()}\n");
        exit(1);
    }

    $missingTables  = $pending['missing_tables'];
    $missingColumns = $pending['missing_columns'];
    $columnCount    = 0;
    foreach ($missingColumns as $columns) {
        $columnCount += count($columns);
    }
    $pendingCount   = count($missingTables) + $columnCount;
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

// ── 正式迁移 ──
// 迁移前先取「跃迁前版本」，供操作日志留痕（from → to）；connect() 只建连，不建表、不补种子。
try {
    $pdo    = Database::connect();
    $before = Database::schemaStatus()['schema_version'];
} catch (\Throwable $e) {
    fwrite(STDERR, "错误：数据库不可用: {$e->getMessage()}\n");
    exit(1);
}

// 写操作日志（action=migrate_schema，操作人 system）：CLI 没有前端，这是后台「日志中心 → 操作日志」
// 唯一能看到"迁移何时被谁执行、从哪个版本到哪个版本"的途径；仓储自身吞异常，绝不影响迁移结果。
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

try {
    // 直接迁移：migrateNow() 自带连接（不依赖 bootstrap），因此空库 / 漏跑建表脚本
    // 也能一次建全，不会在补种子阶段先抛异常。
    $status = Database::migrateNow(); // 建表 + 补列 + 种子 + 自检 + 标记 schema 当前
} catch (\Throwable $e) {
    fwrite(STDERR, "错误：数据库迁移失败: {$e->getMessage()}\n");
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
exit(0);
