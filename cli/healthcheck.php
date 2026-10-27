<?php
/**
 * 容器健康检查 CLI（docker-compose healthcheck 调用）：数据库可达退出 0，否则 1。
 * 只探连通性，不建表、不迁移。
 */
require __DIR__ . '/bootstrap.php';

use App\Service\Database;

try {
    $pdo = Database::createPdo();
    $pdo->query('SELECT 1')->fetchColumn();
} catch (\Throwable $e) {
    fwrite(STDERR, 'healthcheck: 数据库不可达: ' . $e->getMessage() . "\n");
    exit(1);
}

exit(0);
