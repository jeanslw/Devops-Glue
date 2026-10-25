<?php

namespace App\Service;

use App\Config\AppConfig;
use PDO;

/**
 * 数据库备份服务（Web 与 cron 共用，仅备份、不提供恢复）。
 *   - 支持 sqlite / mysql 两种驱动；
 *   - 纯数据 .sql（INSERT，不含 DDL），排除易失/派生缓存表（cache、ci_platform_versions）；
 *   - 启用 CD（共享库存在 cd_* 表）时产出两份 zip：应用表 devops-glue_<driver>_<datetime>.zip、
 *     cd 系统表 devops-cd_<driver>_<datetime>.zip；未启用时只产出一份（应用表）。
 *     两份各自独立、可独立恢复，恢复 Glue 不会误把 cd 数据灌回（防止误恢复）。
 *   - 目录：Docker 内 BACKUP_DIR=/data/backups（compose 卷映射宿主 ./data/backups）；
 *     非 Docker / 未设 BACKUP_DIR 时落仓库根 backups/（与 CLI 备份 cli/backup-lib.php 一致）；
 *     目录不存在则自动创建，并滚动留存最近 $keep 份。
 */
class DataBackupService
{
    private PDO $pdo;
    private string $driver;
    /** @var array{key:string, params:array<string,string>} 最近一次 backup() 检测到的权限告警（i18n 键 + 参数，键为空串表示无告警） */
    private array $lastWarning = ['key' => '', 'params' => []];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $drv = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        if (!in_array($drv, ['sqlite', 'mysql'], true)) {
            throw new \RuntimeException("不支持的数据库驱动: {$drv}");
        }
        $this->driver = $drv;
    }

    public function driver(): string
    {
        return $this->driver;
    }

    /**
     * 最近一次 backup() 检测到的权限告警 i18n 键（空串表示无告警）。
     * 供调用方在备份后读取并翻译呈现——Web 前端 toast 提示 / cron 写入操作日志。
     */
    public function lastWarningKey(): string
    {
        return $this->lastWarning['key'];
    }

    /**
     * 权限告警 i18n 键的替换参数（键含花括号，如 {db} / {error}，后端 strtr 直接替换）。
     * 前端 __.t() 需剥掉花括号后再替换（见 system.js doBackup）。
     *
     * @return array<string,string>
     */
    public function lastWarningParams(): array
    {
        return $this->lastWarning['params'];
    }

    /**
     * 备份排除表：易失/派生缓存，绝不落进备份文件
     *
     * @return list<string>
     */
    private function excludedTables(): array
    {
        return [AppConfig::TABLE_CACHE, AppConfig::TABLE_PLATFORM_VERSIONS];
    }

    /**
     * 应用自有表清单（AppConfig 全部 TABLE_ 常量，反射读取，未来加表自动跟随）
     *
     * @return list<string>
     */
    public function appTableNames(): array
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
            throw new \RuntimeException('应用表清单异常: ' . count($tables));
        }
        return $tables;
    }

    /**
     * 枚举全部用户表（排除 sqlite 内部表）；表名只接受 [a-zA-Z0-9_]
     *
     * @return list<string>
     */
    private function listTables(): array
    {
        if ($this->driver === 'mysql') {
            $names = $this->pdo->query(
                "SELECT table_name FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'
                 ORDER BY table_name"
            )->fetchAll(PDO::FETCH_COLUMN);
        } else {
            $names = $this->pdo->query(
                "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
            )->fetchAll(PDO::FETCH_COLUMN);
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
     * 表列清单（按物理顺序），列名只接受安全字符
     *
     * @return list<string>
     */
    private function tableColumns(string $table): array
    {
        if ($this->driver === 'mysql') {
            $cols = $this->pdo->query('SHOW COLUMNS FROM ' . $table)->fetchAll(PDO::FETCH_COLUMN, 0);
        } else {
            $rows = [];
            foreach ($this->pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC) as $r) {
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
     * 配合 NO_BACKSLASH_ESCAPES 跨驱动通用。
     *
     * @param scalar|null $value
     */
    private function escapeValue($value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        return "'" . str_replace("'", "''", (string) $value) . "'";
    }

    /** 备份输出目录：优先 BACKUP_DIR 环境变量，否则仓库根 backups/（与 CLI 备份约定一致）；不存在则自动创建 */
    private function backupDir(): string
    {
        $dir = '';
        if (isset($_ENV['BACKUP_DIR']) && $_ENV['BACKUP_DIR'] !== '') {
            $dir = (string) $_ENV['BACKUP_DIR'];
        } elseif (($v = getenv('BACKUP_DIR')) !== false && $v !== '') {
            $dir = (string) $v;
        }
        if ($dir === '') {
            $dir = dirname(__DIR__, 2) . '/backups';
        }
        $dir = rtrim($dir, '/');
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        return $dir;
    }

    /**
     * 判断是否为 cd 系统表（cd_ 前缀，与 Glue 共享库的姊妹系统）。
     * 备份时单独成一份 zip，恢复时互不牵连，防止误把 cd 数据灌回 Glue。
     */
    private function isCdTable(string $table): bool
    {
        return str_starts_with($table, 'cd_');
    }

    /**
     * 备份前自检：MySQL 下确认当前账户对当前库有库级或全局权限。
     * information_schema.tables 会按权限过滤，账户对某些表（尤其 cd_*）无任何权限时，
     * 这些表会被漏掉且不报错（比报错更危险）。返回 i18n 告警键 + 参数；无风险时键为空串。
     * SQLite 无权限模型，返回空键。
     *
     * @return array{key:string, params:array<string,string>}
     */
    private function mysqlVisibilityWarning(): array
    {
        if ($this->driver !== 'mysql') {
            return ['key' => '', 'params' => []];
        }
        $db = (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn();
        try {
            $grants = $this->pdo->query('SHOW GRANTS FOR CURRENT_USER()')->fetchAll(PDO::FETCH_COLUMN);
        } catch (\Throwable $e) {
            return ['key' => 'sys.backup_warn_no_grant_info', 'params' => ['{error}' => $e->getMessage()]];
        }
        foreach ($grants as $grant) {
            $g = (string) $grant;

            if (preg_match('/^\s*GRANT\s+USAGE\s+ON/i', $g)) {
                continue;
            }
            if (stripos($g, 'ON *.*') !== false) {
                return ['key' => '', 'params' => []];
            }
            if (stripos($g, "ON `{$db}`.*") !== false || stripos($g, "ON {$db}.*") !== false) {
                return ['key' => '', 'params' => []];
            }
        }
        return ['key' => 'sys.backup_warn_no_grants', 'params' => ['{db}' => $db]];
    }

    /**
     * 执行数据库备份，生成 zip 归档。
     *
     * 启用 CD（共享库存在 cd_* 表）时产出两份：应用表 devops-glue_<driver>_<stamp>.zip、
     * cd 系统表 devops-cd_<driver>_<stamp>.zip；未启用时只产出一份（应用表）。
     *
     * 一致性：MySQL REPEATABLE READ 快照、SQLite 读事务；导出经由临时 .sql 打入 zip 后清理。
     * @param int $keep 每个前缀下最多保留的 zip 份数
     * @return list<array{name:string, mode:string, counts:array<string,int>}>
     */
    public function backup(int $keep = 10): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('zip 扩展不可用');
        }

        $this->lastWarning = $this->mysqlVisibilityWarning();

        $live      = $this->listTables();
        $appTables = array_values(array_diff(
            array_intersect($live, $this->appTableNames()),
            $this->excludedTables()
        ));
        if (empty($appTables)) {
            throw new \RuntimeException('没有任何应用表可备份（数据库未初始化？）');
        }
        $cdTables = array_values(array_filter($live, fn($t) => $this->isCdTable($t)));

        $stamp = date('Ymd_His');
        $files = [];

        $files[] = $this->exportZip('app', $appTables, 'devops-glue_' . $this->driver . '_' . $stamp);
        $this->rotatePrefix('devops-glue_' . $this->driver, $keep);

        if (!empty($cdTables)) {
            $files[] = $this->exportZip('cd', $cdTables, 'devops-cd_' . $this->driver . '_' . $stamp);
            $this->rotatePrefix('devops-cd_' . $this->driver, $keep);
        }

        return $files;
    }

    /**
     * 导出指定表集为一份 zip（纯数据 .sql 打入 zip），返回文件信息与各表行数。
     *
     * @param list<string> $tables
     * @param string $mode app|cd，写入 META，恢复时按模式校验（防止两种备份互相误用）
     * @return array{name:string, mode:string, counts:array<string,int>}
     */
    private function exportZip(string $mode, array $tables, string $base): array
    {
        $dir = $this->backupDir();


        if ($this->driver === 'mysql') {
            $this->pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }
        $this->pdo->beginTransaction();
        $counts = [];
        $body   = '';
        try {
            foreach ($tables as $t) {
                $cols = $this->tableColumns($t);
                if (empty($cols)) {
                    throw new \RuntimeException("表 {$t} 无法读取列定义，备份中止（宁可失败也不给不完整备份）");
                }
                $rows       = $this->pdo->query('SELECT * FROM ' . $t)->fetchAll(PDO::FETCH_NUM);
                $counts[$t] = count($rows);
                if (empty($rows)) {
                    continue;
                }
                $head   = 'INSERT INTO ' . $t . ' (' . implode(', ', $cols) . ') VALUES';
                $values = [];
                foreach ($rows as $row) {
                    $values[] = '(' . implode(', ', array_map([$this, 'escapeValue'], $row)) . ')';
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
            if ($this->pdo->inTransaction()) {
                $this->pdo->commit();
            }
        }

        $metaTables = [];
        foreach ($counts as $t => $c) {
            $metaTables[] = "{$t}({$c})";
        }
        $header = "-- Devops-Glue 数据备份（纯数据 .sql，不含 DDL）\n"
            . '-- META: exported_at=' . date('Y-m-d H:i:s') . "\n"
            . "-- META: source_driver={$this->driver}\n"
            . '-- META: app_version=' . AppConfig::APP_VERSION . "\n"
            . "-- META: mode={$mode}\n"
            . "-- META: tables=" . implode(',', $metaTables) . "\n"

            . "/*!40101 SET SQL_MODE='NO_BACKSLASH_ESCAPES' */;\n"
            . "/*!40014 SET FOREIGN_KEY_CHECKS=0 */;\n\n";


        $tmpSql  = $dir . '/' . $base . '.sql';
        $zipFile = $dir . '/' . $base . '.zip';
        if (file_put_contents($tmpSql, $header . $body) === false) {
            throw new \RuntimeException('写入备份临时 SQL 失败');
        }
        try {
            $zip = new \ZipArchive();
            $res = $zip->open($zipFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
            if ($res !== true) {
                throw new \RuntimeException('创建 zip 失败（ZipArchive::open=' . $res . '）');
            }
            $zip->addFile($tmpSql, $base . '.sql');
            $zip->close();
        } finally {
            if (is_file($tmpSql)) {
                @unlink($tmpSql);
            }
        }

        return ['name' => basename($zipFile), 'mode' => $mode, 'counts' => $counts];
    }

    /**
     * 滚动留存：同前缀只保留最近 $keep 份 zip。
     */
    private function rotatePrefix(string $prefix, int $keep): void
    {
        $dir   = $this->backupDir();
        $files = glob($dir . '/' . $prefix . '_*.zip') ?: [];
        if (count($files) > $keep) {
            usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
            foreach (array_slice($files, $keep) as $old) {
                @unlink($old);
            }
        }
    }

    /**
     * 列出备份目录下已生成的 zip 备份文件（按时间倒序），供数据管理面板渲染
     *
     * @return list<array{name:string, size:int, mtime:int}>
     */
    public function listBackups(): array
    {
        $dir   = $this->backupDir();
        $files = array_merge(
            glob($dir . '/devops-glue_*.zip') ?: [],
            glob($dir . '/devops-cd_*.zip') ?: []
        );
        $out   = [];
        foreach ($files as $f) {
            $out[] = [
                'name'  => basename($f),
                'size'  => (int) @filesize($f),
                'mtime' => (int) @filemtime($f),
            ];
        }
        usort($out, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
        return $out;
    }
}
