<?php
/**
 * 数据库初始化 CLI（建表 + 种子 + 标记 schema 当前）。
 * 容器 entrypoint 在 supervisord 启动前调用。
 *
 * DB_AUTO_MIGRATE=false（手动建库模式）时只补种子；建表或补列请跑 database/*.sql 或 cli/migrate.php。
 *
 * 退出码：0 = 完成；1 = 致命错误（库连不上 / 建表失败）。
 */
require __DIR__ . '/bootstrap.php';

use App\Config\AppConfig;
use App\Service\Database;

try {
    Database::getPdo();   // 内部含 bootstrap()：建表 + 种子 + 自检
} catch (\Throwable $e) {
    fwrite(STDERR, "错误：数据库初始化失败: {$e->getMessage()}\n");
    if (!Database::isAutoMigrateEnabled()) {
        $driverCode = $e instanceof \PDOException ? (int) ($e->errorInfo[1] ?? 0) : 0;
        $kind       = Database::classifyMigrationError((string) $e->getMessage(), (string) $e->getCode(), $driverCode);
        if ($kind === 'unreachable') {
            fwrite(STDERR, "提示：数据库不可达或凭据被拒，请检查 DB_HOST/DB_PORT/DB_USER/DB_PASS/DB_NAME 与网络连通性。\n");
        } else {
            $script = envVal('DB_DRIVER', 'mysql') === 'mysql' ? 'mysql_init.sql' : 'sqlite_init.sql';
            fwrite(STDERR, "提示：当前 DB_AUTO_MIGRATE=false（手动建库模式），应用不会自动建表/补列。\n");
            fwrite(STDERR, "      请先执行 database/{$script}，或用有 DDL 权限的账号执行 php cli/migrate.php。\n");
        }
    }
    exit(1);
}

echo '✓ 数据库初始化完成（driver=' . Database::driver() . ', app_version=' . AppConfig::APP_VERSION . "）\n";
exit(0);
