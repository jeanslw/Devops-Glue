<?php

namespace App\Service;

use App\Config\AppConfig;
use PDO;

/**
 * Web 端数据库备份服务（仅备份，不提供恢复）。
 *   - 支持 sqlite / mysql 两种驱动；
 *   - 纯数据 .sql（INSERT，不含 DDL），排除易失/派生缓存表（cache、ci_platform_versions）；
 *   - 产物 zip 命名 devops-glue_<driver>_<datetime>.zip，保存到 BACKUP_DIR；
 *   - 目录：Docker 内 BACKUP_DIR=/data/backups（compose 卷映射宿主 ./data/backups）；
 *     非 Docker / 未设 BACKUP_DIR 时落仓库根 backups/（与 CLI 备份 bin/backup_lib.php 一致）；
 *     目录不存在则自动创建，并滚动留存最近 $keep 份。
 */
class DataBackupService
{
    private PDO $pdo;
    private string $driver;

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

    /** 备份排除表：易失/派生缓存，绝不落进备份文件 */
    private function excludedTables(): array
    {
        return [AppConfig::TABLE_CACHE, AppConfig::TABLE_PLATFORM_VERSIONS];
    }

    /** 应用自有表清单（AppConfig 全部 TABLE_ 常量，反射读取，未来加表自动跟随） */
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

    /** 枚举全部用户表（排除 sqlite 内部表）；表名只接受 [a-zA-Z0-9_] */
    private function listTables(): array
    {
        if ($this->driver === 'mysql') {
            $names = $this->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
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

    /** 表列清单（按物理顺序），列名只接受安全字符 */
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
        return $cols;
    }

    /**
     * SQL 标准值转义：'' 加倍、NULL 保持 NULL、反斜杠按字面量保留。
     * 配合 NO_BACKSLASH_ESCAPES 跨驱动通用。
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
            // 非 Docker / 未设 BACKUP_DIR 时落仓库根 backups/（bin/backup_lib.php 的 CLI 默认一致）
            $dir = dirname(__DIR__, 2) . '/backups';
        }
        $dir = rtrim($dir, '/');
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        return $dir;
    }
/**
     * 执行数据库备份，生成 zip 归档。
     *
     * 一致性：MySQL REPEATABLE READ 快照、SQLite 读事务；导出经由临时 .sql 打入 zip 后清理。
     * @param int $keep 同一驱动前缀下最多保留的 zip 份数
     * @return array{0: string, 1: array<string,int>} [zip 文件名, 各表行数]
     */
    public function backup(int $keep = 10): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('zip 扩展不可用');
        }

        $dir    = $this->backupDir();
        $tables = array_values(array_diff(
            array_intersect($this->listTables(), $this->appTableNames()),
            $this->excludedTables()
        ));
        if (empty($tables)) {
            throw new \RuntimeException('没有任何应用表可备份（数据库未初始化？）');
        }

        $stamp = date('Ymd_His');
        $base  = 'devops-glue_' . $this->driver . '_' . $stamp;

        // 1) 导出纯数据 .sql
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
                    continue; // 空表不写 INSERT
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
                $this->pdo->commit(); // 读事务尽快提交，不占用锁
            }
        }

        $metaTables = [];
        foreach ($counts as $t => $c) {
            $metaTables[] = "{$t}({$c})";
        }
        $header = "-- Devops-Glue 数据备份（Web 触发导出）\n"
            . '-- META: exported_at=' . date('Y-m-d H:i:s') . "\n"
            . "-- META: source_driver={$this->driver}\n"
            . '-- META: app_version=' . AppConfig::APP_VERSION . "\n"
            . "-- META: tables=" . implode(',', $metaTables) . "\n"
            // mysqldump 同款版本注释：MySQL 服务端执行、SQLite 视为普通注释，同一份文件两种引擎都能吃
            . "/*!40101 SET SQL_MODE='NO_BACKSLASH_ESCAPES' */;\n"
            . "/*!40014 SET FOREIGN_KEY_CHECKS=0 */;\n\n";

        // 2) 写临时 .sql 再打入 zip，无论成败都清理临时 .sql
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

        // 3) 滚动留存：同驱动前缀只保留最近 $keep 份 zip
        $files = glob($dir . '/devops-glue_' . $this->driver . '_*.zip') ?: [];
        if (count($files) > $keep) {
            usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
            foreach (array_slice($files, $keep) as $old) {
                @unlink($old);
            }
        }

        return [basename($zipFile), $counts];
    }
}