<?php
/**
 * 备份/恢复 CLI 共用库（供 cli/backup-db.php、bin/backup.php、bin/restore.php require_once）
 *
 * 随镜像发布（cli/ 在镜像内）；bin/ 下的离线工具是本地补丁，require 本文件。
 * 约定：
 *   - 备份 = 纯数据 .sql（INSERT，不含 DDL）。DDL 由恢复方按目标驱动
 *     Database::bootstrap() 生成，这是「MySQL 备份可恢复进 SQLite、反之亦然」的前提。
 *   - 转义采用 SQL 标准写法（'' 加倍，反斜杠保持字面量），配合文件头
 *     mysqldump 同款 /*!40101 SET SQL_MODE='NO_BACKSLASH_ESCAPES' *​/ 注释，
 *     MySQL 服务端解析时关闭反斜杠转义，SQLite 视其为普通注释——同一份文件两种引擎都能吃。
 *   - 排除 cache（会话 token 是易失凭证，不该在备份里形成第二凭证库）与
 *     ci_platform_versions（Git API 派生缓存，恢复后自动重拉）。
 */
require_once __DIR__ . '/../vendor/autoload.php';

use App\Config\AppConfig;
use App\Service\Database;

// 必须先加载环境再读 BACKUP_DIR：常量在 include 时求值一次，
// 若等到调用方 require 之后再 backupLoadEnv()，config/app.env 里配的 BACKUP_DIR 会被静默忽略
// （只有 Docker ENV / shell export 这类真实环境变量才生效），裸机部署改了 app.env 却不生效。
backupLoadEnv();

define('BACKUP_DIR', rtrim((string) envVal('BACKUP_DIR', ''), '/') ?: __DIR__ . '/../backups');

/**
 * 备份排除表
 *
 * @return list<string>
 */
function backupExcludedTables(): array
{
    return [AppConfig::TABLE_CACHE, AppConfig::TABLE_PLATFORM_VERSIONS];
}

/**
 * 应用自有表清单（AppConfig 全部 TABLE_ 常量，反射读取，未来加表自动跟随）。
 * 恢复只动这些表；清单之外的表一律不碰。
 *
 * @return list<string>
 */
function backupAppTables(): array
{
    $ref    = new \ReflectionClass(AppConfig::class);
    $tables = [];
    foreach ($ref->getConstants() as $name => $value) {
        if (str_starts_with($name, 'TABLE_') && is_string($value)) {
            $tables[] = $value;
        }
    }
    sort($tables);
    if (count($tables) < 10) {
        throw new \RuntimeException('backupAppTables: 反射得到的应用表数量异常: ' . count($tables));
    }
    return $tables;
}

/**
 * cd 系统表判定：cd 与 Devops_Glue 共享同一个库，其表以 cd_ 前缀命名
 * （cd_alert_rules / cd_deploy_logs / cd_servers 等）。
 * 备份全量归档；恢复时跳过——既不删除也不重灌，cd 系统的现场数据保持原样。
 */
function backupIsForeignTable(string $table): bool
{
    return str_starts_with($table, 'cd_');
}

/**
 * 读取配置：优先 $_ENV（phpdotenv 填充），其次 getenv()。
 * 避免 variables_order 不含 E 时 shell 传入的环境变量丢失。
 */
function envVal(string $key, string $default = ''): string
{
    return \App\Support\EnvFileLoader::envVal($key, $default);
}

/** 三层 app.env 加载（优先级 app.env.local > 真实环境变量 > app.env.{APP_ENV} > app.env） */
function backupLoadEnv(): void
{
    \App\Support\EnvFileLoader::load(__DIR__ . '/../config');
}

/**
 * 连接数据库，返回 [driver, pdo]。
 * 共用 Database::createPdo()：统一 DSN 构造与 PDO 属性，消除手写 DSN 漂移。
 *
 * @return array{0:string, 1:\PDO}
 */
