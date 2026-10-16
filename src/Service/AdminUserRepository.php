<?php

namespace App\Service;

use App\Config\AppConfig;

class AdminUserRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findByUsername(string $username): ?array
    {
        $username = $this->normalizeUsername($username);
        $stmt = $this->pdo->prepare(
            "SELECT username, password_hash, systems, role, email, status, avatar_url, must_change_password FROM " . AppConfig::TABLE_ADMIN_USERS . " WHERE username = ?"
        );
        $stmt->execute([$username]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function createUser(string $username, string $passwordHash, string $role, string $systems = 'ci,cd', string $email = ''): void
    {
        $username = $this->normalizeUsername($username);
        // 机制级兜底：所有建号路径共用本方法，非法用户名在这里一律拒绝，
        // 不依赖各控制器各自记得校验。
        if (!self::isValidUsername($username)) {
            throw new \InvalidArgumentException("createUser: 非法用户名 {$username}");
        }
        $stmt = $this->pdo->prepare(
            "INSERT INTO " . AppConfig::TABLE_ADMIN_USERS . " (username, password_hash, role, systems, email, created_at) VALUES (?, ?, ?, ?, ?, " . Database::sqlNow() . ")"
        );
        $stmt->execute([$username, $passwordHash, $role, $systems, $email]);
    }

    public function updatePassword(string $username, string $passwordHash): void
    {
        $username = $this->normalizeUsername($username);
        // 只更新密码，不可用 REPLACE INTO / INSERT OR REPLACE：
        // 那会整行删除重建，把 role/systems 回退到列默认值（super_admin 被降级成 admin）。
        $stmt = $this->pdo->prepare(
            "UPDATE " . AppConfig::TABLE_ADMIN_USERS
            . " SET password_hash = ?, updated_at = " . Database::sqlNow()
            . ", must_change_password = 0"
            . " WHERE username = ?"
        );
        $stmt->execute([$passwordHash, $username]);
        // 防御：调用方已先校验用户存在，此处 rowCount 必须为 1；
        // 若为 0（用户被并发删除 / 用户名大小写不匹配）直接抛异常，不忽略。
        // 新 bcrypt 哈希必然与旧值不同，不存在"值未变导致 rowCount=0"的误报。
        if ($stmt->rowCount() !== 1) {
            throw new \RuntimeException("updatePassword: 未命中用户 {$username}");
        }
    }

    public function userExists(string $username): bool
    {
        $username = $this->normalizeUsername($username);
        $stmt = $this->pdo->prepare("SELECT 1 FROM " . AppConfig::TABLE_ADMIN_USERS . " WHERE username = ?");
        $stmt->execute([$username]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listUsers(bool $includeAdmin = true): array
    {
        if ($includeAdmin) {
            return array_values($this->pdo->query("SELECT username, role, systems, email, status, created_at, updated_at FROM " . AppConfig::TABLE_ADMIN_USERS . " ORDER BY username")->fetchAll());
        }
        return array_values($this->pdo->query("SELECT username, role, systems, email, status, created_at, updated_at FROM " . AppConfig::TABLE_ADMIN_USERS . " WHERE role NOT IN ('" . AppConfig::ROLE_ADMIN . "', '" . AppConfig::ROLE_SUPER_ADMIN . "') ORDER BY username")->fetchAll());
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findUser(string $username): ?array
    {
        $username = $this->normalizeUsername($username);
        // systems 一并无脑带出：跨系统服务账号（如 RbacController）要据此做归属校验，
        // 多返回一列对既有调用方无副作用。
        $stmt = $this->pdo->prepare("SELECT username, role, systems FROM " . AppConfig::TABLE_ADMIN_USERS . " WHERE username = ?");
        $stmt->execute([$username]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * API 用户列表（CD 读接口用）：只回 username/role/systems/status，
     * 绝不携带 password_hash / email，收口 CD 直读 admin_users 的哈希暴露面。
     * @return list<array<string,mixed>>
     */
    public function listUsersForApi(): array
    {
        return array_values($this->pdo->query(
            "SELECT username, role, systems, status FROM " . AppConfig::TABLE_ADMIN_USERS . " ORDER BY username"
        )->fetchAll());
    }

    /**
     * API 单用户读（CD 读接口用）：同样不含 password_hash。
     * @return array<string,mixed>|null
     */
    public function findUserForApi(string $username): ?array
    {
        $username = $this->normalizeUsername($username);
        $stmt = $this->pdo->prepare(
            "SELECT username, role, systems, status FROM " . AppConfig::TABLE_ADMIN_USERS . " WHERE username = ?"
        );
        $stmt->execute([$username]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * API 角色目录（供 CD 审批规则选角色）：只回 name/description，不含 is_system/created_at。
     * @return list<array<string,mixed>>
     */
    public function listRolesForApi(): array
    {
        return array_values($this->pdo->query(
            "SELECT name, description FROM " . AppConfig::TABLE_ROLES . " ORDER BY name"
        )->fetchAll());
    }

    /**
     * 取角色的规范名（roles 表存储值），不存在返回 null。
     *
     * MySQL 默认排序规则大小写不敏感，'SUPER_ADMIN' 这类变体能命中 roles 表，
     * 但 PHP 侧的 === 守卫（super_admin/admin 特权判断）与落库值都是大小写敏感的，
     * 直接落库会得到一个绕过守卫却加载不到权限的脏角色。调用方必须用本方法取回
     * 规范名后再做 === 判断与写库，保证比较与存储用的是同一个值。
     */
    public function canonicalRole(string $role): ?string
    {
        $stmt = $this->pdo->prepare("SELECT name FROM " . AppConfig::TABLE_ROLES . " WHERE name = ?");
        $stmt->execute([$role]);
        $name = $stmt->fetchColumn();
        return $name === false ? null : (string)$name;
    }

    /**
     * 用户名校验：小写字母/数字/._@-，1–64 位。
     *
     * 必须拒绝 '|'：登录 token 落库值以 '|' 分隔用户名与角色，含 '|' 的用户名
     * 会让反查解析错位；同时拒绝控制字符与超长名（MySQL 严格模式下超长直接 500）。
     * 允许 '@' 以兼容邮箱形式的用户名。
     */
    public static function isValidUsername(string $username): bool
    {
        return preg_match('/^[a-z0-9_.@-]{1,64}$/', $username) === 1;
    }

    public function updateUser(string $username, ?string $passwordHash, ?string $role, ?string $email = null): void
    {
        $username = $this->normalizeUsername($username);
        $fields = [];
        $params = [];

        if ($role !== null) {
            $fields[] = 'role = ?';
            $params[] = $role;
        }
        if ($passwordHash !== null) {
            $fields[] = 'password_hash = ?';
            $params[] = $passwordHash;
            $fields[] = 'must_change_password = 0';
        }
        if ($email !== null) {
            $fields[] = 'email = ?';
            $params[] = $email;
        }
        if (empty($fields)) {
            return;
        }

        // 先查存在性、再 UPDATE：不能依赖 rowCount，MySQL 下 rowCount 是变更行数而非命中行数，
        // 同一秒内重复提交（role/email 值未变，updated_at 的 NOW() 为秒级精度也未变）时 rowCount 会误报 0，
        // 导致对存在的用户误抛未命中用户异常。同 setStatus()。
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
        $username = $this->normalizeUsername($username);
        // 先查存在性、再 UPDATE：不能依赖 rowCount，MySQL 下 rowCount 是变更行数而非命中行数，
        // 同一秒内重复 toggle（status 值未变）时 rowCount 会误报 0，导致误抛未命中用户异常。
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
        $username = $this->normalizeUsername($username);
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

    private function normalizeUsername(string $username): string
    {
        return strtolower(trim($username));
    }

    public function seedAdmin(): void
    {
        self::seedAdminFromEnv($this->pdo);
    }

    public static function seedAdminFromEnv(\PDO $pdo): void
    {
        // 种子账号邮箱：ADMIN_EMAIL 未配置时落一个按部署唯一化的占位地址。
        // 这样既保证 OIDC/OAuth 的 userinfo / id_token 有 email 可用，
        // 又避免不同部署环境共享同一个固定邮箱造成误识别。
        $adminEmail = trim((string)($_ENV['ADMIN_EMAIL'] ?? ''));
        if ($adminEmail !== '' && !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            $adminEmail = ''; // 格式非法则忽略，回落到部署唯一化占位值，不把脏值写进库
        }
        if ($adminEmail === '') {
            $hostname = strtolower(trim((string)($_ENV['APP_HOST'] ?? $_ENV['HOSTNAME'] ?? gethostname())));
            $instance = $hostname !== '' ? preg_replace('/[^a-z0-9.-]+/', '-', $hostname) : 'local';
            $instance = trim((string)$instance, ".-");
            $instance = $instance !== '' ? $instance : 'local';
            $adminEmail = 'admin@' . $instance . '.local';
        }

        $defaultAdminEmail = 'admin@example.com';
        $deploymentPlaceholderEmail = $adminEmail;

        $cnt = (int)$pdo->query("SELECT count(*) c FROM " . AppConfig::TABLE_ADMIN_USERS)->fetch()['c'];
        if ($cnt === 0) {
            // 统一小写存储，与登录/建号规范化一致（SQLite 大小写敏感）
            $user = strtolower($_ENV['ADMIN_USER'] ?? 'root');
            $pass = $_ENV['ADMIN_PASSWORD'] ?? '';
            if ($pass !== '') {
                $hash = PasswordHasher::hash($pass);
                $pdo->prepare("INSERT INTO " . AppConfig::TABLE_ADMIN_USERS . " (username, password_hash, role, systems, email, must_change_password) VALUES (?, ?, ?, 'ci,cd', ?, 1)")
                    ->execute([$user, $hash, AppConfig::ROLE_SUPER_ADMIN, $adminEmail]);
            }
        }
        // 表非空后，config 的 ADMIN_USER/ADMIN_PASSWORD 即失效：DB 是唯一权威，登录/改密均以库中
        // super_admin 为准。此处不比对、不告警——用户改密后 config 里的种子密码自然过期，即使与
        // 库中密码恰好一致也不作为任何依据。

        $rootUser = strtolower($_ENV['ADMIN_USER'] ?? 'root');
        $pdo->prepare("UPDATE " . AppConfig::TABLE_ADMIN_USERS . " SET role = ? WHERE username = ? AND role = ?")
            ->execute([AppConfig::ROLE_SUPER_ADMIN, $rootUser, AppConfig::ROLE_ADMIN]);

        // 存量库回填：仅在 email 为空、或仍是占位地址时补写，绝不覆盖用户已在界面上改过的邮箱。
        // 旧库（auto_migrate=false 的手工建库模式）可能尚无 email 列，失败不阻断启动。
        try {
            $pdo->prepare(
                "UPDATE " . AppConfig::TABLE_ADMIN_USERS
                . " SET email = ? WHERE username = ? AND (email IS NULL OR email = '' OR email = ? OR email = ?)"
            )->execute([$adminEmail, $rootUser, $defaultAdminEmail, $deploymentPlaceholderEmail]);
        } catch (\Throwable $e) {
            \App\Helper\Log::exception($e);
        }

        // 安全提示：seed 账号仍在用初始种子密码（must_change_password=1，尚未完成首次登录强制改密）
        // 时告警。种子密码写在 config，属已知值；不尽快改密等于留下「已知口令的 super_admin」。
        // 旧库可能尚无 must_change_password 列，失败不阻断启动（同 email 回填）。
        try {
            $stmt = $pdo->prepare(
                'SELECT username FROM ' . AppConfig::TABLE_ADMIN_USERS . ' WHERE must_change_password = 1 LIMIT 1'
            );
            $stmt->execute();
            $pending = $stmt->fetchColumn();
            if ($pending !== false) {
                \App\Helper\Log::warning(
                    "账号 '{$pending}' 仍使用首次建库的初始种子密码（尚未完成首次登录强制改密），请尽快登录并修改密码"
                );
            }
        } catch (\Throwable $e) {
            \App\Helper\Log::exception($e);
        }
    }
}
