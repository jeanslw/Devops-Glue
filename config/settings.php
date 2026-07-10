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
        'url'   => env('JENKINS_BASE_URL', ''),
        'user'  => env('JENKINS_USER', 'admin'),
        'token' => env('JENKINS_TOKEN'),
    ],

    // ==================== Git 多平台配置 ====================
    'git' => [
        // URL 无法匹配任何已注册平台时，回退使用的平台名
        'default_platform' => env('DEFAULT_GIT_PLATFORM', 'gitlab'),

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
        // ----- Gitea -----
        'gitea' => [
            'base_url' => env('GITEA_BASE_URL', ''),
            'token'    => env('GITEA_TOKEN', ''),
        ],
        // ----- 自定义平台 -----
        'custom_providers' => [
            // 示例：自定义 Git 平台适配器（凭证通过 env() 从 .env 读取，不写死在这里）
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

    // ==================== Harbor ====================
    'harbor' => [
        'url'      => env('HARBOR_BASE_URL', ''),
        'username' => env('HARBOR_USER', 'admin'),
        'password' => env('HARBOR_PASSWORD'),
    ],

    // ==================== 管理后台 ====================
    'admin' => [
        'user'     => env('ADMIN_USER', 'admin'),
        'password' => env('ADMIN_PASSWORD', ''),
    ],

    // ==================== App ====================
    'app' => [
        'env'           => env('APP_ENV', 'production'),
        'debug'         => env('APP_DEBUG') === 'true',
        'build_timeout' => (int) env('BUILD_TIMEOUT', '300'),
        'log_path'      => env('LOG_PATH', '/data/logs/ci-platform/'),
    ],

    /*
     * Job ↔ Git ↔ Harbor 三方映射表
     *
     * 数据已迁移到 config/job_git_map.json，通过管理界面 /admin 编辑。
     * 字段说明（仅保留文档参考）：
     *
     * ┌──────────────┬──────┬──────────────────────────────────────────────────┐
     * │ 字段           │ 必填  │ 说明                                             │
     * ├──────────────┼──────┼──────────────────────────────────────────────────┤
     * │ job_name     │ ✅   │ Jenkins Job 完整路径，如 "java/registry"            │
     * │ git_platform │      │ 自建实例强烈建议。不填则自动检测 URL 关键词，          │
     * │              │      │ 可选值: gitlab|gitee|github|gitea 或自定义平台名      │
     * │ git_remote   │      │ 不填则从 Jenkins Job 的 SCM 配置自动获取             │
     * │ project_id   │      │ GitLab: 不填自动通过 API 查询; GitHub/Gitee: 可填    │
     * │ web_url      │      │ 项目主页链接，仅用于映射输出展示                      │
     * │ current_path │      │ 项目路径，不填从 git_remote 自动推导                 │
     * │ harbor_      │      │ 关联的 Harbor 仓库，格式 "project/repository"        │
     * │   repository │      │ 仅用于映射输出展示                                  │
     * │ api_version  │      │ 纯元数据，不影响 API 路由，仅用于映射输出展示          │
     * └──────────────┴──────┴──────────────────────────────────────────────────┘
     */
    'job_git_map' => [], // 由 AdminController 管理，运行时从 config/job_git_map.json 加载
];