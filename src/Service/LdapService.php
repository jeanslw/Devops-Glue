<?php
namespace App\Service;

use App\Config\AppConfig;

/**
 * 基于 PHP ext-ldap 的 LDAP 认证客户端。
 *
 * 流程（管理员搜索模式）：
 *   1) ldap_connect(host, port)；可选 STARTTLS
 *   2) ldap_bind(bind_dn, bind_password) 以管理员身份连接
 *   3) ldap_search(base_dn, user_filter 替换 %s 为 username) 取用户 DN + 属性
 *   4) ldap_bind(用户DN, 用户密码) 校验密码
 *
 * 流程（直连模式，无管理员绑定）：
 *   设置 user_dn_pattern（如 uid=%s,ou=users,dc=example,dc=com），跳过搜索直接 bind
 *
 * 依赖：
 *   - php.ini 必须启用 extension=ldap，否则 isAvailable() 返回 false，
 *     调用方需静默降级（此时 LDAP 登录不可用，不影响本地 DB 登录）
 */
class LdapService
{
    public function __construct(private AppConfig $config) {}

    /** extension=ldap 是否加载（未加载时 LDAP 登录不可用，本地登录不受影响） */
    public function isAvailable(): bool
    {
        return extension_loaded('ldap') && function_exists('ldap_connect');
    }

    /** 配置 enabled + 扩展可用才算启用 */
    public function isEnabled(): bool
    {
        $cfg = $this->config->getLdapConfig();
        return ($cfg['enabled'] ?? false) && $this->isAvailable();
    }

    /**
     * 用用户名 + 密码去 LDAP 做一次完整的绑定认证。
     * @return array{ok:bool, dn?:string, attrs?:array, error?:string}
     */
    public function authenticate(string $username, string $password): array
    {
        if ($username === '' || $password === '') {
            return ['ok' => false, 'error' => 'empty_credentials'];
        }
        if (!$this->isAvailable()) {
            return ['ok' => false, 'error' => 'ldap_extension_missing'];
        }

        $cfg = $this->config->getLdapConfig();
        if (!($cfg['enabled'] ?? false)) {
            return ['ok' => false, 'error' => 'ldap_disabled'];
        }
        if (empty($cfg['host'])) {
            return ['ok' => false, 'error' => 'ldap_host_missing'];
        }

        $resource = $this->connect($cfg);
        if ($resource === null) {
            return ['ok' => false, 'error' => 'ldap_connect_failed'];
        }
        try {
            // 直连模式：已有 DN 模板，跳过搜索
            $dnPattern = (string)($cfg['user_dn_pattern'] ?? '');
            if ($dnPattern !== '') {
                $dn = sprintf($dnPattern, $this->escapeDn($username));
                if (!$this->bind($resource, $dn, $password)) {
                    return ['ok' => false, 'error' => 'ldap_bind_failed'];
                }
                // 拉一次属性用于缓存（可选：失败不阻断登录）
                $attrs = $this->readAttributes($resource, $dn, $cfg);
                return ['ok' => true, 'dn' => $dn, 'attrs' => $attrs];
            }

            // 管理员搜索模式：先 bind 管理员，再搜用户 DN
            if (empty($cfg['bind_dn']) || empty($cfg['base_dn']) || empty($cfg['user_filter'])) {
                return ['ok' => false, 'error' => 'ldap_search_config_missing'];
            }
            if (!$this->bind($resource, (string)$cfg['bind_dn'], (string)$cfg['bind_password'])) {
                return ['ok' => false, 'error' => 'ldap_admin_bind_failed'];
            }

            $filter = sprintf((string)$cfg['user_filter'], $this->escapeFilter($username));
            $attrsToRead = is_array($cfg['attrs'] ?? null) ? $cfg['attrs'] : ['uid', 'cn', 'mail', 'dn'];
            $search = @ldap_search($resource, (string)$cfg['base_dn'], $filter, $attrsToRead);
            if ($search === false) {
                return ['ok' => false, 'error' => 'ldap_search_failed'];
            }
            $entries = ldap_get_entries($resource, $search);
            if ($entries === false || (int)($entries['count'] ?? 0) === 0) {
                return ['ok' => false, 'error' => 'ldap_user_not_found'];
            }

            $userDn = (string)$entries[0]['dn'];
            $attrs  = $this->normalizeAttrs($entries[0]);

            // 用用户 DN + 密码替换连接身份，做最终认证
            if (!$this->bind($resource, $userDn, $password)) {
                return ['ok' => false, 'error' => 'ldap_bind_failed'];
            }
            return ['ok' => true, 'dn' => $userDn, 'attrs' => $attrs];
        } finally {
            @ldap_unbind($resource);
        }
    }

