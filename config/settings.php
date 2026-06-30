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
        'url'   => env('JENKINS_BASE_URL', 'http://192.168.137.5:8083'),
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
        'url'      => env('HARBOR_BASE_URL', 'http://192.168.137.5'),
        'username' => env('HARBOR_USER', 'admin'),
        'password' => env('HARBOR_PASSWORD'),
        'repo'     => env('HARBOR_REPO', '192.168.137.5'),
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
            'web_url'           => 'http://urs/tools/registry',
            'current_path'      => 'tools/registry',
            'harbor_repository' => 'mycode/code-runtime',
            'api_version'       => 'v4', //不填默认自动推导
        ],
        [
            'job_name'          => 'php/myapp',
            'project_id'        => 5,
            'web_url'           => 'http://urs/tools/myapp',
            'current_path'      => 'tools/myapp',
            'harbor_repository' => 'mycode/myapp',
            'api_version'       => 'v5',//不填默认自动推导
        ],
        [
            'job_name'          => 'static',
            'project_id'        => null,   // Gitee 项目 ID，若已知可填写
            'web_url'           => 'https://gitee.com/lucky-boy1/git_one_app',
            'current_path'      => 'lucky-boy1/git_one_app',
            'group_id'          => '',
            'owner'             => 'devops-team',
            'harbor_repository' => 'mycode/static-app',
            'api_version'       => 'v1',//不填默认自动推导
        ],
    ],
];