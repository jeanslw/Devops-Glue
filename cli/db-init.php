<?php
/**
 * 数据库初始化 CLI（建表 + 种子 + 标记 schema 当前）。
 *
 * 由容器 entrypoint 在 supervisord 启动前显式调用，确保 cron 循环（tag-cleanup / tag-backfill）
 * 启动时表已就绪，避免首次安装时 cron 抢在 HTTP 首次请求前运行、撞上「表缺失」。
 *
 * 注意：是否建表受 DB_AUTO_MIGRATE 控制——设为 false（手动建库模式）时本脚本只补种子，
 * 需要建表/补列请先执行 database/*.sql，或改用 cli/migrate.php（无视该开关）。
 *
 * 退出码：0 = 完成；1 = 致命错误（库连不上 / 建表失败）。
 */
require __DIR__ . '/bootstrap.php';

use App\Config\AppConfig;
use App\Service\Database;

try {
    Database::getPdo();   // 内部 createPdo() + bootstrap()：建表 + 种子 + 自检 + 标记 schema 当前
} catch (\Throwable $e) {
    fwrite(STDERR, "错误：数据库初始化失败: {$e->getMessage()}\n");
    if (!Database::isAutoMigrateEnabled()) {
        // 与 Web 后台「同步库结构」同一套归因：仅「账号缺 DDL 权限/只读」或「表缺失」才提示跑建表
        // 脚本 / 换高权账号；连不上或凭据被拒时应去查连接配置，避免把人引向白跑一遍建表脚本。
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
