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
 * 用法：php cli/migrate.php
 * 退出码：0 = 迁移完成；1 = 致命错误（库连不上 / 建表或种子失败 / 迁移后仍缺表）。
 */
require __DIR__ . '/bootstrap.php';

use App\Config\AppConfig;
use App\Service\Database;

try {
    // 直接迁移：migrateNow() 自带连接（不依赖 bootstrap），因此空库 / 漏跑建表脚本
    // 也能一次建全，不会在补种子阶段先抛异常。
    $status = Database::migrateNow(); // 建表 + 补列 + 种子 + 自检 + 标记 schema 当前
} catch (\Throwable $e) {
    fwrite(STDERR, "错误：数据库迁移失败: {$e->getMessage()}\n");
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
    exit(1);
}

echo '✓ 数据库迁移完成（driver=' . $status['driver']
    . ', schema_version=' . ($status['schema_version'] ?? '(未标记)')
    . ', app_version=' . AppConfig::APP_VERSION . "）\n";
exit(0);
