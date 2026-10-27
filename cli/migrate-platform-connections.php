<?php
/**
 * 离线迁移：把旧版 env 里的 Git/Harbor/Jenkins 连接配置迁入DB
 *
 * 用法：
 *   php cli/migrate-platform-connections.php
 *
 * 退出码：0 = 完成（含无可迁移项）；1 = 致命错误
 */
require __DIR__ . '/bootstrap.php';

use App\Service\Database;
use App\Service\PlatformConnectionRepository;
use App\Support\EnvFileLoader;

const ENV_MAP = [
    'gitlab'  => ['url' => ['GITLAB_BASE_URL', 'GIT_BASE_URL'], 'token' => ['GITLAB_TOKEN', 'GIT_TOKEN']],
    'github'  => ['url' => ['GITHUB_BASE_URL'], 'token' => ['GITHUB_TOKEN']],
    'gitee'   => ['url' => ['GITEE_BASE_URL'],  'token' => ['GITEE_TOKEN']],
    'gitea'   => ['url' => ['GITEA_BASE_URL'],  'token' => ['GITEA_TOKEN']],
    'harbor'  => ['url' => ['HARBOR_BASE_URL'], 'username' => ['HARBOR_USER'], 'password' => ['HARBOR_PASSWORD']],
    'jenkins' => ['url' => ['JENKINS_BASE_URL'], 'username' => ['JENKINS_USER'], 'token' => ['JENKINS_TOKEN']],
];

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

$repo     = new PlatformConnectionRepository($pdo);
$migrated = [];
$skipped  = [];

foreach (ENV_MAP as $platform => $cols) {
    if ($repo->find($platform) !== null) {
        $skipped[] = "{$platform}（已有 DB 行，不覆盖）";
        continue;
    }

    $data     = ['url' => '', 'token' => '', 'username' => '', 'password' => '', 'description' => ''];
    $hasValue = false;
    foreach ($cols as $dbCol => $envKeys) {
        $value = '';
        foreach ($envKeys as $envKey) {
            $candidate = EnvFileLoader::envVal($envKey);
            if (trim($candidate) !== '') {
                $value = $candidate;
                break;
            }
        }
        $data[$dbCol] = $value;
        if ($value !== '') {
            $hasValue = true;
        }
    }

    if (!$hasValue) {
        $skipped[] = "{$platform}（env 未配置）";
        continue;
    }

    $repo->save($platform, $data);
    $migrated[] = $platform;
}

try {
    $encrypted = encryptSensitiveColumns(
        $pdo,
        \App\Config\AppConfig::TABLE_PLATFORM_CONNECTIONS,
        'platform',
        ['token', 'username', 'password']
    );
} catch (\Throwable $e) {
    fwrite(STDERR, "错误：{$e->getMessage()}\n");
    exit(1);
}

echo "迁移完成：写入 " . ($migrated ? implode(', ', $migrated) : '（无）') . "，加密存量字段 {$encrypted} 个\n";
if ($skipped) {
    echo "跳过：" . implode('；', $skipped) . "\n";
}
echo "提示：确认无误后可从 config/app.env 删除这些连接键（应用此后只读 DB）。\n";
exit(0);
