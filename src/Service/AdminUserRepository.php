<?php
namespace App\Service;

use App\Config\AppConfig;

class AdminUserRepository
{
    public function __construct(private \PDO $pdo) {}

    public function findByUsername(string $username): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT username, password_hash, systems, role FROM " . AppConfig::TABLE_ADMIN_USERS . " WHERE username = ?"
        );
        $stmt->execute([$username]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function createUser(string $username, string $passwordHash, string $role, string $systems = 'ci,cd'): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO " . AppConfig::TABLE_ADMIN_USERS . " (username, password_hash, role, systems, updated_at) VALUES (?, ?, ?, ?, " . Database::sqlNow() . ")"
        );
        $stmt->execute([$username, $passwordHash, $role, $systems]);
    }

    public function upsertPassword(string $username, string $passwordHash): void
    {
        // 只更新密码，绝不能用 REPLACE INTO / INSERT OR REPLACE ——
        // 那会整行删除重建，把 role/systems 回退到列默认值（super_admin 被降级成 admin）。
        $this->pdo->prepare(
            "UPDATE " . AppConfig::TABLE_ADMIN_USERS
            . " SET password_hash = ?, updated_at = " . Database::sqlNow()
            . " WHERE username = ?"
        )->execute([$passwordHash, $username]);
    }

    public function userExists(string $username): bool
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM " . AppConfig::TABLE_ADMIN_USERS . " WHERE username = ?");
        $stmt->execute([$username]);
        return (bool)$stmt->fetchColumn();
    }

    public function listUsers(bool $includeAdmin = true): array
    {
        if ($includeAdmin) {
            return $this->pdo->query("SELECT username, role, systems, updated_at FROM " . AppConfig::TABLE_ADMIN_USERS . " ORDER BY username")->fetchAll();
        }
        return $this->pdo->query("SELECT username, role, systems, updated_at FROM " . AppConfig::TABLE_ADMIN_USERS . " WHERE role NOT IN ('" . AppConfig::ROLE_ADMIN . "', '" . AppConfig::ROLE_SUPER_ADMIN . "') ORDER BY username")->fetchAll();
    }

    public function findUser(string $username): ?array
    {
        $stmt = $this->pdo->prepare("SELECT username, role FROM " . AppConfig::TABLE_ADMIN_USERS . " WHERE username = ?");
        $stmt->execute([$username]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function updateUser(string $username, ?string $passwordHash, ?string $role): void
    {
        $fields = [];
        $params = [];

        if ($role !== null) {
            $fields[] = 'role = ?';
            $params[] = $role;
        }
        if ($passwordHash !== null) {
            $fields[] = 'password_hash = ?';
            $params[] = $passwordHash;
        }
        if (empty($fields)) {
            return;
        }

        $fields[] = 'updated_at = ' . Database::sqlNow();
        $params[] = $username;
        $sql = 'UPDATE ' . AppConfig::TABLE_ADMIN_USERS . ' SET ' . implode(', ', $fields) . ' WHERE username = ?';
        $this->pdo->prepare($sql)->execute($params);
    }

    public function deleteUser(string $username): void
    {
        $this->pdo->prepare("DELETE FROM " . AppConfig::TABLE_ADMIN_USERS . " WHERE username = ?")->execute([$username]);
    }

    public function countByRole(string $role): int
    {
        $stmt = $this->pdo->prepare("SELECT count(*) FROM " . AppConfig::TABLE_ADMIN_USERS . " WHERE role = ?");
        $stmt->execute([$role]);
        return (int)$stmt->fetchColumn();
    }

    public function updateRoleName(string $oldRole, string $newRole): void
    {
        $this->pdo->prepare("UPDATE " . AppConfig::TABLE_ADMIN_USERS . " SET role = ? WHERE role = ?")->execute([$newRole, $oldRole]);
    }

    public function seedAdmin(): void
    {
        self::seedAdminFromEnv($this->pdo);
    }

    public static function seedAdminFromEnv(\PDO $pdo): void
    {
        $cnt = (int)$pdo->query("SELECT count(*) c FROM " . AppConfig::TABLE_ADMIN_USERS)->fetch()['c'];
        if ($cnt === 0) {
            $user = $_ENV['ADMIN_USER'] ?? 'admin';
            $pass = $_ENV['ADMIN_PASSWORD'] ?? '';
            if ($pass !== '') {
                $hash = password_hash($pass, PASSWORD_BCRYPT);
                $pdo->prepare("INSERT INTO " . AppConfig::TABLE_ADMIN_USERS . " (username, password_hash, role, systems) VALUES (?, ?, ?, 'ci,cd')")
                    ->execute([$user, $hash, AppConfig::ROLE_SUPER_ADMIN]);
            }
        }

        $rootUser = $_ENV['ADMIN_USER'] ?? 'admin';
        $pdo->prepare("UPDATE " . AppConfig::TABLE_ADMIN_USERS . " SET role = ? WHERE username = ? AND role = ?")
            ->execute([AppConfig::ROLE_SUPER_ADMIN, $rootUser, AppConfig::ROLE_ADMIN]);
    }
}
