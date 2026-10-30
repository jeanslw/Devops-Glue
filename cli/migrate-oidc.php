<?php
/**
 * 离线迁移：把旧版 env 里的 OIDC 登录配置，迁入DB。
 *
 * 用法：
 *   php cli/migrate-oidc.php
 *
 * 退出码：0 = 完成（含无可迁移项）；1 = 致命错误（库连不上、表缺失）。
 */
require __DIR__ . '/bootstrap.php';

use App\Service\Database;
use App\Service\OidcConfigImporter;

try {
    $pdo = Database::getPdo();
} catch (\Throwable $e) {
    fwrite(STDERR, "错误：数据库初始化失败: {$e->getMessage()}\n");
    exit(1);
}

try {
    (new \App\Service\Encryptor())->rawKey();
} catch (\Throwable $e) {
    fwrite(STDERR, "错误：{$e->getMessage()}\n");
    exit(1);
}

// 导入逻辑与引导期「空表自动播种」共用一份实现（OidcConfigImporter），避免两处漂移。
$result   = (new OidcConfigImporter($pdo))->importFromEnv();
$migrated = $result['migrated'];
$skipped  = $result['skipped'];

try {
    $encrypted = encryptSensitiveColumns(
        $pdo,
        \App\Config\AppConfig::TABLE_OAUTH_CLIENTS,
        'client_id',
        ['secret']
    );
} catch (\Throwable $e) {
    fwrite(STDERR, "错误：{$e->getMessage()}\n");
    exit(1);
}

echo "迁移完成：写入 " . ($migrated ? implode(', ', $migrated) : '（无）') . "，加密存量字段 {$encrypted} 个\n";
if ($skipped) {
    echo "跳过：" . implode('；', $skipped) . "\n";
}
echo "提示：私钥仍在 env / 文件（OIDC_RSA_PRIVATE_KEY / OIDC_KEY_FILE），不在本次迁移范围。\n";
exit(0);
