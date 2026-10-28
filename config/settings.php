<?php

if (!function_exists('env')) {
    function env(string $key, string $default = ''): string
    {
        return $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key) ?: $default;
    }
}

return [
    'git' => [
        'default_platform' => env('DEFAULT_GIT_PLATFORM', 'gitlab'),
        'gitlab' => [
            'base_url' => '',
            'token'    => '',
        ],
        'github' => [
            'base_url' => 'https://api.github.com',
            'token'    => '',
        ],
        'gitee' => [
            'base_url' => 'https://gitee.com/api/v5',
            'token'    => '',
        ],
        'gitea' => [
            'base_url' => '',
            'token'    => '',
        ],
        'custom_providers' => [],
    ],

    'cd' => [
        'base_url'  => env('CD_BASE_URL', ''),
        'api_token' => env('CD_API_TOKEN', ''),
    ],

    'build' => [
        'custom_providers' => [
            [
                'name'   => 'custom_push',
                'class'  => 'App\\Service\\Build\\CustomPushBuildProvider',
                'config' => [
                    'variables' => [
                        'env' => ['type' => 'choice', 'choices' => ['dev', 'staging', 'prod'], 'description' => '构建镜像目标环境（可选）', 'required' => false],
                    ],
                    'log_url_allowed_hosts' => array_values(array_filter(array_map('trim', explode(',', env('CUSTOM_PUSH_LOG_ALLOWED_HOSTS', ''))))),
                ],
            ],
        ],
    ],

    'admin' => [
        'user'     => env('ADMIN_USER', 'root'),
        'password' => env('ADMIN_PASSWORD', ''),
        'legacy_token' => env('ADMIN_LEGACY_TOKEN', 'false') === 'true',
    ],

    'ldap' => [
        'enabled'         => env('LDAP_ENABLED', 'false') === 'true',
        'host'            => env('LDAP_HOST', ''),
        'port'            => (int) env('LDAP_PORT', '389'),
        'use_tls'         => env('LDAP_USE_TLS', 'false') === 'true',
        'use_ldaps'       => env('LDAP_USES_LDAPS', 'false') === 'true',
        'base_dn'         => env('LDAP_BASE_DN', ''),
        'bind_dn'         => env('LDAP_BIND_DN', ''),
        'bind_password'   => env('LDAP_BIND_PASSWORD', ''),
        'user_filter'     => env('LDAP_USER_FILTER', '(uid=%s)'),
        'attrs'           => array_values(array_filter(array_map('trim', explode(',', env('LDAP_ATTRS', 'uid,cn,mail,dn'))))),
        'user_dn_pattern' => env('LDAP_USER_DN_PATTERN', ''),
        'network_timeout' => (int) env('LDAP_NETWORK_TIMEOUT', '5'),
    ],

    'cors' => [
        'allowed_origins' => env('CORS_ORIGIN', '*') === '*'
            ? ['*']
            : array_map('trim', explode(',', env('CORS_ORIGIN', '*'))),
    ],

    'app' => [
        'env'           => env('APP_ENV', 'production'),
        'debug'         => env('APP_DEBUG') === 'true',
        'log_path'      => env('LOG_PATH', '/data/logs/ci-platform/'),
        'log_retain_days' => max(0, (int) env('LOG_RETAIN_DAYS', '30')),
        'api_base_url'  => env('API_BASE_URL', ''),
        'system_type'   => env('SYSTEM_TYPE', 'ci'),
        'trusted_proxy_hops' => max(0, (int) env('TRUSTED_PROXY_HOPS', '0')),
    ],

    'oauth_clients' => [],

    'oidc' => [
        'private_key' => env('OIDC_RSA_PRIVATE_KEY', ''),
        'key_file'    => env('OIDC_KEY_FILE', __DIR__ . '/data/oidc_rsa.pem'),
    ],
];
