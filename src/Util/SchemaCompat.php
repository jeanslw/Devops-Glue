<?php

declare(strict_types=1);

namespace App\Util;

/**
 * 判断异常是否来自 schema 迁移期间的兼容错误（表 / 列缺失）。
 *
 * 升级瞬间表或列尚未补齐时，应沿用旧行为（不锁定会话），而非 fail-closed；
 * 其他数据库异常才应失败即锁。OAuthService 与 TokenService 共用本判定，确保口径一致：
 * - 含 SQLSTATE 标准码（42S02 表不存在 / 42S22、42703 列不存在）
 * - 含 SQLite / PostgreSQL 等以消息文本表达的缺失（HY000 + "no such table"、42P01 等）
 */
final class SchemaCompat
{
    public static function isEvolutionError(\PDOException $e): bool
    {
        $code = (string) $e->getCode();
        if (in_array($code, ['42S02', '42S22', '42703', '42P01'], true)) {
            return true;
        }
        return (bool) preg_match('/no such table|no such column|unknown column/i', $e->getMessage());
    }
}
