<?php
/**
 * 离线迁移：把旧版 env 里的 OIDC 登录配置（issuer / id_token_ttl / 客户端白名单）一次性搬进 DB。
 *
 * 用法：
 *   php cli/migrate-oidc.php
 *
 * 说明：
 *   - 只在从旧版（OIDC 登录配置写 env）升级到 DB 版时手动跑一次。
 *   - 幂等 + 非破坏：DB 已有值 / 已有行的项不覆盖，只补「env 有值、DB 无值/无行」的项；不删除任何行。
 *   - 客户端只有 secret 与 redirect_uri 齐备才写入（fail-closed：残缺客户端会被 OAuthService 剔除）。
 *   - 私钥不在迁移范围：OIDC_RSA_PRIVATE_KEY / OIDC_KEY_FILE 仍是 env / 文件，永不搬 DB。
 *
 * 退出码：0 = 完成（含无可迁移项）；1 = 致命错误（库连不上、表缺失）。
 */
require __DIR__ . '/bootstrap.php';

use App\Service\Database;
use App\Service\AppSettingRepository;
use App\Service\OAuthClientRepository;
use App\Support\EnvFileLoader;

try {
    $pdo = Database::getPdo();
} catch (\Throwable $e) {
    fwrite(STDERR, "错误：数据库初始化失败: {$e->getMessage()}\n");
    exit(1);
}

// 提前校验加密密钥：缺 key 时在任何写入前即报错，避免迁移半途失败。
try {
    (new \App\Service\Encryptor())->rawKey();
} catch (\Throwable $e) {
    fwrite(STDERR, "错误：{$e->getMessage()}\n");
    exit(1);
}

$appSettings = new AppSettingRepository($pdo);
$clients     = new OAuthClientRepository($pdo);
$migrated    = [];
$skipped     = [];

// issuer：env OIDC_ISSUER → ci_app_settings（DB 已设置则不覆盖）
if ($appSettings->getOidcIssuer() !== null) {
    $skipped[] = 'issuer（DB 已有值，不覆盖）';
} else {
    $issuer = trim(EnvFileLoader::envVal('OIDC_ISSUER'));
    if ($issuer !== '') {
        $appSettings->setOidcIssuer($issuer);
        $migrated[] = 'issuer';
    }
}

// id_token_ttl：env OIDC_ID_TOKEN_TTL → ci_app_settings（DB 已设置则不覆盖）
if ($appSettings->getOidcIdTokenTtl() !== null) {
    $skipped[] = 'id_token_ttl（DB 已有值，不覆盖）';
} else {
    $ttl = (int) trim(EnvFileLoader::envVal('OIDC_ID_TOKEN_TTL'));
    if ($ttl > 0) {
        $appSettings->setOidcIdTokenTtl($ttl);
        $migrated[] = 'id_token_ttl';
    }
}

// 客户端白名单：env → ci_oauth_clients（DB 已有行则不覆盖；secret+redirect_uri 齐备才写）
$clientEnv = [
    'jenkins' => ['secret' => ['JENKINS_OIDC_SECRET'], 'redirect_uri' => ['JENKINS_OIDC_REDIRECT_URI']],
    'cd'      => ['secret' => ['CD_OIDC_SECRET'], 'redirect_uri' => ['CD_OIDC_REDIRECT_URI'], 'system' => ['CD_OIDC_SYSTEM']],
];
foreach ($clientEnv as $clientId => $cols) {
    if ($clients->find($clientId) !== null) {
        $skipped[] = "client:{$clientId}（DB 已有行，不覆盖）";
        continue;
    }
    $data     = ['secret' => '', 'redirect_uri' => '', 'system' => '', 'enabled' => true, 'description' => ''];
    $hasValue = false;
    foreach ($cols as $field => $envKeys) {
        $value = '';
        foreach ($envKeys as $envKey) {
            $candidate = trim(EnvFileLoader::envVal($envKey));
            if ($candidate !== '') {
                $value = $candidate;
                break;
            }
        }
        $data[$field] = $value;
        if ($value !== '') {
            $hasValue = true;
        }
    }
    if ($data['secret'] === '' || $data['redirect_uri'] === '') {
        if ($hasValue) {
            $skipped[] = "client:{$clientId}（secret/redirect_uri 不齐，未写入）";
        }
        continue;
    }
    $clients->save($clientId, $data);
    $migrated[] = 'client:' . $clientId;
}

// 迁移后顺带把存量明文 secret 加密（迁移写入的新行已由仓储 save 自动加密，此处处理存量明文）。
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