function backupConnect(): array
{
    Database::init(); // 读取完整 DB 配置（driver 非法时 defaultConfig 会抛异常）
    try {
        $pdo = Database::createPdo();
    } catch (\Throwable $e) {
        fwrite(STDERR, "错误：数据库连接失败（driver=" . Database::driver() . "）: {$e->getMessage()}\n");
        exit(1);
    }
    return [Database::driver(), $pdo];
}

/**
 * 枚举全部用户表。
 * 表名只接受 [a-zA-Z0-9_]，防止异常表名拼进 SQL。
 *
 * MySQL 走 information_schema 且限定 table_type='BASE TABLE'：
 *   - 只取基础表，视图（如 v_glue_deploy_logs）不会被当成表导出（SHOW TABLES 会带上视图，
 *     导出视图会生成一条对视图执行 SELECT * 的假 INSERT，恢复时必然失败）
 *   - 顺带能看到共享库里 cd 系统的表，与是否启用 CD 无关
 * SQLite 的 type='table' 本就排除视图。
 *
 * @return list<string>
 */
function backupListTables(\PDO $pdo, string $driver): array
{
    if ($driver === 'mysql') {
        $names = $pdo->query(
            "SELECT table_name FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'
             ORDER BY table_name"
        )->fetchAll(\PDO::FETCH_COLUMN);
    } else {
        $names = $pdo->query(
            "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
        )->fetchAll(\PDO::FETCH_COLUMN);
    }
    $tables = [];
    foreach ($names as $t) {
        if (is_string($t) && preg_match('/^[a-zA-Z0-9_]+$/', $t)) {
            $tables[] = $t;
        }
    }
    sort($tables);
    return $tables;
}

/**
 * 表的列清单（按物理顺序），列名同样只接受安全字符
 *
 * @return list<string>
 */
function backupTableColumns(\PDO $pdo, string $driver, string $table): array
{
    if ($driver === 'mysql') {
        $cols = $pdo->query('SHOW COLUMNS FROM ' . $table)->fetchAll(\PDO::FETCH_COLUMN, 0);
    } else {
        $rows = [];
        foreach ($pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $rows[$r['cid']] = $r['name'];
        }
        ksort($rows);
        $cols = array_values($rows);
    }
    foreach ($cols as $c) {
        if (!is_string($c) || !preg_match('/^[a-zA-Z0-9_]+$/', $c)) {
            throw new \RuntimeException("表 {$table} 存在异常的列名: " . var_export($c, true));
        }
    }
    return array_values($cols);
}

/**
 * SQL 标准值转义：'' 加倍、NULL 保持 NULL、反斜杠按字面量保留。
 * 配合恢复时的 NO_BACKSLASH_ESCAPES，MySQL/SQLite 通用，不会出现
 * 「MySQL 备份里的 \' 被 SQLite 当成字面量反斜杠」这类跨驱动污染。
 *
 * @param scalar|null $value
 */
function backupEscapeValue($value): string
{
    if ($value === null) {
        return 'NULL';
    }
    if (is_int($value) || is_float($value)) {
        return (string)$value;
    }
    return "'" . str_replace("'", "''", (string)$value) . "'";
}

/**
 * 导出纯数据备份到指定文件。
 *
 * 一致性：MySQL REPEATABLE READ 事务快照；SQLite 读事务（WAL 下写不阻塞读）。
 * 原子性：先写临时文件再 rename，失败不会留下半截备份（半截备份恢复=灾难）。
 *
 * @param list<string> $onlyTables 只导出这些表；空数组 = 全部表。
 *                          排除表（cache/ci_platform_versions）无论是否传入都会被跳过——
 *                          调用方传进来的清单里若含排除表，是调用方的疏漏，这里兜底拦截。
 * @param string $mode      app|cd|full，写入 META，恢复时按模式校验（防止两种备份互相误用）
 * @return array{0: string, 1: array<string,int>} [文件路径, 各表行数]
 */
