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
use App\Service\AppSettingRepository;
use App\Service\OAuthClientRepository;
use App\Support\EnvFileLoader;

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

$appSettings = new AppSettingRepository($pdo);
$clients     = new OAuthClientRepository($pdo);
$migrated    = [];
$skipped     = [];

if ($appSettings->getOidcIssuer() !== null) {
    $skipped[] = 'issuer（DB 已有值，不覆盖）';
} else {
    $issuer = trim(EnvFileLoader::envVal('OIDC_ISSUER'));
    if ($issuer !== '') {
        $appSettings->setOidcIssuer($issuer);
        $migrated[] = 'issuer';
    }
}

if ($appSettings->getOidcIdTokenTtl() !== null) {
    $skipped[] = 'id_token_ttl（DB 已有值，不覆盖）';
} else {
    $ttl = (int) trim(EnvFileLoader::envVal('OIDC_ID_TOKEN_TTL'));
    if ($ttl > 0) {
        $appSettings->setOidcIdTokenTtl($ttl);
        $migrated[] = 'id_token_ttl';
    }
}

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
