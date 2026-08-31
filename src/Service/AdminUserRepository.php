<?php

namespace App\Service;

use App\Config\AppConfig;

class AdminUserRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function findByUsername(string $username): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT username, password_hash, systems, role, email, status, avatar_url FROM " . AppConfig::TABLE_ADMIN_USERS . " WHERE username = ?"
        );
        $stmt->execute([$username]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function createUser(string $username, string $passwordHash, string $role, string $systems = 'ci,cd', string $email = ''): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO " . AppConfig::TABLE_ADMIN_USERS . " (username, password_hash, role, systems, email, created_at) VALUES (?, ?, ?, ?, ?, " . Database::sqlNow() . ")"
        );
        $stmt->execute([$username, $passwordHash, $role, $systems, $email]);
    }

    public function updatePassword(string $username, string $passwordHash): void
    {
        // 只更新密码，绝不能用 REPLACE INTO / INSERT OR REPLACE ——
        // 那会整行删除重建，把 role/systems 回退到列默认值（super_admin 被降级成 admin）。
        $stmt = $this->pdo->prepare(
            "UPDATE " . AppConfig::TABLE_ADMIN_USERS
            . " SET password_hash = ?, updated_at = " . Database::sqlNow()
            . " WHERE username = ?"
        );
        $stmt->execute([$passwordHash, $username]);
        // 防御：调用方已先校验用户存在，此处 rowCount 必须为 1；
        // 若为 0（用户被并发删除 / 用户名大小写不匹配）绝不静默吞掉。
        // 新 bcrypt 哈希必然与旧值不同，不存在"值未变导致 rowCount=0"的误报。
        if ($stmt->rowCount() !== 1) {
            throw new \RuntimeException("updatePassword: 未命中用户 {$username}");
        }
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
            return $this->pdo->query("SELECT username, role, systems, email, status, created_at, updated_at FROM " . AppConfig::TABLE_ADMIN_USERS . " ORDER BY username")->fetchAll();
        }
        return $this->pdo->query("SELECT username, role, systems, email, status, created_at, updated_at FROM " . AppConfig::TABLE_ADMIN_USERS . " WHERE role NOT IN ('" . AppConfig::ROLE_ADMIN . "', '" . AppConfig::ROLE_SUPER_ADMIN . "') ORDER BY username")->fetchAll();
    }

    public function findUser(string $username): ?array
    {
        $stmt = $this->pdo->prepare("SELECT username, role FROM " . AppConfig::TABLE_ADMIN_USERS . " WHERE username = ?");
        $stmt->execute([$username]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * API 用户列表（CD 读接口用）：只回 username/role/systems/status，
     * 绝不携带 password_hash / email，收口 CD 直读 admin_users 的哈希暴露面。
     */
    public function listUsersForApi(): array
    {
        return $this->pdo->query(
            "SELECT username, role, systems, status FROM " . AppConfig::TABLE_ADMIN_USERS . " ORDER BY username"
        )->fetchAll();
    }

    /** API 单用户读（CD 读接口用）：同样不含 password_hash。 */
    public function findUserForApi(string $username): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT username, role, systems, status FROM " . AppConfig::TABLE_ADMIN_USERS . " WHERE username = ?"
        );
        $stmt->execute([$username]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** API 角色目录（供 CD 审批规则选角色）：只回 name/description，不含 is_system/created_at。 */
    public function listRolesForApi(): array
    {
        return $this->pdo->query(
            "SELECT name, description FROM " . AppConfig::TABLE_ROLES . " ORDER BY name"
        )->fetchAll();
    }

    /** 角色是否已存在于 roles 表（建号/改号前校验，防「角色名悬空」导致权限加载为空）。 */
    public function roleExists(string $role): bool
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM " . AppConfig::TABLE_ROLES . " WHERE name = ?");
        $stmt->execute([$role]);
        return (bool)$stmt->fetchColumn();
    }

    public function updateUser(string $username, ?string $passwordHash, ?string $role, ?string $email = null): void
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
        if ($email !== null) {
            $fields[] = 'email = ?';
            $params[] = $email;
        }
        if (empty($fields)) {
            return;
        }

        // 先查存在性、再 UPDATE：不能依赖 rowCount —— MySQL 下 rowCount 是「变更行数」而非「命中行数」，
        // 同一秒内重复提交（role/email 值未变，updated_at 的 NOW() 为秒级精度也未变）时 rowCount 会误报 0，
        // 导致对存在的用户误抛「未命中用户」。同 setStatus()。
        if (!$this->userExists($username)) {
            throw new \RuntimeException("updateUser: 未命中用户 {$username}");
        }

        $fields[] = 'updated_at = ' . Database::sqlNow();
        $params[] = $username;
        $sql = 'UPDATE ' . AppConfig::TABLE_ADMIN_USERS . ' SET ' . implode(', ', $fields) . ' WHERE username = ?';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    /**
     * 启用/停用用户（status: 1=启用, 0=停用）。更新状态同时刷新 updated_at。
     */
    public function setStatus(string $username, int $status): void
    {
        // 先查存在性、再 UPDATE：不能依赖 rowCount —— MySQL 下 rowCount 是「变更行数」而非「命中行数」，
        // 同一秒内重复 toggle（status 值未变）时 rowCount 会误报 0，导致误抛「未命中用户」。
        if (!$this->userExists($username)) {
            throw new \RuntimeException("setStatus: 未命中用户 {$username}");
        }
        $stmt = $this->pdo->prepare(
            "UPDATE " . AppConfig::TABLE_ADMIN_USERS
            . " SET status = ?, updated_at = " . Database::sqlNow()
            . " WHERE username = ?"
        );
        $stmt->execute([$status, $username]);
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
        // 种子账号邮箱：ADMIN_EMAIL 未配置时落一个占位地址，保证 OIDC/OAuth 的 userinfo
        // 与 id_token 始终有 email 可用（部分下游按 email 匹配/建号，不能为空）。
        // 配置 ADMIN_EMAIL 后即改用真实邮箱；占位值可在后台自由改回真实邮箱。
        $adminEmail = trim((string)($_ENV['ADMIN_EMAIL'] ?? ''));
        if ($adminEmail !== '' && !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            $adminEmail = ''; // 格式非法则忽略，回落到占位地址，不把脏值写进库
        }
        if ($adminEmail === '') {
            $adminEmail = 'admin@example.com'; // 占位，等待部署方在后台改成真实邮箱
        }

        $cnt = (int)$pdo->query("SELECT count(*) c FROM " . AppConfig::TABLE_ADMIN_USERS)->fetch()['c'];
        if ($cnt === 0) {
            // 统一小写存储，与登录/建号规范化一致（SQLite 大小写敏感）
            $user = strtolower($_ENV['ADMIN_USER'] ?? 'admin');
            $pass = $_ENV['ADMIN_PASSWORD'] ?? '';
            if ($pass !== '') {
                $hash = password_hash($pass, PASSWORD_BCRYPT);
                if ($adminEmail !== '') {
                    $pdo->prepare("INSERT INTO " . AppConfig::TABLE_ADMIN_USERS . " (username, password_hash, role, systems, email) VALUES (?, ?, ?, 'ci,cd', ?)")
                        ->execute([$user, $hash, AppConfig::ROLE_SUPER_ADMIN, $adminEmail]);
                } else {
                    $pdo->prepare("INSERT INTO " . AppConfig::TABLE_ADMIN_USERS . " (username, password_hash, role, systems) VALUES (?, ?, ?, 'ci,cd')")
                        ->execute([$user, $hash, AppConfig::ROLE_SUPER_ADMIN]);
                }
            }
        }

        $rootUser = strtolower($_ENV['ADMIN_USER'] ?? 'admin');
        $pdo->prepare("UPDATE " . AppConfig::TABLE_ADMIN_USERS . " SET role = ? WHERE username = ? AND role = ?")
            ->execute([AppConfig::ROLE_SUPER_ADMIN, $rootUser, AppConfig::ROLE_ADMIN]);

        // 存量库回填：仅在 email 为空、或仍是占位地址时补写，绝不覆盖用户已在界面上改过的邮箱。
        // 旧库（auto_migrate=false 的手工建库模式）可能尚无 email 列，失败不阻断启动。
        try {
            $pdo->prepare(
                "UPDATE " . AppConfig::TABLE_ADMIN_USERS
                . " SET email = ? WHERE username = ? AND (email IS NULL OR email = '' OR email = ?)"
            )->execute([$adminEmail, $rootUser, 'admin@example.com']);
        } catch (\Throwable $e) {
            \App\Helper\Log::exception($e);
        }
    }
}