function backupWriteFile(\PDO $pdo, string $driver, string $targetFile, array $onlyTables = [], string $mode = 'app'): array
{
    $excluded = backupExcludedTables();

    if ($driver === 'mysql') {
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    }
    $pdo->beginTransaction();
    $counts = [];
    $body   = '';
    try {
        $tables = $onlyTables ?: backupListTables($pdo, $driver);
        foreach ($tables as $t) {
            // 排除表无条件跳过：cache（会话 token 是易失凭证，落盘=第二凭证库）与
            // ci_platform_versions（派生缓存，恢复后自动重拉）绝不能进备份文件。
            // 这里不能加 !$onlyTables 守卫：双模式改造后调用方总是传入 onlyTables，
            // 守卫会把排除架空（历史事故：cache 40 行 token 漏进备份文件）。
            if (in_array($t, $excluded, true)) {
                continue;
            }
            $cols = backupTableColumns($pdo, $driver, $t);
            if (empty($cols)) {
                throw new \RuntimeException("表 {$t} 无法读取列定义，备份中止（宁可失败也不给不完整备份）");
            }
            $rows = $pdo->query('SELECT * FROM ' . $t)->fetchAll(\PDO::FETCH_NUM);
            $counts[$t] = count($rows);
            if (empty($rows)) {
                continue; // 空表不写 INSERT（恢复方按 META 里的 0 行校验）
            }
            $head   = 'INSERT INTO ' . $t . ' (' . implode(', ', $cols) . ') VALUES';
            $values = [];
            foreach ($rows as $row) {
                $values[] = '(' . implode(', ', array_map('backupEscapeValue', $row)) . ')';
                if (count($values) >= 200) {
                    $body .= $head . "\n" . implode(",\n", $values) . ";\n";
                    $values = [];
                }
            }
            if (!empty($values)) {
                $body .= $head . "\n" . implode(",\n", $values) . ";\n";
            }
        }
    } finally {
        // 读事务尽快提交，不占用锁
        $pdo->commit();
    }

    $metaTables = [];
    foreach ($counts as $t => $c) {
        $metaTables[] = "{$t}({$c})";
    }
    $header = "-- Devops-Glue 数据备份（备份 CLI 导出）\n"
        . '-- META: exported_at=' . date('Y-m-d H:i:s') . "\n"
        . "-- META: source_driver={$driver}\n"
        . '-- META: app_version=' . AppConfig::APP_VERSION . "\n"
        . "-- META: mode={$mode}\n"
        . '-- META: tables=' . implode(',', $metaTables) . "\n"
        . '-- META: excluded=' . implode(',', $excluded) . "\n"
        // mysqldump 同款版本注释：MySQL 服务端执行（关闭反斜杠转义 + 外键检查），
        // SQLite 视为普通注释无副作用 —— 本文件也能用 mysql 原生 CLI 安全恢复。
        . "/*!40101 SET SQL_MODE='NO_BACKSLASH_ESCAPES' */;\n"
        . "/*!40014 SET FOREIGN_KEY_CHECKS=0 */;\n\n";

    if (!is_dir(BACKUP_DIR)) {
        @mkdir(BACKUP_DIR, 0777, true);
    }
    $tmpFile = $targetFile . '.tmp';
    if (file_put_contents($tmpFile, $header . $body) === false) {
        @unlink($tmpFile);
        throw new \RuntimeException("写入备份文件失败: {$targetFile}");
    }
    if (!rename($tmpFile, $targetFile)) {
        @unlink($tmpFile);
        throw new \RuntimeException("备份文件落盘失败: {$targetFile}");
    }
    return [$targetFile, $counts];
}

/**
 * 滚动清理：每个前缀只保留最新 $keep 份（backup_ 与 pre_restore_ 各自独立计数，
 * 避免恢复前快照挤掉日常备份）。
 */
function backupRotate(string $prefix, int $keep = 10): void
{
    $files = glob(BACKUP_DIR . '/' . $prefix . '_*.sql') ?: [];
    if (count($files) <= $keep) {
        return;
    }
    usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
    foreach (array_slice($files, $keep) as $old) {
        @unlink($old);
    }
}
