<?php

/**
 * 存量时间字段时区迁移：把旧版本以「本地时」写入的 DATETIME/TEXT 时间列
 * 统一换算为 UTC，与 v2.11.4 之后「数据库统一存 UTC + 前端按 display_timezone 渲染」对齐。
 *
 * ⚠️ 重要：
 *   1. 必须在「升级到写入 UTC 的新版本之前」运行。若库里已混入新代码写入的 UTC 值，
 *      重复/错误运行会把正确的 UTC 值再减一次偏移 → 数据损坏。
 *   2. 默认 DRY-RUN（只统计、不写）。加 --apply 才真正写入，并打幂等标记。
 *   3. 幂等：ci_app_settings 写入 timezone_data_migrated 标记；已迁移且无 --force 则跳过。
 *   4. 运行前请务必备份数据库（mysqldump / 复制 data.db）。
 *   5. 旧数据若不是 Asia/Shanghai 写入的，请用 --from-tz 指定实际源时区。
 *
 * 用法：
 *   php bin/migrate-timezone.php                      # 默认 from=Asia/Shanghai to=UTC，仅统计
 *   php bin/migrate-timezone.php --apply              # 真正写入
 *   php bin/migrate-timezone.php --from-tz=America/New_York --apply
 *   php bin/migrate-timezone.php --force --apply      # 忽略幂等标记强行重跑
 */

require __DIR__ . '/../cli/bootstrap.php';

use App\Service\Database;

// ── 参数解析 ──
$opts = [];
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--')) {
        [$k, $v] = array_pad(explode('=', substr($a, 2), 2), 2, true);
        $opts[$k] = $v;
    }
}
$fromTz = (string) ($opts['from-tz'] ?? 'Asia/Shanghai');
$toTz   = (string) ($opts['to-tz']   ?? 'UTC');
$apply  = isset($opts['apply']);
$force  = isset($opts['force']);

// ── 时间列白名单：表 => [主键, [需换算的列...]] ──
// 仅含 DATETIME/TEXT 类型的时间列；INT 时间戳（expires_at）已排除。
// 主键用于定位行；复合主键表（role_permissions/implied_rules）无时间列，未列入。
$TABLES = [
    'ci_pipeline_artifacts'   => ['id',         ['source_updated_at', 'created_at', 'updated_at']],
    'ci_custom_builds'        => ['id',         ['triggered_at', 'started_at', 'finished_at']],
    'ci_app_settings'         => ['setting_key', ['updated_at']],
    'ci_admin_users'          => ['id',         ['created_at', 'updated_at']],
    'ci_user_identities'      => ['id',         ['bound_at', 'updated_at']],
    'ci_security_checks'      => ['id',         ['created_at']],
    'ci_operation_logs'       => ['id',         ['created_at']],
    'ci_api_access_logs'      => ['id',         ['created_at']],
    'ci_roles'                => ['id',         ['created_at']],
    'ci_permissions'          => ['perm_key',   ['created_at']],
    'ci_api_tokens'           => ['id',         ['created_at']],
    'ci_build_tag_cache'      => ['id',         ['created_at', 'updated_at']],
    'ci_integrated_services'  => ['id',         ['created_at', 'updated_at']],
    'ci_oauth_clients'        => ['client_id',  ['created_at', 'updated_at']],
    'ci_platform_connections' => ['platform',   ['created_at', 'updated_at']],
];

try {
    $pdo = Database::getPdo();
} catch (\Throwable $e) {
    fwrite(STDERR, "错误：数据库初始化失败: {$e->getMessage()}\n");
    exit(1);
}

$markerKey = 'timezone_data_migrated';
$marker = Database::readAppSetting($pdo, $markerKey);
if ($marker !== null && !$force) {
    echo gmdate('Y-m-d H:i:s') . " - 已迁移过（marker={$marker}），跳过。如需重跑请加 --force\n";
    exit(0);
}

try {
    new \DateTimeZone($fromTz);
    new \DateTimeZone($toTz);
} catch (\Throwable $e) {
    fwrite(STDERR, "错误：非法时区定义: {$e->getMessage()}\n");
    exit(1);
}

echo gmdate('Y-m-d H:i:s') . " - 模式: " . ($apply ? 'APPLY（将写入）' : 'DRY-RUN（仅统计）')
    . "  from={$fromTz} -> to={$toTz}\n";

