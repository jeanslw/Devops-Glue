<?php
/**
 * 离线清理 ci_pipeline_artifacts 过期 tag CLI（运维工具，不随 Web 运行）
 *
 * 用法：
 *   php cli/cleanup-pipeline-tags.php
 *
 * 说明：
 * 调度：用宿主 cron / 容器 crontab / Windows 计划任务定时调用即可，与 CD 完全解耦。
 *   例如每小时：0 * * * * php /path/to/cli/cleanup-pipeline-tags.php >> /data/logs/tag-cleanup.log 2>&1
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

// ── 1. 初始化数据库（Database::getPdo() 内部 createPdo()+bootstrap()，建表+种子+自检，只跑一次）──
try {
    $pdo = Database::getPdo();
} catch (\Throwable $e) {
    fwrite(STDERR, "错误：数据库初始化失败: {$e->getMessage()}\n");
    exit(1);
}

// ── 3.5 开关检查：后台清理过期 tag 未开启则直接跳过（默认关闭，删除不可逆）──
try {
    $cleanupRow = $pdo->query("SELECT value FROM " . AppConfig::TABLE_APP_SETTINGS . " WHERE setting_key = 'stale_tag_cleanup_enabled'")->fetch();
    $cleanupEnabled = $cleanupRow && $cleanupRow['value'] === '1';
} catch (\Throwable $e) {
    $cleanupEnabled = false; // 表缺失/查询异常 → 视为关闭，安全跳过
}
if (!$cleanupEnabled) {
    echo "跳过：后台「清理过期 tag」开关未开启（stale_tag_cleanup_enabled=0）。\n";
    exit(0);
}

// ── 4. 构造镜像仓库提供方（未配置时传 null，服务会安全跳过；连接值从 DB 解析）──
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
            'connect_timeout' => 5,   // TCP 握手超时，防止 hang
            'timeout'         => 15,  // 整个请求超时
        ]);
        $registry = new HarborProvider(new HarborService($harborClient, $pdo, null));
    }
}

// ── 5. 执行清理 ──
if ($registry === null) {
    echo "跳过：镜像仓库未配置（平台连接 URL 为空），ci_pipeline_artifacts 未做清理。\n";
    exit(0);
}

// 分布式锁：多实例（多 worker 容器）部署时保证同一时刻只有一个实例执行清理。
// 未抢到锁说明他者正在清理，直接跳过；任务结束（成功或失败）后精确释放，
// 仅进程被 kill 等硬退出时靠 ttl 自动过期兜底。
$lockName  = 'tag-cleanup';
$lockToken = Database::tryAcquireLock($lockName, 600);
if ($lockToken === null) {
    echo "跳过：已有另一实例在执行清理（分布式锁未获取）。\n";
    exit(0);
}

// 注意：不能在 try/catch 内 exit——PHP 的 exit 会跳过 finally，导致锁不释放。
// 用标志位收集失败，finally 内统一释放，再在块外按结果退出。
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