    /** 建立连接并按需 STARTTLS，失败返回 null */
    private function connect(array $cfg)
    {
        $useLdaps = (bool)($cfg['use_ldaps'] ?? false);
        $host     = (string)$cfg['host'];
        $port     = (int)($cfg['port'] ?? ($useLdaps ? 636 : 389));
        $uri      = $useLdaps ? "ldaps://{$host}:{$port}" : "ldap://{$host}:{$port}";

        $resource = @ldap_connect($uri);
        if ($resource === false || $resource === null) {
            return null;
        }
        ldap_set_option($resource, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($resource, LDAP_OPT_REFERRALS, 0);
        $timeout = (int)($cfg['network_timeout'] ?? 5);
        if ($timeout > 0) {
            ldap_set_option($resource, LDAP_OPT_NETWORK_TIMEOUT, $timeout);
        }

        if (!$useLdaps && (bool)($cfg['use_tls'] ?? false)) {
            if (!@ldap_start_tls($resource)) {
                @ldap_unbind($resource);
                return null;
            }
        }
        return $resource;
    }

    /** 包装 ldap_bind，避免上层直接看到 warning */
    private function bind($resource, string $dn, string $password): bool
    {
        if ($dn === '' || $password === '') {
            return false;
        }
        return @ldap_bind($resource, $dn, $password);
    }

    /** 从已 bind 的连接读属性（管理员搜索模式下已读到一次 entries，直接复用规范化的结果） */
    private function readAttributes($resource, string $dn, array $cfg): array
    {
        $attrsToRead = is_array($cfg['attrs'] ?? null) ? $cfg['attrs'] : ['uid', 'cn', 'mail'];
        $search = @ldap_read($resource, $dn, '(objectClass=*)', $attrsToRead);
        if ($search === false) {
            return [];
        }
        $entries = ldap_get_entries($resource, $search);
        return $entries === false ? [] : $this->normalizeAttrs($entries[0]);
    }

    /** 把 LDAP entries 的"数值索引 + 命名索引混合"扁平化为纯关联数组 */
    private function normalizeAttrs(array $entry): array
    {
        $attrs = [];
        $count = (int)($entry['count'] ?? 0);
        for ($i = 0; $i < $count; $i++) {
            $name = $entry[$i] ?? '';
            if ($name === '' || !isset($entry[$name])) {
                continue;
            }
            $raw = $entry[$name];
            if (is_array($raw)) {
                unset($raw['count']);
                $attrs[$name] = count($raw) === 1 ? (string)$raw[0] : $raw;
            } else {
                $attrs[$name] = (string)$raw;
            }
        }
        return $attrs;
    }

    /** 防 LDAP filter 注入：搜 UID 时把 * ( ) \ NUL 转义（public static 便于无 LDAP 环境单测） */
    public static function escapeFilter(string $value): string
    {
        $map = ['\\' => '\\5c', '*' => '\\2a', '(' => '\\28', ')' => '\\29', "\x00" => '\\00'];
        return strtr($value, $map);
    }

    /** 防 DN 注入：DN 拼接时把 , = + < > # ; \ " 转义（public static 便于无 LDAP 环境单测） */
    public static function escapeDn(string $value): string
    {
        $map = [
            ',' => '\\2c', '=' => '\\3d', '+' => '\\2b', '<' => '\\3c', '>' => '\\3e',
            '#' => '\\23', ';' => '\\3b', '\\' => '\\5c', '"' => '\\22',
        ];
        return strtr($value, $map);
    }
}
