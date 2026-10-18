<?php

namespace App\Service\Git;

/**
 * Git 平台托管类型（与 CI 能力正交，只影响配置 UI 的徽标与 URL 提示）：
 *   saas        —— 固定公网地址，用户只需填 token
 *   self-hosted —— 自定义地址，需填 base_url + token
 *
 * 判定依据是实际填写的 base_url 域名，而非平台名：命中 SAAS_HOSTS（含子域，如
 * api.github.com → github.com）为 saas，其余（自建域名 / 内网 IP / 空地址）为 self-hosted。
 */
final class HostingType
{
    public const SAAS        = 'saas';
    public const SELF_HOSTED = 'self-hosted';

    /** 已知 SaaS Git 平台的公网主机（host 或子域后缀匹配）。 */
    private const SAAS_HOSTS = [
        'github.com',
        'gitee.com',
        'gitlab.com',
        'bitbucket.org',
        'gitcode.com',
    ];

    public static function classify(string $url): string
    {
        $host = strtolower((string) parse_url(trim($url), PHP_URL_HOST));
        if ($host !== '') {
            foreach (self::SAAS_HOSTS as $saas) {
                if ($host === $saas || str_ends_with($host, '.' . $saas)) {
                    return self::SAAS;
                }
            }
        }
        return self::SELF_HOSTED;
    }
}