$total = 0;
$pdo->beginTransaction();
try {
    foreach ($TABLES as $table => [$pk, $cols]) {
        $existing = array_values(array_intersect($cols, tableColumns($pdo, $table)));
        if ($existing === []) {
            echo "  [跳过] {$table}: 无时间列（可能旧库尚未补列）\n";
            continue;
        }
        $colList = implode(', ', array_merge([$pk], $existing));
        $rows = $pdo->query("SELECT {$colList} FROM {$table}")->fetchAll(\PDO::FETCH_ASSOC);
        $count = 0;
        foreach ($rows as $row) {
            $id = $row[$pk] ?? null;
            if ($id === null || $id === '') {
                continue;
            }
            $sets = [];
            $params = [];
            foreach ($existing as $col) {
                $v = $row[$col] ?? '';
                if ($v === '') {
                    continue;
                }
                $v = (string) $v;
                // 严格格式校验：仅接受 Y-m-d H:i:s（可选毫秒）。避免 createFromFormat 对
                // 零日期在某些 PHP 版本返回对象+警告（非 false）而被换算成非法年份写回造成
                // 数据损坏；也避免带毫秒的值被 createFromFormat 判为不可解析而直接跳过（漏迁移）。
                $matched = preg_match('/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2}):(\d{2})(\.\d+)?$/', $v, $m) === 1;
                if (!$matched) {
                    fwrite(STDERR, "  警告：{$table}.{$col}={$v} 时间格式异常，跳过该行此列\n");
                    continue;
                }
                $clean = "{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:{$m[5]}:{$m[6]}";
                // 零日期（含 0000 年或 00 月/日）无实际意义，按 NULL 处理，避免换算成非法年份写回
                if ($m[1] === '0000' || $m[2] === '00' || $m[3] === '00') {
                    $sets[] = "{$col} = ?";
                    $params[] = null;
                    continue;
                }
                $dt = \DateTime::createFromFormat('Y-m-d H:i:s', $clean, new \DateTimeZone($fromTz));
                $dtErrors = \DateTime::getLastErrors();
                if (!is_array($dtErrors)) {
                    $dtErrors = ['error_count' => 0, 'warning_count' => 0];
                }
                if ($dt === false || $dtErrors['warning_count'] > 0 || $dtErrors['error_count'] > 0) {
                    fwrite(STDERR, "  警告：{$table}.{$col}={$v} 无法按 {$fromTz} 安全解析，跳过该行此列\n");
                    continue;
                }
                $dt->setTimezone(new \DateTimeZone($toTz));
                $sets[] = "{$col} = ?";
                $params[] = $dt->format('Y-m-d H:i:s');
            }
            if ($sets === []) {
                continue;
            }
            $params[] = $id;
            if ($apply) {
                $pdo->prepare("UPDATE {$table} SET " . implode(', ', $sets) . " WHERE {$pk} = ?")
                    ->execute($params);
            }
            $count++;
        }
        echo "  {$table}: " . ($apply ? '已更新' : '将更新') . " {$count} 行（检查 " . count($rows) . " 行）\n";
        $total += $count;
    }

    if ($apply) {
        Database::writeAppSetting($pdo, $markerKey, "{$fromTz}->{$toTz}");
        $pdo->commit();
        echo gmdate('Y-m-d H:i:s') . " - 完成：共 {$total} 行时间字段已换算为 {$toTz}（已写入幂等标记）\n";
    } else {
        $pdo->rollBack();
        echo gmdate('Y-m-d H:i:s') . " - DRY-RUN 完成：预计 {$total} 行将被换算。确认无误后加 --apply 写入。\n";
    }
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "错误：迁移失败: {$e->getMessage()}\n");
    exit(1);
}

/**
 * 取表实际存在的列名（兼容增量补列前的旧库，避免 SELECT 不存在的列报错）。
 * 表名/列名均来自上方硬编码白名单，非用户输入，直接拼接安全。
 *
 * @return string[]
 */
function tableColumns(\PDO $pdo, string $table): array
{
    $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $stmt = $pdo->query("PRAGMA table_info({$table})");
        $out = [];
        foreach ($stmt as $r) {
            $out[] = $r['name'];
        }
        return $out;
    }
    $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}`");
    $out = [];
    foreach ($stmt as $r) {
        $out[] = $r['Field'];
    }
    return $out;
}
