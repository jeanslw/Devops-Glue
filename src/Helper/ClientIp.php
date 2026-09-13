<?php

namespace App\Helper;

/**
 * 真实客户端 IP 解析。
 *
 * 必须与 Devops_CD backend/login_guard.py::client_ip 的语义保持一致
 * （两边共享同一张 admin_users / cache 表与登录锁定逻辑）：
 *
 *  - trustedHops=0（默认，安全默认）：只信 REMOTE_ADDR，完全忽略 X-Forwarded-For，
 *    防止直连客户端伪造 XFF 绕过登录失败锁定；
 *  - trustedHops>0 且 TCP 对端是回环/私网/链路本地地址（说明确实隔着自家反代）时，
 *    从 X-Forwarded-For **右端**取倒数第 hops 个地址：
 *    反代会把真实客户端追加在链尾，攻击者自行加在链首的假地址取不到；
 *  - 任一步不合法（对端不是内网、XFF 缺失/过短/含非法 IP）都回退 REMOTE_ADDR。
 */
class ClientIp
{
    public static function resolve(array $serverParams, int $trustedHops = 0): string
    {
        $remote = trim((string) ($serverParams['REMOTE_ADDR'] ?? ''));
        if ($remote === '') {
            return 'unknown';
        }
        if ($trustedHops <= 0 || !self::isLocalPeer($remote)) {
            return $remote;
        }

        $xff = trim((string) ($serverParams['HTTP_X_FORWARDED_FOR'] ?? ''));
        if ($xff === '') {
            return $remote;
        }
        $parts = array_values(array_filter(
            array_map('trim', explode(',', $xff)),
            static fn($v) => $v !== ''
        ));
        if (count($parts) < $trustedHops) {
            return $remote;
        }

        $candidate = $parts[count($parts) - $trustedHops];
        // 剥离可能的 IPv6 zone id（如 fe80::1%eth0）后再校验
        $zone = strpos($candidate, '%');
        if ($zone !== false) {
            $candidate = substr($candidate, 0, $zone);
        }
        return filter_var($candidate, FILTER_VALIDATE_IP) !== false ? $candidate : $remote;
    }

    /**
     * 对端是否为可放置可信反代的地址：回环 / RFC1918 私网 / 链路本地 / IPv6 唯一本地等。
     * 实现方式：合法 IP 若同时命中“私网段 + 保留段”过滤则 filter 失败，即视为内网地址。
     */
    private static function isLocalPeer(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false
            && filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) === false;
    }
}
