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
        private AppConfig $config,
        private ?ApiTokenService $apiTokenService = null
    ) {}

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
            $stmt->execute([AppConfig::CACHE_KEY_ADMIN_TOKEN_PREFIX . $token, time()]);
            $cache = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($cache) {
                // role 取最后一段，用户名即使含 '|' 也不会被解析成 role（防注入提权）
                $parts = explode('|', $cache['value']);
                if (count($parts) < 2) {
                    return null; // 缓存格式异常
                }
                $role = array_pop($parts);
                $user = implode('|', $parts);
                return [
                    'user' => $user,
                    'role' => $role !== '' ? $role : AppConfig::ROLE_ADMIN,
                ];
            }
        } catch (\Exception $e) {
        }
        return null;
    }

    /**
     * 验证 API token（服务账号 / 第三方调用）。
     * 明文 sha256 后查 api_tokens 表，校验 enabled + 未过期。
     *
     * @return array{user:string, scopes:string[]}|null
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
                'user'   => $resolved['name'],
                'scopes' => $resolved['scopes'],
            ];
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * 验证旧版 base64 token（兼容历史 Swagger UI 客户端）
     */
    public function validateLegacy(string $token): bool
    {
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
            )->execute([AppConfig::CACHE_KEY_ADMIN_TOKEN_PREFIX . $token]);
        } catch (\Exception $e) {
        }
    }
}
