<?php

namespace App\Service;

use App\Config\AppConfig;

/**
 * Token 验证服务
 *
 * 统一封装 cache token / 旧版 base64 token 的验证逻辑，
 * 供 AuthMiddleware 和 MainController（文档鉴权）复用，消除重复代码。
 */
class TokenService
{
    public function __construct(
        private \PDO $pdo,
        private Settings $config,
        private ?ApiTokenService $apiTokenService = null
    ) {
    }

    /**
     * 登录 token 入库前统一做 sha256（与 API token 一致，见 validateApiToken）：
     * cache 表只存哈希、不存明文，避免库表或备份泄露时会话令牌一并泄露。
     * 明文 token 只在登录响应里返回一次；此后校验、反查、吊销一律凭哈希。
     */
    public static function hashAdminToken(string $token): string
    {
        return \hash('sha256', $token);
    }

    /**
     * 验证 cache 中的随机 token
     *
     * @param string $token Bearer token
     * @return array{user: string, role: string}|null 验证成功返回用户信息，失败返回 null
     */
    public function validate(string $token): ?array
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT value FROM " . AppConfig::TABLE_CACHE
                . " WHERE cache_key = ? AND expires_at > ?"
            );
            $stmt->execute([AppConfig::CACHE_KEY_ADMIN_TOKEN_PREFIX . self::hashAdminToken($token), time()]);
            $cache = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($cache) {
                $parts = explode('|', $cache['value']);
                if (count($parts) < 2) {
                    return null;
                }
                $role = array_pop($parts);
                $user = implode('|', $parts);

                $state = $this->accountState($user);
                if (!$state['active']) {
                    return null;
                }

                $currentRole = $state['role'] ?? '';
                if ($currentRole === '') {
                    $currentRole = $role;
                }
                return [
                    'user' => $user,
                    'role' => $currentRole !== '' ? $currentRole : AppConfig::ROLE_ADMIN,
                ];
            }
        } catch (\Exception $e) {
            \App\Helper\Log::exception($e);
        }
        return null;
    }

    /**
     * 回查账号当前状态与角色。
     *
     * status 为 0 或查无此行 → active = false；查询异常 → active = true 且 role = null（不阻断已登录会话）。
     *
     * @return array{active: bool, role: ?string}
     */
    private function accountState(string $username): array
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT status, role FROM " . AppConfig::TABLE_ADMIN_USERS . " WHERE username = ?"
            );
            $stmt->execute([$username]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row === false) {
                return ['active' => false, 'role' => null];
            }
            $status = $row['status'] ?? null;
            $active = $status === null ? true : (int)$status !== 0;
            return [
                'active' => $active,
                'role'   => isset($row['role']) ? (string)$row['role'] : null,
            ];
        } catch (\Exception $e) {
            return ['active' => true, 'role' => null];
        }
    }

    /**
     * 验证 API token（服务账号 / 第三方调用）。
     * 明文 sha256 后查 api_tokens 表，校验 enabled + 未过期。
     *
     * @return array{user:string, token_name:string, token_id:?int, scopes:string[]}|null
     */
    public function validateApiToken(string $token): ?array
    {
        if ($this->apiTokenService === null) {
            return null;
        }
        try {
            $resolved = $this->apiTokenService->resolve(hash('sha256', $token));
            if ($resolved === null) {
                return null;
            }
            return [


                'user'       => $resolved['name'],
                'token_name' => $resolved['name'],
                'token_id'   => $resolved['id'],
                'scopes'     => $resolved['scopes'],
            ];
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * 验证旧版 base64 token（兼容历史 Swagger UI 客户端）
     *
     * 安全开关：默认禁用（ADMIN_LEGACY_TOKEN=false）。该格式 = base64(user:password)，
     * 无过期、无吊销、等价于明文凭证，是现代随机 token（sha256 入库）出现前的遗留旁路。
     * 现代随机 token 已覆盖文档访问，此兼容仅按需开启（在 .env 显式 ADMIN_LEGACY_TOKEN=true）。
     */
    public function validateLegacy(string $token): bool
    {
        if (!$this->config->isLegacyTokenEnabled()) {
            return false;
        }
        $cred = $this->config->getAdminCredentials();
        if (empty($cred['user']) || empty($cred['password'])) {
            return false;
        }
        $expected = \base64_encode($cred['user'] . ':' . $cred['password']);
        if (\function_exists('hash_equals')) {
            return \hash_equals($expected, $token);
        }
        return $expected === $token;
    }

    /**
     * 检查是否首次初始化场景（未设密码且无管理员用户则放行）
     */
    public function isFirstInit(): bool
    {
        $cred = $this->config->getAdminCredentials();
        if (!empty($cred['password'])) {
            return false;
        }
        try {
            $cnt = $this->pdo->query(
                "SELECT count(*) c FROM " . AppConfig::TABLE_ADMIN_USERS
            )->fetch()['c'];
            return $cnt == 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 查询角色对应的权限列表
     *
     * @return string[] 权限 key 数组
     */
    public function loadPermissions(string $role): array
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT rp.perm_key FROM " . AppConfig::TABLE_ROLE_PERMISSIONS . " rp"
                . " JOIN " . AppConfig::TABLE_ROLES . " r ON r.id = rp.role_id"
                . " WHERE r.name = ?"
            );
            $stmt->execute([$role]);
            return $stmt->fetchAll(\PDO::FETCH_COLUMN);
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * 删除 token（退出登录）
     */
    public function revoke(string $token): void
    {
        try {
            $this->pdo->prepare(
                "DELETE FROM " . AppConfig::TABLE_CACHE . " WHERE cache_key = ?"
            )->execute([AppConfig::CACHE_KEY_ADMIN_TOKEN_PREFIX . self::hashAdminToken($token)]);
        } catch (\Exception $e) {
        }
    }

    /** 登录成功后写 cache token（先清过期同前缀，再 upsert）。失败向上抛出。 */
    public function storeAdminToken(string $token, string $user, string $role): void
    {
        $this->pdo->prepare("DELETE FROM " . AppConfig::TABLE_CACHE . " WHERE cache_key LIKE ? AND expires_at <= ?")
            ->execute([AppConfig::CACHE_KEY_ADMIN_TOKEN_PREFIX . '%', time()]);
        $sql = Database::sqlUpsert(AppConfig::TABLE_CACHE, 'cache_key, value, expires_at', '?, ?, ?');
        $this->pdo->prepare($sql)->execute([AppConfig::CACHE_KEY_ADMIN_TOKEN_PREFIX . self::hashAdminToken($token), $user . '|' . $role, time() + AppConfig::TTL_TOKEN]);
    }

    /** 由明文 token 反查用户名（不含角色），无则空串。 */
    public function userFromCacheToken(string $token): string
    {
        try {
            $stmt = $this->pdo->prepare("SELECT value FROM " . AppConfig::TABLE_CACHE . " WHERE cache_key = ?");
            $stmt->execute([AppConfig::CACHE_KEY_ADMIN_TOKEN_PREFIX . self::hashAdminToken($token)]);
            $value = $stmt->fetchColumn();
            if ($value !== false && $value !== '') {
                $parts = explode('|', (string)$value);
                if (count($parts) >= 2) {
                    array_pop($parts);
                    return implode('|', $parts);
                }
            }
        } catch (\Throwable $e) {
        }
        return '';
    }
}
