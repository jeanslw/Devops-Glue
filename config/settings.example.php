<?php
// config/settings.example.php
return [
    'jenkins' => [
        'url'   => env('JENKINS_BASE_URL', 'http://your-jenkins'),
        'user'  => env('JENKINS_USER', 'admin'),
        'token' => env('JENKINS_TOKEN', ''),
    ],
    'git' => [
        'gitlab' => [
            'base_url' => env('GITLAB_BASE_URL', 'http://your-gitlab'),
            'token'    => env('GITLAB_TOKEN', ''),
        ],
        'gitee' => [
            'base_url' => env('GITEE_BASE_URL', 'https://gitee.com/api/v5'),
            'token'    => env('GITEE_TOKEN', ''),
        ],
        'github' => [
            'base_url' => env('GITHUB_BASE_URL', 'https://api.github.com'),
            'token'    => env('GITHUB_TOKEN', ''),
        ],
    ],
    'harbor' => [
        'url'      => env('HARBOR_BASE_URL', 'http://your-harbor'),
        'username' => env('HARBOR_USER', 'admin'),
        'password' => env('HARBOR_PASSWORD', ''),
    ],
    'app' => [
        'env'           => env('APP_ENV', 'production'),
        'debug'         => env('APP_DEBUG') === 'true',
        'build_timeout' => (int) env('BUILD_TIMEOUT', '300'),
        'log_path'      => env('LOG_PATH', '/data/logs/ci-platform/'),
    ],
    'job_git_map' => [],  // 清空或只留示例
];