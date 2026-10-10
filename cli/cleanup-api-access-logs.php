<?php
/**
 * 离线清理过期审计日志 CLI（运维工具，不随 Web 运行）
 *
 * 统一清理策略（后台「系统设置 → 平台管理 → 日志设置」控制）：
 *   - ci_api_access_logs（API 调用日志）与 ci_operation_logs（操作日志）
 *     共用同一保留天数与清理开关。
 *   - 部署日志（v_glue_deploy_logs，数据归 CD 系统所有）暂不由本脚本清理。
 *
 * 用法：
 *   php cli/cleanup-api-access-logs.php [保留天数]
 *
 * 行为：
 *   - 开关关闭时打印跳过并以 0 退出，不做任何删除（与 tag-cleanup 不变量一致）。
 *   - 保留天数优先级：argv[1]（手动覆盖）> 后台 DB 设置 > 环境变量
 *     API_ACCESS_LOG_RETAIN_DAYS > 默认 90 天。
 *   - 只删除 created_at 早于保留期的行，幂等、可重复跑；不触碰任何其他表。
 *   - 表缺失/DB 不可达等异常由仓储静默吞掉（返回 0），本脚本仍以 0 退出，
 *     避免 cron 在首次部署建表竞态期刷错误告警。
 *
 * 调度：宿主 cron / 容器 crontab / Windows 计划任务，建议每天一次，例如：
 *   30 3 * * * php /path/to/cli/cleanup-api-access-logs.php >> /data/logs/api-log-cleanup.log 2>&1
 *
 * 退出码：0 = 完成（含「开关关闭跳过」「没有可删的行」）；1 = 数据库初始化失败。
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

// 分布式锁：多实例（多 worker 容器）部署时保证同一时刻只有一个实例执行清理。
// 未抢到锁说明他者正在清理，直接跳过；任务结束（成功或失败）后精确释放，
// 仅进程被 kill 等硬退出时靠 ttl 自动过期兜底。
$lockName  = 'api-log-cleanup';
$lockToken = Database::tryAcquireLock($lockName, 600);
if ($lockToken === null) {
    echo "跳过：已有另一实例在执行清理（分布式锁未获取）。\n";
    exit(0);
}

// 注意：不能在 try/catch 内 exit——PHP 的 exit 会跳过 finally，导致锁不释放。
// 用标志位收集失败，finally 内统一释放，再在块外按结果退出。
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
