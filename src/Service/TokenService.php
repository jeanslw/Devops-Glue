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
     * 登录 token 入库前统一 sha256（与 API token 一致，见 validateApiToken）：
     * cache 表只存哈希、不存明文，杜绝「库表/备份泄露即会话令牌泄露」。
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
                // role 取最后一段，用户名即使含 '|' 也不会被解析成 role（防注入提权）
                $parts = explode('|', $cache['value']);
                if (count($parts) < 2) {
                    return null; // 缓存格式异常
                }
                $role = array_pop($parts);
                $user = implode('|', $parts);
                // 停用/删除账号即时踢下线：token 命中缓存后仍要回查账号状态，
                // 否则管理员停用某账号后，其尚未过期的旧 token 依然能继续调接口。
                if (!$this->isAccountActive($user)) {
                    return null;
                }
                return [
                    'user' => $user,
                    'role' => $role !== '' ? $role : AppConfig::ROLE_ADMIN,
                ];
            }
        } catch (\Exception $e) {
            \App\Helper\Log::exception($e);
        }
        return null;
    }

    /**
     * 回查账号是否仍有效（未停用、未删除）。
     *
     * - status 明确为 0（停用）→ false，token 立即失效（踢下线）。
     * - 查无此行（账号已被删除）→ false，同样失效。
     * - 查询抛异常（DB 不可达 / 旧库无 status 列）→ true 放行：
     *   状态回查失败不阻断已登录 token 的校验（fail-open 保可用）。
     */
    private function isAccountActive(string $username): bool
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT status FROM " . AppConfig::TABLE_ADMIN_USERS . " WHERE username = ?"
            );
            $stmt->execute([$username]);
            $status = $stmt->fetchColumn();
            if ($status === false) {
                return false; // 无此行 = 账号已删除
            }
            return $status === null ? true : (int)$status !== 0;
        } catch (\Exception $e) {
            return true;
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
                // user/token_name 都是 token 的展示名（api_tokens.name，非登录账号），
                // 供调用方归因与审计；token 原文/hash 绝不进入返回值。
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
}
