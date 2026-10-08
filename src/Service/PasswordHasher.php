<?php

namespace App\Service;

/**
 * 密码哈希统一入口：集中控制算法与成本，供登录种子、建号、改密、离线重置复用，
 * 避免各调用点各自散写 password_hash(..., PASSWORD_BCRYPT) 导致算法无法统一升级。
 *
 * 算法由环境变量 PASSWORD_HASH_ALGO 选择（config/app.env / 根 .env 注入）：
 *   bcrypt    PASSWORD_BCRYPT，成本 12（默认；PHP 默认成本 10 已偏低，此处显式升到 12）
 *   argon2id  PASSWORD_ARGON2ID，内存 64 MiB / 时间 4 / 并行 1（抗 GPU 更强）
 *
 * password_verify() 依据哈希前缀自动识别算法，切换只影响「新写入」的哈希：
 * 存量 bcrypt 哈希照常可校验，无迁移成本。
 *
 * 默认仍用 bcrypt 的原因：argon2id 每次校验固定分配 64 MiB 内存，登录/改密并发时
 * 会抬高 php-fpm 内存水位；在确认容器内存预算充足前，保守默认 bcrypt。
 */
final class PasswordHasher
{
    public static function hash(string $plain): string
    {
        $algo = strtolower(trim((string)($_ENV['PASSWORD_HASH_ALGO'] ?? '')));
        if ($algo === 'argon2id' && defined('PASSWORD_ARGON2ID')) {
            return password_hash($plain, PASSWORD_ARGON2ID, [
                'memory_cost' => 65536, // 64 MiB
                'time_cost'   => 4,
                'threads'     => 1,
            ]);
        }
        return password_hash($plain, PASSWORD_BCRYPT, ['cost' => 12]);
    }
}
