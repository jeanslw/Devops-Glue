<?php
// config/settings.example.php
return [
    'jenkins' => [
        'url'   => env('JENKINS_BASE_URL', 'http://your-jenkins'),
        'user'  => env('JENKINS_USER', 'admin'),
        'token' => env('JENKINS_TOKEN', ''),
    ],
    'git' => [
        'default_platform' => env('DEFAULT_GIT_PLATFORM', 'gitlab'),

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
        'gitea' => [
            'base_url' => env('GITEA_BASE_URL', ''),
            'token'    => env('GITEA_TOKEN', ''),
        ],
        'custom_providers' => [
            // 示例：自定义 Git 平台（凭证通过 env() 从 .env 读取）
            // [
            //     'class'  => 'App\\Service\\Git\\BitbucketService',
            //     'config' => [
            //         'name'     => 'bitbucket',
            //         'base_url' => 'https://api.bitbucket.org/2.0',
            //         'token'    => env('BITBUCKET_TOKEN', ''),
            //         'matcher'  => function (string $url): bool {
            //             return str_contains($url, 'bitbucket');
            //         },
            //     ],
            // ],
        ],
    ],
    'harbor' => [
        'url'      => env('HARBOR_BASE_URL', ''),
        'username' => env('HARBOR_USER', 'admin'),
        'password' => env('HARBOR_PASSWORD', ''),
    ],
    'app' => [
        'env'           => env('APP_ENV', 'production'),
        'debug'         => env('APP_DEBUG') === 'true',
        'build_timeout' => (int) env('BUILD_TIMEOUT', '300'),
        'log_path'      => env('LOG_PATH', '/data/logs/ci-platform/'),
    ],
    'cors' => [
        'allowed_origins' => ['*'],                     // 允许的域名，* 表示全部
        'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS'],
        'allowed_headers' => ['Content-Type', 'Authorization', 'X-Requested-With', 'Accept'],
    ],
    /*
     * Job ↔ Git ↔ Harbor 三方映射表
     *
     * ┌──────────────┬──────┬──────────────────────────────────────────────────┐
     * │ 字段           │ 必填  │ 说明                                             │
     * ├──────────────┼──────┼──────────────────────────────────────────────────┤
     * │ job_name     │ ✅   │ Jenkins Job 完整路径                               │
     * │ git_platform │      │ 自建实例强烈建议。可选: gitlab|gitee|github|gitea    │
     * │ git_remote   │      │ 不填则从 Jenkins 自动获取                           │
     * │ project_id   │      │ GitLab: 不填自动查 API                              │
     * │ web_url      │      │ 项目主页链接，仅展示                                 │
     * │ current_path │      │ 不填则从 git_remote 自动推导                        │
     * │ harbor_      │      │ 关联 Harbor 仓库 "project/repository"               │
     * │   repository │      │                                                   │
     * │ api_version  │      │ 纯元数据，不影响 API 路由                            │
     * └──────────────┴──────┴──────────────────────────────────────────────────┘
     */
    'job_git_map' => [
        // [
        //     'job_name'          => 'java/registry',   // ✅ 必填
        //     'git_platform'      => 'gitlab',           //   自建实例必须
        //     'git_remote'        => 'http://git.company.com/group/project.git',
        //     'project_id'        => 2,
        //     'web_url'           => 'http://git.company.com/group/project',
        //     'current_path'      => 'group/project',
        //     'harbor_repository' => 'mycode/code-runtime',
        // ],
    ],
];