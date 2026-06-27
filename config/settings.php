<?php

// ✅ 1. 必须加保护，防止重复声明
if (!function_exists('env')) {
    /**
     * 安全读取环境变量，兼容 $_ENV / $_SERVER / getenv()
     */
    function env(string $key, string $default = ''): string
    {
        return $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key) ?: $default; 
    }
}

return [
    // ==================== Jenkins ====================
    'jenkins' => [
        // ✅ 2. 全部改用 env()，不再直接用 getenv()
        'url'   => env('JENKINS_BASE_URL', 'http://192.168.137.5:8083'),
        'user'  => env('JENKINS_USER', 'admin'),
        'token' => env('JENKINS_TOKEN'),
    ],

    // ==================== GitLab ====================
    'git' => [
        'base_url' => env('GIT_BASE_URL', 'http://192.168.137.5:8082'),
        'token'    => env('GIT_TOKEN'),
    ],

    // ==================== Harbor ====================
    'harbor' => [
        'url'      => env('HARBOR_BASE_URL', 'http://192.168.137.5'),
        'username' => env('HARBOR_USER', 'admin'),
        'password' => env('HARBOR_PASSWORD'),
        'repo'     => env('HARBOR_REPO', '192.168.137.5'),
    ],

    // ==================== App ====================
    'app' => [
        'env'           => env('APP_ENV', 'production'),
        // ✅ 布尔值和整数转换放在 env() 外部处理
        'debug'         => env('APP_DEBUG') === 'true',
        'build_timeout' => (int) env('BUILD_TIMEOUT', '300'),
        'log_path'      => env('LOG_PATH', '/data/logs/ci-platform/'),
    ],
];