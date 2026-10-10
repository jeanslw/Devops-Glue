<?php
/**
 * 离线回填 ci_pipeline_artifacts 缺失 tag CLI
 *
 * 用法：
 *   php cli/backfill-pipeline-tags.php
 *
 * 说明：
 *   - 数据来源：ci_pipeline_build_log（拉取式记录「镜像 Tag」日志兜底的懒解析缓存，source='log'）。
 *   - 信任边界：仅当 Harbor 明确返回「该 tag 存在」时，才把日志推导的 tag 提升写入
 *     canonical ci_pipeline_artifacts；且只补「(provider,project_id,pipeline_iid) 尚无 tag」的坑，
 *     绝不覆盖 scanSync 的权威结果。故回填数据与 scanSync 同信任级。
 *   - 安全不变量：Harbor 未配置 / 不可达 / repository 或 tag 为空 / 已有 canonical tag → 跳过，
 *     绝不误写；只写「Harbor 明确返回了 tag 列表且其中包含这条」的记录。
 *   - 幂等：可重复跑、并发跑无害。只写 ci_pipeline_artifacts，绝不碰任何 cd_* 表。
 *   - 受后台开关 backfill_tag_enabled 控制：未开启时直接跳过（退出码 0）。
 *
 * 调度：用宿主 cron / 容器 crontab / Windows 计划任务定时调用即可，与 CD 完全解耦。
 *   例如每 30 分钟：0,30 * * * * php /path/to/cli/backfill-pipeline-tags.php >> /data/logs/tag-backfill.log 2>&1
 *
 * 退出码：0 = 完成（含「开关未开启 / Harbor 未配置 / 不可达而安全跳过」）；1 = 致命错误（库连不上/表缺失/异常）。
 */
require __DIR__ . '/bootstrap.php';

use App\Config\AppConfig;
use App\Service\Database;
use App\Service\HarborService;
use App\Service\PipelineTagService;
use GuzzleHttp\Client;

// ── 1. 初始化数据库（Database::getPdo() 内部 createPdo()+bootstrap()，建表+种子+自检，只跑一次）──
try {
    $pdo = Database::getPdo();
} catch (\Throwable $e) {
    fwrite(STDERR, "错误：数据库初始化失败: {$e->getMessage()}\n");
    exit(1);
}

// ── 3.5 开关检查：后台「镜像 Tag 日志回填」未开启则安全跳过（默认关闭）──
try {
    $backfillRow = $pdo->query("SELECT value FROM " . AppConfig::TABLE_APP_SETTINGS . " WHERE setting_key = 'backfill_tag_enabled'")->fetch();
    $backfillEnabled = $backfillRow && $backfillRow['value'] === '1';
} catch (\Throwable $e) {
    $backfillEnabled = false; // 表缺失/查询异常 → 视为关闭，安全跳过
}
if (!$backfillEnabled) {
    echo "跳过：后台「镜像 Tag 日志回填」开关未开启（backfill_tag_enabled=0）。\n";
    exit(0);
}

// ── 4. 构造 HarborService（未配置时传 null，服务会安全跳过）──
$harbor = null;
$harborUrl = rtrim(envVal('HARBOR_BASE_URL'), '/');
if ($harborUrl !== '') {
    $harborClient = new Client([
        'base_uri' => $harborUrl,
        'auth'     => [
            envVal('HARBOR_USER', 'admin'),
            envVal('HARBOR_PASSWORD'),
        ],
        'headers'         => ['Accept' => 'application/json'],
        'connect_timeout' => 5,   // TCP 握手超时，防止 hang
        'timeout'         => 15,  // 整个请求超时
    ]);
    $harbor = new HarborService($harborClient, $pdo, null);
}

// ── 5. 执行回填 ──
if ($harbor === null) {
    echo "跳过：Harbor 未配置（HARBOR_BASE_URL 为空），ci_pipeline_artifacts 未做回填。\n";
    exit(0);
}

// 分布式锁：多实例（多 worker 容器）部署时保证同一时刻只有一个实例执行回填。
// 未抢到锁说明他者正在回填，直接跳过；任务结束（成功或失败）后精确释放，
// 仅进程被 kill 等硬退出时靠 ttl 自动过期兜底。
$lockName  = 'tag-backfill';
$lockToken = Database::tryAcquireLock($lockName, 600);
if ($lockToken === null) {
    echo "跳过：已有另一实例在执行回填（分布式锁未获取）。\n";
    exit(0);
}

// 注意：不能在 try/catch 内 exit——PHP 的 exit 会跳过 finally，导致锁不释放。
// 用标志位收集失败，finally 内统一释放，再在块外按结果退出。
$failed = false;
try {
    $svc  = new PipelineTagService($pdo, $harbor);
    $stat = $svc->backfillTagsFromBuildLog();
    echo "✓ 回填完成：提升 {$stat['promoted']} 条"
        . "（核对 {$stat['checked']} / Harbor 不可达跳过 {$stat['unreachable']} / 无 repository 或 tag 跳过 {$stat['unverifiable']} / 已有 canonical tag 跳过 {$stat['skipped']}）\n";
} catch (\Throwable $e) {
    fwrite(STDERR, "错误：回填失败: {$e->getMessage()}\n");
    $failed = true;
} finally {
    Database::releaseLock($lockName, $lockToken);
}
exit($failed ? 1 : 0);
