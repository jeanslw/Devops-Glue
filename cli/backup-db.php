<?php
/**
 * 全库数据备份 CLI（随镜像发布，由 supervisord 的 db-backup 循环调度）
 *
 * 复用 Web 侧 DataBackupService —— 与后台「手动备份」走同一套逻辑（一样的逻辑）：
 *   - 启用 CD（共享库存在 cd_* 表）时产出两份 zip：
 *       devops-glue_<driver>_<stamp>.zip = CI 应用表（mode=app）
 *       devops-cd_<driver>_<stamp>.zip  = CD 系统表（mode=cd）
 *   - 未启用 CD 时只产出一份（devops-glue_*.zip）。
 *   两份各自独立、可独立恢复，恢复 Glue 不会误把 cd 数据灌回（防止误恢复）。
 *
 * 用法：
 *   php cli/backup-db.php           受 DB_BACKUP_ENABLED 门控（默认关：打印跳过并退出 0）
 *   php cli/backup-db.php --force   忽略门控强制执行一次（手工备份用）
 *
 * 行为：
 *   - 应用表 = AppConfig 全部 TABLE_* 常量；cd 表 = cd_ 前缀。
 *   - 仍无条件排除 cache（会话 token 是易失凭证，落盘 = 第二凭证库）与
 *     ci_platform_versions（Git API 派生缓存，恢复后自动重拉）。
 *   - 各自滚动保留最新 10 份。
 *   - 退出码：0 = 完成或按开关跳过；1 = 致命错误（库连不上 / 写盘失败）。
 *
 * 恢复（bin/restore.php 已支持 zip 与 .sql 两种输入）：
 *   php bin/restore.php <devops-glue_*.zip> --yes         恢复应用表
 *   php bin/restore.php <devops-cd_*.zip> --yes --cd      恢复 cd 表
 */
require_once __DIR__ . '/backup-lib.php'; // 内含 backupLoadEnv()，保证 $_ENV['BACKUP_DIR'] 就绪

use App\Service\DataBackupService;
use App\Service\OperationLogRepository;

$force = in_array('--force', $_SERVER['argv'] ?? [], true);

if (!$force && !in_array(envVal('DB_BACKUP_ENABLED', '0'), ['1', 'true', 'on', 'yes'], true)) {
    echo date('Y-m-d H:i:s') . " - 备份：未启用（DB_BACKUP_ENABLED 未设为 1），跳过\n";
    exit(0);
}

[, $pdo] = backupConnect();

try {
    $service = new DataBackupService($pdo);
    $files   = $service->backup(10);
    $warning = $service->lastWarning();

    if ($warning !== '') {
        echo date('Y-m-d H:i:s') . " ⚠ 备份权限告警: {$warning}\n";
        // cron 无前端，落操作日志是唯一的 web 可见途径（后台「操作日志」面板可查）
        (new OperationLogRepository($pdo))->record(
            'system',
            'backup_database',
            implode(', ', array_column($files, 'name')),
            ['driver' => $service->driver(), 'files' => count($files), 'warning' => $warning],
            '',
            'success',
            'system'
        );
    }

    echo date('Y-m-d H:i:s') . " ✓ 备份完成（CI / CD 分离）\n";
    foreach ($files as $f) {
        $total = array_sum($f['counts']);
        $label = $f['mode'] === 'cd' ? 'CD' : 'CI';
        echo "  [{$label}] {$f['name']} (mode={$f['mode']} | 表数: " . count($f['counts']) . " | 总行数: {$total})\n";
        foreach ($f['counts'] as $t => $c) {
            echo "      {$t}: {$c} 行\n";
        }
    }
    if (count($files) === 1) {
        echo "  [CD] 库中无 cd_* 表，跳过（未启用 CD 共享库）\n";
    }
} catch (\Throwable $e) {
    fwrite(STDERR, "错误：备份失败: {$e->getMessage()}\n");
    exit(1);
}
exit(0);
