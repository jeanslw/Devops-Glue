<?php

namespace App\Service;

use App\Support\EnvFileLoader;

/**
 * 敏感字段对称加密（AES-256-GCM，认证加密）。
 *
 * 用途：ci_platform_connections 的 token/username/password 与 ci_oauth_clients 的 secret
 * 写入时加密、读出时解密，使「只读数据库」不再等于「泄露密钥」。
 *
 * 边界（对称加密固有）：持有 DATA_ENC_KEY 即可解全部密文，无法防御「拿到应用密钥」。
 *
 * 密钥来源：显式配置 DATA_ENC_KEY（base64 编码的 32 字节），缺失即抛异常（fail-closed）。
 * 生成：php -r "echo base64_encode(random_bytes(32));"
 *
 * 密文格式：enc:v1:<base64(iv(12) || tag(16) || ciphertext)>，前缀 enc:v1: 标识已是密文。
 * 幂等 / 透明兼容：encrypt() 对空串、已带前缀值原样返回；decrypt() 对空串、无前缀值原样返回（兼容存量明文）。
 */
class Encryptor
{
    public const CIPHER = 'aes-256-gcm';
    public const PREFIX = 'enc:v1:';
    public const ENV_KEY = 'DATA_ENC_KEY';
    public const IV_LENGTH = 12;
    public const TAG_LENGTH = 16;
    public const KEY_LENGTH = 32;

    private ?string $key = null;

    /**
     * @param string|null $key 显式注入密钥（base64 编码的 32 字节，测试/显式场景用）；null 时懒加载从 env 读。
     */
    public function __construct(?string $key = null)
    {
        if ($key !== null) {
            $this->key = $this->normalizeKey($key);
        }
    }

    public function encrypt(string $plain): string
    {
        if ($plain === '' || str_starts_with($plain, self::PREFIX)) {
            return $plain;
        }
        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';
        $cipher = openssl_encrypt($plain, self::CIPHER, $this->rawKey(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new \RuntimeException('字段加密失败（openssl AES-256-GCM）');
        }
        return self::PREFIX . base64_encode($iv . $tag . $cipher);
    }

    public function decrypt(string $value): string
    {
        if ($value === '' || !str_starts_with($value, self::PREFIX)) {
            return $value;
        }
        $raw = base64_decode(substr($value, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) < self::IV_LENGTH + self::TAG_LENGTH) {
            throw new \RuntimeException('密文格式损坏（不是有效的 ' . self::PREFIX . ' 密文）');
        }
        $iv = substr($raw, 0, self::IV_LENGTH);
        $tag = substr($raw, self::IV_LENGTH, self::TAG_LENGTH);
        $cipher = substr($raw, self::IV_LENGTH + self::TAG_LENGTH);
        $plain = openssl_decrypt($cipher, self::CIPHER, $this->rawKey(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) {
            throw new \RuntimeException('字段解密失败（密钥不匹配或密文被篡改）');
        }
        return $plain;
    }

    /**
     * 解析 32 字节 raw key（懒加载缓存）。DATA_ENC_KEY 缺失即抛异常（fail-closed）。
     */
    public function rawKey(): string
    {
        if ($this->key !== null) {
            return $this->key;
        }
        $env = trim(EnvFileLoader::envVal(self::ENV_KEY, ''));
        if ($env === '') {
            throw new \RuntimeException(
                '未配置 ' . self::ENV_KEY . '：敏感字段加解密需要显式配置 32 字节 base64 密钥'
                . '（生成：php -r "echo base64_encode(random_bytes(32));"）'
            );
        }
        return $this->key = $this->normalizeKey($env);
    }

    private function normalizeKey(string $key): string
    {
        $raw = base64_decode($key, true);
        if ($raw === false || strlen($raw) !== self::KEY_LENGTH) {
            throw new \RuntimeException(
                self::ENV_KEY . ' 必须是 base64 编码的 32 字节密钥'
                . '（生成：php -r "echo base64_encode(random_bytes(32));"）'
            );
        }
        return $raw;
    }
}
