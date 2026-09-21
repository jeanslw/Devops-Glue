<?php
/**
 * 数据库初始化 CLI（建表 + 种子 + 标记 schema 当前）。
 *
 * 由容器 entrypoint 在 supervisord 启动前显式调用，确保 cron 循环（tag-cleanup / tag-backfill）
 * 启动时表已就绪，避免首次安装时 cron 抢在 HTTP 首次请求前运行、撞上「表缺失」。
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
    exit(1);
}

echo '✓ 数据库初始化完成（driver=' . Database::driver() . ', app_version=' . AppConfig::APP_VERSION . "）\n";
exit(0);
