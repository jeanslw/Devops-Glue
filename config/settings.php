<?php

if (!function_exists('env')) {
    function env(string $key, string $default = ''): string
    {
        return $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key) ?: $default;
    }
}

return [
    // ==================== Jenkins ====================
    'jenkins' => [
        'url'   => env('JENKINS_BASE_URL', 'http://Jenkins_URL'),
        'user'  => env('JENKINS_USER', 'admin'),
        'token' => env('JENKINS_TOKEN'),
    ],

    // ==================== Git 多平台配置 ====================
    'git' => [
        // ----- GitLab -----
        'gitlab' => [
            'base_url' => env('GITLAB_BASE_URL', env('GIT_BASE_URL', '')),
            'token'    => env('GITLAB_TOKEN', env('GIT_TOKEN', '')),
        ],
        // ----- GitHub -----
        'github' => [
            'base_url' => env('GITHUB_BASE_URL', 'https://api.github.com'),
            'token'    => env('GITHUB_TOKEN', ''),
        ],
        // ----- Gitee -----
        'gitee' => [
            'base_url' => env('GITEE_BASE_URL', 'https://gitee.com/api/v5'),
            'token'    => env('GITEE_TOKEN', ''),
        ],
    ],

    // ==================== Harbor ====================
    'harbor' => [
        'url'      => env('HARBOR_BASE_URL', 'http://Harbor_URL'),
        'username' => env('HARBOR_USER', 'admin'),
        'password' => env('HARBOR_PASSWORD'),
        'repo'     => env('HARBOR_REPO', 'Harbor_URL'),
    ],

    // ==================== App ====================
    'app' => [
        'env'           => env('APP_ENV', 'production'),
        'debug'         => env('APP_DEBUG') === 'true',
        'build_timeout' => (int) env('BUILD_TIMEOUT', '300'),
        'log_path'      => env('LOG_PATH', '/data/logs/ci-platform/'),
    ],

    'job_git_map' => [
        [
            'job_name'          => 'java/registry',
            'project_id'        => 2,
            'harbor_repository' => 'mycode/code-runtime',
            'api_version'       => 'v4', //不填默认自动推导
        ],
        [
            'job_name'          => 'php/myapp',
            'project_id'        => 5,
            'harbor_repository' => 'mycode/myapp',
            'api_version'       => 'v5',//不填默认自动推导
        ],
        [
            'job_name'          => 'static',
            'project_id'        => null,   // Gitee 项目 ID，若已知可填写
            'harbor_repository' => 'mycode/static-app',
            'api_version'       => 'v1',//不填默认自动推导
        ],
    ],
];