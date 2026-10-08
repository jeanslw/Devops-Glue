<?php
/**
 * 全库数据备份 CLI（随镜像发布，由 supervisord 的 db-backup 循环调度）
 *
 * 与 bin/backup.php 的区别：
 *   - bin/backup.php 是本地补丁工具（bin/ 被 .dockerignore 排除，不进镜像），
 *     需要手工选 --app / --cd / --all；
 *   - 本脚本在容器内跑，一次产出**两份独立备份**，各自可独立恢复、互不牵连：
 *       db_backup_ci_*.sql = CI 应用表（mode=app）
 *       db_backup_cd_*.sql = CD 系统表（mode=cd）
 *     恢复 CI 时不会误灌 CD 表，恢复 CD 时也不会动 CI 表 —— 各回各家。
 *
 * 用法：
 *   php cli/backup-db.php           受 DB_BACKUP_ENABLED 门控（默认关：打印跳过并退出 0）
 *   php cli/backup-db.php --force   忽略门控强制执行一次（手工备份用）
 *
 * 行为：
 *   - 应用表 = AppConfig 全部 TABLE_* 常量；cd 表 = cd_ 前缀。两者之外的表
 *     （既非应用表又非 cd 表）不属于任何一侧，打印警告、两份备份均不含。
 *   - 仍无条件排除 cache（会话 token 是易失凭证，落盘 = 第二凭证库）与
 *     ci_platform_versions（Git API 派生缓存，恢复后自动重拉）——由 backupWriteFile 兜底。
 *   - 原子落盘（tmp + rename）：失败不会留下半截备份。
 *   - 各自滚动保留最新 10 份。
 *   - 退出码：0 = 完成或按开关跳过；1 = 致命错误（库连不上 / 写盘失败）。
 *
 * 恢复：
 *   php bin/restore.php <db_backup_ci_*.sql> --yes        恢复应用表
 *   php bin/restore.php <db_backup_cd_*.sql> --yes --cd   恢复 cd 表
 * （旧的单文件 db_backup_*.sql 全库备份已废弃，若残留请手工清理。）
 */
require_once __DIR__ . '/backup-lib.php';

backupLoadEnv();

$force = in_array('--force', $_SERVER['argv'] ?? [], true);

if (!$force && !in_array(envVal('DB_BACKUP_ENABLED', '0'), ['1', 'true', 'on', 'yes'], true)) {
    echo date('Y-m-d H:i:s') . " - 备份：未启用（DB_BACKUP_ENABLED 未设为 1），跳过\n";
    exit(0);
}

[$driver, $pdo] = backupConnect();

try {
    $liveTables = backupListTables($pdo, $driver);
    if (empty($liveTables)) {
        fwrite(STDERR, "错误：目标库没有任何表（未初始化？），已中止，不生成空壳备份。\n");
        exit(1);
    }

    // 应用表（AppConfig TABLE_* 常量；含 cache/ci_platform_versions，由 backupWriteFile 兜底排除）
    $appTables = array_values(array_intersect($liveTables, backupAppTables()));
    // cd 表（cd_ 前缀，与 Glue 共享库的 CD 系统）
    $cdTables  = array_values(array_filter($liveTables, 'backupIsForeignTable'));

    // 既非应用表又非 cd 表：不归任何一侧，打印警告避免静默漏备
    $unknown = array_values(array_diff($liveTables, array_merge($appTables, $cdTables)));
    if (!empty($unknown)) {
        fwrite(STDERR, "警告：以下表既非应用表也非 cd 表，两份备份均不包含："
            . implode(', ', $unknown) . "\n");
    }

    $ts = date('Ymd_His');

    // 1) CI 应用表备份（mode=app）
    if (empty($appTables)) {
        fwrite(STDERR, "错误：库中没有应用表（AppConfig TABLE_* 常量无交集），已中止。\n");
        exit(1);
    }
    $ciFile = BACKUP_DIR . '/db_backup_ci_' . $ts . '.sql';
    [$ciFile, $ciCounts] = backupWriteFile($pdo, $driver, $ciFile, $appTables, 'app');
    backupRotate('db_backup_ci', 10);

    // 2) CD 表备份（mode=cd，仅在存在 cd 表时产出）
    $cdFile   = '';
    $cdCounts = [];
    if (!empty($cdTables)) {
        $cdFile = BACKUP_DIR . '/db_backup_cd_' . $ts . '.sql';
        [$cdFile, $cdCounts] = backupWriteFile($pdo, $driver, $cdFile, $cdTables, 'cd');
        backupRotate('db_backup_cd', 10);
    }

    $ciTotal = array_sum($ciCounts);
    echo date('Y-m-d H:i:s') . " ✓ 备份完成（CI / CD 分离）\n";
    echo "  [CI] {$ciFile} (mode=app | 表数: " . count($ciCounts) . " | 总行数: {$ciTotal})\n";
    foreach ($ciCounts as $t => $c) {
        echo "      {$t}: {$c} 行\n";
    }
    if (!empty($cdTables)) {
        $cdTotal = array_sum($cdCounts);
        echo "  [CD] {$cdFile} (mode=cd | 表数: " . count($cdCounts) . " | 总行数: {$cdTotal})\n";
        foreach ($cdCounts as $t => $c) {
            echo "      {$t}: {$c} 行\n";
        }
    } else {
        echo "  [CD] 库中无 cd_* 表，跳过（未启用 CD 共享库）\n";
    }
} catch (\Throwable $e) {
    fwrite(STDERR, "错误：备份失败: {$e->getMessage()}\n");
    exit(1);
}
exit(0);
