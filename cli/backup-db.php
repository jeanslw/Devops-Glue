<?php
/**
 * 全库数据备份 CLI（随镜像发布，由 supervisord 的 db-backup 循环调度）
 *
 * 启用 CD（共享库存在 cd_* 表）时产出 app / cd 两份 zip，未启用 CD 时只产出一份。
 *
 * 用法：
 *   php cli/backup-db.php           受 DB_BACKUP_ENABLED 门控（默认跳过并退出 0）
 *   php cli/backup-db.php --force   忽略门控强制执行一次
 *
 * 各自滚动保留最新 10 份。
 * 退出码：0 = 完成或按开关跳过；1 = 致命错误（库连不上 / 写盘失败）。
 */
require_once __DIR__ . '/backup-lib.php';

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
    $warning = $service->lastWarningKey() !== ''
        ? (new \App\Service\I18nService(dirname(__DIR__) . '/lang'))->trans($service->lastWarningKey(), $service->lastWarningParams())
        : '';

    if ($warning !== '') {
        echo date('Y-m-d H:i:s') . " ⚠ 备份权限告警: {$warning}\n";
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
