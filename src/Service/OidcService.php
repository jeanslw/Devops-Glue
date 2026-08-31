<?php

namespace App\Service;

use Firebase\JWT\JWT;

/**
 * OIDC Provider 核心（在极简 OAuth2 之上补全 OIDC 能力）
 *
 * 职责：
 *  - RSA-2048 密钥对管理：优先读配置注入的私钥（PEM），否则从 key_file 读，
 *    都没有则自动生成并持久化到 key_file（保证跨重启 kid/JWKS 稳定）。
 *  - id_token 签发：RS256 + kid，claims 含 iss/sub/aud/exp/iat/nonce(有则)
 *    与 name/preferred_username/email/email_verified/groups。
 *  - OIDC Discovery 文档与 JWKS 发布（供 Jenkins oic-auth / Harbor OIDC / GitLab OmniAuth 消费）。
 *
 * 安全事实依据：
 *  - firebase/php-jwt v7 强制 RSA 密钥 ≥ 2048 位（CVE-2025-45769 修复），本服务固定生成 2048 位。
 *  - 私钥文件落盘后 chmod 0600 收紧权限；私钥从不进 discovery / jwks 输出。
 */
class OidcService
{
    public const SCOPE_OPENID  = 'openid';
    public const SCOPE_PROFILE = 'profile';
    public const SCOPE_EMAIL   = 'email';

    public const SCOPES_SUPPORTED = [self::SCOPE_OPENID, self::SCOPE_PROFILE, self::SCOPE_EMAIL];

    /** id_token 标准公开 claims（order 无意义，仅用于 discovery.claims_supported） */
    public const CLAIMS_SUPPORTED = [
        'iss', 'sub', 'aud', 'exp', 'iat', 'nonce',
        'name', 'preferred_username', 'email', 'email_verified', 'groups',
    ];

    private array $config;

    private ?\OpenSSLAsymmetricKey $privateKey = null;
    private ?string $privateKeyPem = null;
    private ?string $publicKeyPem = null;
    private ?string $keyId = null;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    // ─────────────────────────── 配置 ───────────────────────────

    /** 配置的 issuer（可能为空，由控制器在空时从请求推导） */
    public function issuer(): string
    {
        return rtrim(trim((string)($this->config['issuer'] ?? '')), '/');
    }

    // ─────────────────────────── 密钥管理 ───────────────────────────

    /** 私钥 PEM（懒加载：配置注入 → key_file → 自动生成持久化） */
    private function privateKeyPem(): string
    {
        if ($this->privateKeyPem !== null) {
            return $this->privateKeyPem;
        }

        $envPem = trim((string)($this->config['private_key'] ?? ''));
        if ($envPem !== '') {
            return $this->privateKeyPem = $envPem;
        }

        $file = (string)($this->config['key_file'] ?? '');
        if ($file !== '' && is_file($file)) {
            $pem = file_get_contents($file);
            if ($pem !== false && trim($pem) !== '') {
                return $this->privateKeyPem = $pem;
            }
        }

        return $this->privateKeyPem = $this->generateAndPersist($file);
    }

    private function generateAndPersist(string $file): string
    {
        if (!function_exists('openssl_pkey_new')) {
            throw new \RuntimeException('OIDC 需要 PHP openssl 扩展');
        }
        // Windows 下 openssl_pkey_new/export 依赖 openssl.cnf（否则报「configuration file routines::no such file」），
        // 需显式定位并传入；Linux 通常已内置默认 config，找不到也不影响。
        $cfg    = $this->opensslConfig();
        $genOpt = [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];
        $expOpt = [];
        if ($cfg !== null) {
            $genOpt['config'] = $cfg;
            $expOpt['config'] = $cfg;
        }

        $key = openssl_pkey_new($genOpt);
        if ($key === false) {
            throw new \RuntimeException('OIDC: RSA-2048 密钥生成失败');
        }
        if (!openssl_pkey_export($key, $pem, null, $expOpt)) {
            throw new \RuntimeException('OIDC: 私钥导出失败');
        }

        if ($file !== '') {
            $dir = dirname($file);
            if (!is_dir($dir)) {
                @mkdir($dir, 0777, true);
            }
            if (@file_put_contents($file, $pem, LOCK_EX) !== false) {
                @chmod($file, 0600);
            }
        }
        return $pem;
    }

    /**
     * 定位 openssl.cnf（仅 openssl_pkey_new/export 生成密钥时需要）。
     * Windows 上 PHP 常找不到默认 config 导致密钥生成失败，这里按常见位置兜底。
     */
    private function opensslConfig(): ?string
    {
        $env = getenv('OPENSSL_CONF');
        if (is_string($env) && $env !== '' && is_file($env)) {
            return $env;
        }
        if (!function_exists('openssl_get_cert_locations')) {
            return null;
        }

        $candidates = [];
        $loc = openssl_get_cert_locations();
        if (!empty($loc['default_config_file'])) {
            $candidates[] = (string)$loc['default_config_file'];
        }
        if (defined('PHP_BINARY')) {
            $binDir = dirname((string)PHP_BINARY);
            $candidates[] = $binDir . '/extras/ssl/openssl.cnf'; // Laragon/XAMPP
            $candidates[] = $binDir . '/openssl.cnf';
        }
        if (defined('PHP_BINDIR') && PHP_BINDIR !== '') {
            $candidates[] = PHP_BINDIR . '/extras/ssl/openssl.cnf';
            $candidates[] = PHP_BINDIR . '/openssl.cnf';
        }
        $candidates[] = 'C:/Program Files/Common Files/SSL/openssl.cnf';
        $candidates[] = 'C:/Program Files/Git/mingw64/ssl/openssl.cnf';
        $candidates[] = '/etc/ssl/openssl.cnf';
        $candidates[] = '/usr/lib/ssl/openssl.cnf';

        foreach ($candidates as $c) {
            if (is_string($c) && $c !== '' && is_file($c)) {
                return $c;
            }
        }
        return null;
    }

