<?php

namespace App\Service;

use App\Config\AppConfig;

/**
 * 极简 OAuth2 Provider（授权码流程）
 *
 * 供 Grafana Generic OAuth 等外部系统使用 Devops-Glue 账号登录。
 * 授权码：一次性、60 秒过期、绑定 client_id + redirect_uri。
 * 访问令牌：存 cache 表，默认 1 小时过期。
 * 客户端白名单：settings.php 的 oauth_clients 配置。
 */
class OAuthService
{
    private const CODE_PREFIX        = 'oauth_code_';
    private const ACCESS_PREFIX      = 'oauth_at_';
    private const CODE_TTL           = 60;      // 授权码有效期（秒）
    private const ACCESS_TOKEN_TTL   = 3600;    // 访问令牌有效期（秒）

    private \PDO $pdo;
    private array $clients;

    public function __construct(\PDO $pdo, array $clients = [])
    {
        $this->pdo = $pdo;
        // fail-closed：secret 为空/纯空白的客户端视为未配置，直接剔除。
        // 否则「忘了设 GRAFANA_OAUTH_SECRET、用空默认」时，空 secret 与空输入 hash_equals 相等，
        // token 端点可被空 secret 绕过——等价于公开默认密钥的带病运行。
        $this->clients = array_filter(
            $clients,
            fn($c) => is_array($c) && trim((string)($c['secret'] ?? '')) !== ''
        );
    }

    /**
     * 校验客户端：client_id 存在且（如提供）redirect_uri 匹配
     */
    public function validateClient(string $clientId, ?string $redirectUri = null): bool
    {
        $client = $this->clients[$clientId] ?? null;
        if ($client === null) {
            return false;
        }
        if ($redirectUri !== null) {
            $allowed = $client['redirect_uri'] ?? '';
            if (!hash_equals((string)$allowed, $redirectUri)) {
                return false;
            }
        }
        return true;
    }

    /**
     * 校验客户端密钥（token 端点用）
     */
    public function validateClientSecret(string $clientId, string $clientSecret): bool
    {
        $client = $this->clients[$clientId] ?? null;
        if ($client === null) {
            return false;
        }
        return hash_equals((string)($client['secret'] ?? ''), $clientSecret);
    }

    /**
     * 获取客户端配置的 redirect_uri
     */
    public function getClientRedirectUri(string $clientId): ?string
    {
        return $this->clients[$clientId]['redirect_uri'] ?? null;
    }

    /**
     * 签发授权码（认证成功后调用）
     *
     * scope/nonce 为 OIDC 扩展字段：随授权码透传到 token 端点，
     * 决定是否签发 id_token 以及 nonce 回显。默认空值保持纯 OAuth2 向后兼容。
     */
    public function issueCode(string $clientId, string $redirectUri, string $username, string $role, string $scope = '', ?string $nonce = null): string
    {
        $code = bin2hex(random_bytes(32));
        $value = json_encode([
            'client_id'    => $clientId,
            'redirect_uri' => $redirectUri,
            'user'         => $username,
            'role'         => $role,
            'scope'        => $scope,
            'nonce'        => $nonce,
        ]);
        $sql = Database::sqlUpsert(AppConfig::TABLE_CACHE, 'cache_key, value, expires_at', '?, ?, ?');
        $this->pdo->prepare($sql)->execute([
            self::CODE_PREFIX . $code,
            $value,
            time() + self::CODE_TTL,
        ]);
        return $code;
    }

    /**
     * 消费授权码（一次性）：成功返回码内数据，失败返回 null
     */
    public function consumeCode(string $code, string $clientId, string $redirectUri): ?array
    {
        $key = self::CODE_PREFIX . $code;
        try {
            $stmt = $this->pdo->prepare(
                "SELECT value FROM " . AppConfig::TABLE_CACHE
                . " WHERE cache_key = ? AND expires_at > ?"
            );
            $stmt->execute([$key, time()]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$row) {
                return null;
            }
            // 一次性：无论后续校验是否通过都先删除，防重放
            $this->pdo->prepare(
                "DELETE FROM " . AppConfig::TABLE_CACHE . " WHERE cache_key = ?"
            )->execute([$key]);

            $data = json_decode($row['value'], true);
            if (!is_array($data)) {
                return null;
            }
            // 授权码绑定 client_id + redirect_uri
            if (
                ($data['client_id'] ?? '') !== $clientId
                || ($data['redirect_uri'] ?? '') !== $redirectUri
            ) {
                return null;
            }
            return $data;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 签发访问令牌
     */
    public function issueAccessToken(string $username, string $role, string $clientId): string
    {
        $token = bin2hex(random_bytes(32));
        $value = json_encode([
            'user'      => $username,
            'role'      => $role,
            'client_id' => $clientId,
        ]);
        $sql = Database::sqlUpsert(AppConfig::TABLE_CACHE, 'cache_key, value, expires_at', '?, ?, ?');
        $this->pdo->prepare($sql)->execute([
            self::ACCESS_PREFIX . $token,
            $value,
            time() + self::ACCESS_TOKEN_TTL,
        ]);
        return $token;
    }

    /**
     * 校验访问令牌，成功返回用户信息，失败返回 null
     */
    public function validateAccessToken(string $token): ?array
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT value FROM " . AppConfig::TABLE_CACHE
                . " WHERE cache_key = ? AND expires_at > ?"
            );
            $stmt->execute([self::ACCESS_PREFIX . $token, time()]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$row) {
                return null;
            }
            $data = json_decode($row['value'], true);
            return is_array($data) ? $data : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
