<?php

declare(strict_types=1);

namespace App\Service;

use App\Config\AppConfig;

/**
 * ci_permissions 与 ci_implied_rules 的读写。
 */
class PermissionRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /** @return list<array<string,mixed>> 权限列表，每项附加 is_builtin 标记 */
    public function listAll(): array
    {
        $rows = $this->pdo->query(
            "SELECT perm_key, description, parent_key, created_at FROM " . AppConfig::TABLE_PERMISSIONS . " ORDER BY perm_key"
        )->fetchAll();
        /** @var list<array<string,mixed>> $rows */
        foreach ($rows as &$row) {
            $row['is_builtin'] = array_key_exists($row['perm_key'], AppConfig::DEFAULT_PERMISSIONS);
        }
        unset($row);
        return $rows;
    }

    /** @return array<string, list<string>> 隐含规则映射（source_key → target_key 列表） */
    public function listImpliedRules(): array
    {
        $implied = [];
        try {
            $rows = $this->pdo->query("SELECT source_key, target_key FROM " . AppConfig::TABLE_IMPLIED_RULES)->fetchAll();
            foreach ($rows as $r) {
                $implied[$r['source_key']][] = $r['target_key'];
            }
        } catch (\Exception $e) {
            \App\Helper\Log::exception($e);
        }
        return $implied;
    }

    /** 存在则更新 description/parent_key，否则插入。 */
    public function upsert(string $permKey, string $desc, ?string $parent): void
    {
        $exists = $this->pdo->prepare("SELECT COUNT(*) FROM " . AppConfig::TABLE_PERMISSIONS . " WHERE perm_key = ?");
        $exists->execute([$permKey]);
        if ((int)$exists->fetchColumn() > 0) {
            $this->pdo->prepare(
                "UPDATE " . AppConfig::TABLE_PERMISSIONS . " SET description = ?, parent_key = ? WHERE perm_key = ?"
            )->execute([$desc, $parent, $permKey]);
        } else {
            $this->pdo->prepare(
                "INSERT INTO " . AppConfig::TABLE_PERMISSIONS . " (perm_key, description, parent_key, created_at) VALUES (?, ?, ?, " . Database::sqlNow() . ")"
            )->execute([$permKey, $desc, $parent]);
        }
    }

    /** 删除权限及其在角色、隐含规则中的引用。 */
    public function delete(string $permKey): void
    {
        $this->pdo->prepare("DELETE FROM " . AppConfig::TABLE_PERMISSIONS . " WHERE perm_key = ?")->execute([$permKey]);
        $this->pdo->prepare("DELETE FROM " . AppConfig::TABLE_ROLE_PERMISSIONS . " WHERE perm_key = ?")->execute([$permKey]);
        $this->pdo->prepare("DELETE FROM " . AppConfig::TABLE_IMPLIED_RULES . " WHERE source_key = ? OR target_key = ?")->execute([$permKey, $permKey]);
    }

    /** @param list<string> $keys 计算 perm_key 命中数 */
    public function countByKeys(array $keys): int
    {
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM " . AppConfig::TABLE_PERMISSIONS . " WHERE perm_key IN ({$placeholders})");
        $stmt->execute($keys);
        return (int)$stmt->fetchColumn();
    }

    public function upsertImpliedRule(string $src, string $tgt): void
    {
        $sql = Database::sqlUpsert(AppConfig::TABLE_IMPLIED_RULES, 'source_key, target_key', '?, ?');
        $this->pdo->prepare($sql)->execute([$src, $tgt]);
    }

    public function deleteImpliedRule(string $src, string $tgt): void
    {
        $this->pdo->prepare("DELETE FROM " . AppConfig::TABLE_IMPLIED_RULES . " WHERE source_key = ? AND target_key = ?")->execute([$src, $tgt]);
    }
}
