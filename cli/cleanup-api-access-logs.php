<?php
/**
 * 离线清理过期审计日志 CLI
 *
 * 统一清理策略（后台系统设置页平台管理的日志设置控制）：
 *   - ci_api_access_logs（API 调用日志）与 ci_operation_logs（操作日志）
 *     共用同一保留天数与清理开关。
 *   - 部署日志（数据归 CD 系统所有，CI 经 CD 只读端点拉取、不落本地库）不由本脚本清理
 *
 * 用法：
 *   php cli/cleanup-api-access-logs.php [保留天数]
 *例：
 *   30 3 * * * php /path/to/cli/cleanup-api-access-logs.php >> /data/logs/api-log-cleanup.log 2>&1
 *
 * 退出码：0 = 完成；1 = 数据库初始化失败。
 */
require __DIR__ . '/bootstrap.php';

use App\Config\AppConfig;
use App\Service\ApiAccessLogRepository;
use App\Service\AppSettingRepository;
use App\Service\Database;
use App\Service\OperationLogRepository;

try {
    $pdo = Database::getPdo();
} catch (\Throwable $e) {
    fwrite(STDERR, "错误：数据库初始化失败: {$e->getMessage()}\n");
    exit(1);
}

$settings = new AppSettingRepository($pdo);

if (!$settings->getApiAccessLogCleanupEnabled()) {
    echo date('Y-m-d H:i:s') . " - 审计日志清理：开关已关闭（后台「日志设置」），跳过\n";
    exit(0);
}

$retainDays = (int) ($argv[1] ?? 0);
if ($retainDays < 1) {
    $retainDays = $settings->hasApiAccessLogRetainDays()
        ? $settings->getApiAccessLogRetainDays()
        : (int) envVal('API_ACCESS_LOG_RETAIN_DAYS', (string) AppConfig::DEFAULT_API_ACCESS_LOG_RETAIN_DAYS);
}
if ($retainDays < 1) {
    fwrite(STDERR, "错误：保留天数必须是 >= 1 的整数（收到：{$retainDays}）\n");
    exit(1);
}

$lockName  = 'api-log-cleanup';
$lockToken = Database::tryAcquireLock($lockName, 600);
if ($lockToken === null) {
    echo "跳过：已有另一实例在执行清理（分布式锁未获取）。\n";
    exit(0);
}

$failed = false;
try {
    $deletedApi = (new ApiAccessLogRepository($pdo))->purge($retainDays);
    $deletedOps = (new OperationLogRepository($pdo))->purge($retainDays);

    echo date('Y-m-d H:i:s')
        . " ✓ 审计日志清理完成：保留 {$retainDays} 天，"
        . "删除 API 调用日志 {$deletedApi} 条、操作日志 {$deletedOps} 条\n";
} catch (\Throwable $e) {
    fwrite(STDERR, "错误：审计日志清理失败: {$e->getMessage()}\n");
    $failed = true;
} finally {
    Database::releaseLock($lockName, $lockToken);
}
exit($failed ? 1 : 0);