    private function privateKey(): \OpenSSLAsymmetricKey
    {
        if ($this->privateKey === null) {
            $key = openssl_pkey_get_private($this->privateKeyPem());
            if ($key === false) {
                throw new \RuntimeException('OIDC: 私钥解析失败');
            }
            $this->privateKey = $key;
        }
        return $this->privateKey;
    }

    /** 公钥 PEM（从私钥派生，供测试/调试；JWKS 用 n/e 而非此 PEM） */
    public function publicKeyPem(): string
    {
        if ($this->publicKeyPem === null) {
            $details = openssl_pkey_get_details($this->privateKey());
            if ($details === false || empty($details['key'])) {
                throw new \RuntimeException('OIDC: 公钥提取失败');
            }
            $this->publicKeyPem = $details['key'];
        }
        return $this->publicKeyPem;
    }

    /** 稳定 kid（由公钥派生，私钥持久化后跨重启不变） */
    public function keyId(): string
    {
        if ($this->keyId === null) {
            $this->keyId = 'oidc-rsa-' . substr(hash('sha256', $this->publicKeyPem()), 0, 16);
        }
        return $this->keyId;
    }

    // ─────────────────────────── id_token ───────────────────────────

    /**
     * 签发 RS256 id_token。
     *
     * @param array<string,mixed> $claims   须含 iss、sub；可选 name/preferred_username/email/email_verified/groups
     * @param string              $clientId aud（客户端 id）
     * @param string|null         $nonce    有则回显进 id_token（防重放）
     */
    public function signIdToken(array $claims, string $clientId, ?string $nonce = null): string
    {
        $now = time();
        $payload = [
            'iss' => (string)($claims['iss'] ?? ''),
            'sub' => (string)($claims['sub'] ?? ''),
            'aud' => $clientId,
            'iat' => $now,
            'exp' => $now + (int)($this->config['id_token_ttl'] ?? 3600),
        ];
        if ($nonce !== null && $nonce !== '') {
            $payload['nonce'] = $nonce;
        }
        foreach (['name', 'preferred_username', 'email'] as $k) {
            if (array_key_exists($k, $claims)) {
                $payload[$k] = $claims[$k];
            }
        }
        if (array_key_exists('email_verified', $claims)) {
            $payload['email_verified'] = (bool)$claims['email_verified'];
        }
        if (array_key_exists('groups', $claims)) {
            $payload['groups'] = $claims['groups'];
        }

        return JWT::encode($payload, $this->privateKeyPem(), 'RS256', $this->keyId());
    }

    // ─────────────────────────── discovery / jwks ───────────────────────────

    /** OIDC Discovery 文档（RFC 8414 / OIDC Discovery 1.0） */
    public function discovery(string $issuer): array
    {
        $issuer = rtrim($issuer, '/');
        return [
            'issuer'                                => $issuer,
            'authorization_endpoint'                => $issuer . '/oauth/authorize',
            'token_endpoint'                        => $issuer . '/oauth/token',
            'userinfo_endpoint'                     => $issuer . '/oauth/userinfo',
            'jwks_uri'                              => $issuer . '/.well-known/jwks.json',
            'response_types_supported'              => ['code'],
            'subject_types_supported'               => ['public'],
            'id_token_signing_alg_values_supported' => ['RS256'],
            'scopes_supported'                      => self::SCOPES_SUPPORTED,
            'token_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post'],
            'claims_supported'                      => self::CLAIMS_SUPPORTED,
        ];
    }

    /** JWKS：只发布公钥（n/e），绝不泄露私钥 */
    public function jwks(): array
    {
        $details = openssl_pkey_get_details($this->privateKey());
        if ($details === false || empty($details['rsa']['n']) || empty($details['rsa']['e'])) {
            throw new \RuntimeException('OIDC: RSA 公钥参数提取失败');
        }

        return [
            'keys' => [[
                'kty' => 'RSA',
                'use' => 'sig',
                'alg' => 'RS256',
                'kid' => $this->keyId(),
                'n'   => $this->base64url($this->rsaParamToBin((string)$details['rsa']['n'])),
                'e'   => $this->base64url($this->rsaParamToBin((string)$details['rsa']['e'])),
            ]],
        ];
    }

    // ─────────────────────────── 角色 → groups ───────────────────────────

    /** Glue 角色映射为 OIDC groups claim（各系统自行把 group 映射到本地角色） */
    public function groupsFromRole(string $role): array
    {
        $role = trim($role);
        return $role !== '' ? [$role] : [];
    }

    // ─────────────────────────── 内部工具 ───────────────────────────

    /** hex → 二进制（奇长时前置 0 保证成对） */
    private function hexToBin(string $hex): string
    {
        if (strlen($hex) % 2 !== 0) {
            $hex = '0' . $hex;
        }
        $bin = hex2bin($hex);
        return $bin === false ? '' : $bin;
    }

    /**
     * openssl_pkey_get_details 的 rsa.n/e：老版本 PHP 返回 lowercase hex，
     * PHP 8.x + OpenSSL 3 返回二进制。用 ctype_xdigit 区分（二进制含非 hex 字节，hex 全为 0-9a-f），
     * 统一转成二进制供 base64url 编码 JWK。
     */
    private function rsaParamToBin(string $value): string
    {
        return ctype_xdigit($value) ? $this->hexToBin($value) : $value;
    }

    /** 标准 base64 → base64url（URL 安全、去 padding），供 JWK n/e 编码 */
    private function base64url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }
}
