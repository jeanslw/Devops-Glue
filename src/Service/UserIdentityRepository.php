<?php
namespace App\Service;

use App\Config\AppConfig;

/**
 * user_identities 仓储：一个 admin_users.username 可绑定多个身份源（ldap/local/oauth_*）
 *
 * 设计原则：
 *  - username 不复制用户资料，仅引用 admin_users.username（不级联，删除由上层决定）
 *  - provider_type + provider_uid 全局唯一：LDAP 里是 DN / entryUUID；local 里是 admin_users.username
 *  - credential 只许 local 存 bcrypt hash；ldap/oauth 一律 NULL，避免旁路掉外部认证
 *  - 本仓库不做 LDAP 网络调用，纯 SQL；LdapService 调本仓库落盘
 */
class UserIdentityRepository
{
    public function __construct(private \PDO $pdo) {}

    /** 按身份源 + 外部 UID 查（登录主路径），返回行或 null */
    public function find(string $providerType, string $providerUid): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, username, provider_type, provider_uid, credential, email, raw_profile, bound_at, updated_at
               FROM " . AppConfig::TABLE_USER_IDENTITIES . "
              WHERE provider_type = ? AND provider_uid = ?
              LIMIT 1"
        );
        $stmt->execute([$providerType, $providerUid]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** 列出某用户名下所有已绑定的身份源（管理界面展示用） */
    public function listByUsername(string $username): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, username, provider_type, provider_uid, email, bound_at, updated_at
               FROM " . AppConfig::TABLE_USER_IDENTITIES . "
              WHERE username = ?
              ORDER BY provider_type, provider_uid"
        );
        $stmt->execute([$username]);
        return $stmt->fetchAll();
    }

    /**
     * 新建绑定，幂等：同 (provider_type, provider_uid) 冲突时返回 false 由上层报错
     */
    public function bind(string $username, string $providerType, string $providerUid, array $opts = []): bool
    {
        $email = (string)($opts['email'] ?? '');
        $credential = $opts['credential'] ?? null; // 仅 local 用，外部 provider 保持 null
        $rawProfile = $opts['raw_profile'] ?? null; // 数组 → JSON 存 raw_profile
        $now = Database::sqlNow();

        $stmt = $this->pdo->prepare(
            "INSERT INTO " . AppConfig::TABLE_USER_IDENTITIES . "
                (username, provider_type, provider_uid, credential, email, raw_profile, bound_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, {$now}, {$now})"
        );
        try {
            return $stmt->execute([
                $username,
                $providerType,
                $providerUid,
                $credential,
                $email,
                is_array($rawProfile) ? json_encode($rawProfile, JSON_UNESCAPED_UNICODE) : $rawProfile,
            ]);
        } catch (\PDOException $e) {
            // 唯一索引冲突 = 已绑定，返回 false 让上层决定（不静默覆盖）
            return false;
        }
    }

    /** 解除绑定（管理界面允许 super_admin 解绑 ldap，以便清理离职人员） */
    public function unbind(string $providerType, string $providerUid): bool
    {
        $stmt = $this->pdo->prepare(
            "DELETE FROM " . AppConfig::TABLE_USER_IDENTITIES
            . " WHERE provider_type = ? AND provider_uid = ?"
        );
        return $stmt->execute([$providerType, $providerUid]) && $stmt->rowCount() > 0;
    }

    /**
     * 登录成功后回填最新 LDAP 属性（email/raw_profile）。
     * 与 bind 不同：这里幂等 update，不插入；快照源是 LDAP 服务端字段，属缓存用途。
     */
    public function refreshProfile(string $providerType, string $providerUid, string $email = '', ?array $rawProfile = null): void
    {
        $now = Database::sqlNow();
        $this->pdo->prepare(
            "UPDATE " . AppConfig::TABLE_USER_IDENTITIES . "
                SET email = ?, raw_profile = ?, updated_at = {$now}
              WHERE provider_type = ? AND provider_uid = ?"
        )->execute([
            $email,
            $rawProfile === null ? null : json_encode($rawProfile, JSON_UNESCAPED_UNICODE),
            $providerType,
            $providerUid,
        ]);
    }
}
