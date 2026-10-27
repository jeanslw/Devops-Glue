<?php
/**
 * 离线回填 ci_pipeline_artifacts 缺失 tag CLI
 *
 * 用法：
 *   php cli/backfill-pipeline-tags.php

*   例如每 30 分钟：0,30 * * * * php /path/to/cli/backfill-pipeline-tags.php >> /data/logs/tag-backfill.log 2>&1
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
    $backfillRow = $pdo->query("SELECT value FROM " . AppConfig::TABLE_APP_SETTINGS . " WHERE setting_key = 'backfill_tag_enabled'")->fetch();
    $backfillEnabled = $backfillRow && $backfillRow['value'] === '1';
} catch (\Throwable $e) {
    $backfillEnabled = false;
}
if (!$backfillEnabled) {
    echo "跳过：后台「镜像 Tag 日志回填」开关未开启（backfill_tag_enabled=0）。\n";
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
    echo "跳过：镜像仓库未配置（平台连接 URL 为空），ci_pipeline_artifacts 未做回填。\n";
    exit(0);
}

$lockName  = 'tag-backfill';
$lockToken = Database::tryAcquireLock($lockName, 600);
if ($lockToken === null) {
    echo "跳过：已有另一实例在执行回填（分布式锁未获取）。\n";
    exit(0);
}

$failed = false;
try {
    $svc  = new PipelineTagService($pdo, $registry);
    $stat = $svc->backfillTagsFromBuildLog();
    echo "✓ 回填完成：提升 {$stat['promoted']} 条"
        . "（核对 {$stat['checked']} / 镜像仓库不可达跳过 {$stat['unreachable']} / 无 repository 或 tag 跳过 {$stat['unverifiable']} / 已有 canonical tag 跳过 {$stat['skipped']}）\n";
} catch (\Throwable $e) {
    fwrite(STDERR, "错误：回填失败: {$e->getMessage()}\n");
    $failed = true;
} finally {
    Database::releaseLock($lockName, $lockToken);
}
exit($failed ? 1 : 0);
