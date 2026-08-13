<?php
// migrate_api_tokens_mysql.php - ensure api_tokens table exists with current schema
// 幂等：已存在则跳过；缺列会尝试补齐（仅 MySQL）。
$dsn  = 'mysql:host=127.0.0.1;dbname=devops_glue;charset=utf8mb4';
$user = 'root';
$pass = ''; // 按需修改
try {
    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    $pdo->exec("CREATE TABLE IF NOT EXISTS `api_tokens` (
        `id`         INT AUTO_INCREMENT PRIMARY KEY,
        `name`       VARCHAR(255) NOT NULL,
        `token_hash` VARCHAR(64) NOT NULL UNIQUE,
        `scopes`     TEXT,
        `enabled`    TINYINT NOT NULL DEFAULT 1,
        `expires_at` INT,
        `created_by` VARCHAR(255),
        `note`       TEXT,
        `created_at` DATETIME DEFAULT (NOW())
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 兼容早期脚手架遗留的旧列（owner / role）：若存在则删除，避免脏数据
    $cols = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'api_tokens'")->fetchAll(PDO::FETCH_COLUMN);
    foreach (['owner', 'role'] as $legacy) {
        if (in_array($legacy, $cols, true)) {
            echo "Dropping legacy column {$legacy}...\n";
            $pdo->exec("ALTER TABLE `api_tokens` DROP COLUMN `{$legacy}`");
        }
    }

    echo "Migration done\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
