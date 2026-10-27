<?php
/**
 * 离线清理 ci_pipeline_artifacts 过期 tag CLI
 *
 * 用法：
 *   php cli/cleanup-pipeline-tags.php
 *
 * 说明：
 * 例如每小时：0 * * * * php /path/to/cli/cleanup-pipeline-tags.php >> /data/logs/tag-cleanup.log 2>&1
 *
 * 退出码：0 = 完成（含开关未开启 / 镜像仓库未配置 / 不可达而安全跳过）；1 = 致命错误（库连不上、表缺失或异常）。
 */
require __DIR__ . '/bootstrap.php';

use App\Config\AppConfig;
use App\Service\AppSettingRepository;
use App\Service\Database;
use App\Service\HarborService;
use App\Service\PipelineTagService;
use App\Service\PlatformConnectionRepository;
use App\Service\Registry\DockerRegistryProvider;
use App\Service\Registry\HarborProvider;
use GuzzleHttp\Client;

try {
    $pdo = Database::getPdo();
} catch (\Throwable $e) {
    fwrite(STDERR, "错误：数据库初始化失败: {$e->getMessage()}\n");
    exit(1);
}

try {
    $cleanupRow = $pdo->query("SELECT value FROM " . AppConfig::TABLE_APP_SETTINGS . " WHERE setting_key = 'stale_tag_cleanup_enabled'")->fetch();
    $cleanupEnabled = $cleanupRow && $cleanupRow['value'] === '1';
} catch (\Throwable $e) {
    $cleanupEnabled = false;
}
if (!$cleanupEnabled) {
    echo "跳过：后台「清理过期 tag」开关未开启（stale_tag_cleanup_enabled=0）。\n";
    exit(0);
}

$registry = null;
$registryCfg = (new PlatformConnectionRepository($pdo))->resolveConnections()['harbor'] ?? [];
$registryUrl = rtrim((string)($registryCfg['url'] ?? ''), '/');
if ($registryUrl !== '') {
    if ((new AppSettingRepository($pdo))->getRegistryType() === 'registry') {
        $registryClient = new Client([
            'base_uri'        => $registryUrl . '/',
            'headers'         => ['Accept' => 'application/json'],
            'connect_timeout' => 5,
            'timeout'         => 15,
            'http_errors'     => false,
        ]);
        $registry = new DockerRegistryProvider(
            $registryClient,
            (string)($registryCfg['username'] ?? ''),
            (string)($registryCfg['password'] ?? ''),
            null
        );
    } else {
        $harborClient = new Client([
            'base_uri' => $registryUrl,
            'auth'     => [
                $registryCfg['username'] ?? 'admin',
                $registryCfg['password'] ?? '',
            ],
            'headers'         => ['Accept' => 'application/json'],
            'connect_timeout' => 5,
            'timeout'         => 15,
        ]);
        $registry = new HarborProvider(new HarborService($harborClient, $pdo, null));
    }
}

if ($registry === null) {
    echo "跳过：镜像仓库未配置（平台连接 URL 为空），ci_pipeline_artifacts 未做清理。\n";
    exit(0);
}

$lockName  = 'tag-cleanup';
$lockToken = Database::tryAcquireLock($lockName, 600);
if ($lockToken === null) {
    echo "跳过：已有另一实例在执行清理（分布式锁未获取）。\n";
    exit(0);
}

$failed = false;
try {
    $svc  = new PipelineTagService($pdo, $registry);
    $stat = $svc->cleanupStaleTags();
    echo "✓ 清理完成：删除 {$stat['deleted']} 条"
        . "（核对 {$stat['checked']} / 镜像仓库不可达跳过 {$stat['unreachable']} / 空 registry_repository 或 tag 跳过 {$stat['unverifiable']}）\n";
} catch (\Throwable $e) {
    fwrite(STDERR, "错误：清理失败: {$e->getMessage()}\n");
    $failed = true;
} finally {
    Database::releaseLock($lockName, $lockToken);
}
exit($failed ? 1 : 0);
