<?php

declare(strict_types=1);

namespace App\Service;

use App\Config\AppConfig;

/**
 * ci_roles 与 ci_role_permissions 的读写。
 */
class RoleRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /** @return list<array<string,mixed>> 角色列表，每项附加 permissions（perm_key 数组） */
    public function listWithPermissions(): array
    {
        $roles = $this->pdo->query(
            "SELECT id, name, description, is_system, created_at FROM " . AppConfig::TABLE_ROLES . " ORDER BY id"
        )->fetchAll();
        /** @var list<array<string,mixed>> $roles */
        $permStmt = $this->pdo->prepare("SELECT perm_key FROM " . AppConfig::TABLE_ROLE_PERMISSIONS . " WHERE role_id = ?");
        foreach ($roles as &$r) {
            $permStmt->execute([$r['id']]);
            $r['permissions'] = $permStmt->fetchAll(\PDO::FETCH_COLUMN);
            $r['is_system'] = (bool)$r['is_system'];
        }
        return $roles;
    }

    public function nameExists(string $name): bool
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM " . AppConfig::TABLE_ROLES . " WHERE name = ?");
        $stmt->execute([$name]);
        return (bool)$stmt->fetch();
    }

    public function nameExistsExcluding(string $name, int $roleId): bool
    {
        $stmt = $this->pdo->prepare("SELECT id FROM " . AppConfig::TABLE_ROLES . " WHERE name = ? AND id != ?");
        $stmt->execute([$name, $roleId]);
        return (bool)$stmt->fetch();
    }

    /** 新建角色，返回新行 id。 */
    public function create(string $name, string $desc): int
    {
        $this->pdo->prepare(
            "INSERT INTO " . AppConfig::TABLE_ROLES . " (name, description, is_system, created_at) VALUES (?, ?, 0, " . Database::sqlNow() . ")"
        )->execute([$name, $desc]);
        return (int)$this->pdo->lastInsertId();
    }

    /** @return array<string,mixed>|null 只回 id/is_system/name（与原控制器口径一致） */
    public function findById(int $roleId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT id, is_system, name FROM " . AppConfig::TABLE_ROLES . " WHERE id = ?");
        $stmt->execute([$roleId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * 更新角色的指定列。
     *
     * @param array<string,string> $sets 列名 => 新值（只含待更新列）
     */
    public function update(int $roleId, array $sets): void
    {
        if ($sets === []) {
            return;
        }
        $fields = [];
        $params = [];
        foreach ($sets as $col => $val) {
            $fields[] = $col . ' = ?';
            $params[] = $val;
        }
        $params[] = $roleId;
        $this->pdo->prepare(
            "UPDATE " . AppConfig::TABLE_ROLES . " SET " . implode(', ', $fields) . " WHERE id = ?"
        )->execute($params);
    }

    /**
     * @param list<string> $permKeys 展开后的权限列表
     * @param list<string> $validKeys 合法 perm_key 全集
     */
    public function insertPermissions(int $roleId, array $permKeys, array $validKeys): void
    {
        $stmt = $this->pdo->prepare("INSERT INTO " . AppConfig::TABLE_ROLE_PERMISSIONS . " (role_id, perm_key) VALUES (?, ?)");
        foreach ($permKeys as $pk) {
            if (in_array($pk, $validKeys, true)) {
                try {
                    $stmt->execute([$roleId, $pk]);
                } catch (\Exception $e) {
                    \App\Helper\Log::exception($e);
                }
            }
        }
    }

    public function deletePermissions(int $roleId): void
    {
        $this->pdo->prepare("DELETE FROM " . AppConfig::TABLE_ROLE_PERMISSIONS . " WHERE role_id = ?")->execute([$roleId]);
    }

    public function delete(int $roleId): void
    {
        $this->pdo->prepare("DELETE FROM " . AppConfig::TABLE_ROLE_PERMISSIONS . " WHERE role_id = ?")->execute([$roleId]);
        $this->pdo->prepare("DELETE FROM " . AppConfig::TABLE_ROLES . " WHERE id = ?")->execute([$roleId]);
    }
}
