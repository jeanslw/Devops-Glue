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
     * ┌──────────────┬──────┬──────────────────────────────────────────────────┐
     * │ 字段           │ 必填  │ 说明                                             │
     * ├──────────────┼──────┼──────────────────────────────────────────────────┤
     * │ job_name     │ ✅   │ Jenkins Job 完整路径，如 "java/registry"            │
     * │ git_platform │      │ 自建实例强烈建议。不填则自动检测 URL 关键词，          │
     * │              │      │ 自建 GitLab/Gitea 的 URL 不含关键词时回退            │
     * │              │      │ DEFAULT_GIT_PLATFORM。可选值: gitlab|gitee|github|  │
     * │              │      │ gitea 或自定义平台名                                │
     * │ git_remote   │      │ 不填则从 Jenkins Job 的 SCM 配置自动获取             │
     * │ project_id   │      │ GitLab: 不填自动通过 API 查询; GitHub/Gitee: 可填    │
     * │ web_url      │      │ 项目主页链接，仅用于映射输出展示                      │
     * │ current_path │      │ 项目路径，不填从 git_remote 自动推导                 │
     * │ harbor_      │      │ 关联的 Harbor 仓库，格式 "project/repository"        │
     * │   repository │      │ 仅用于映射输出展示                                  │
     * │ api_version  │      │ 纯元数据，不影响 API 路由，仅用于映射输出展示          │
     * └──────────────┴──────┴──────────────────────────────────────────────────┘
     */
    'job_git_map' => [
        // ----- 示例 1：自建 GitLab（URL 不含平台关键词，必须指定 git_platform）-----
        [
            'job_name'          => 'java/registry',     // ✅ 必填
            'git_platform'      => 'gitlab',             //    自建实例必须
            'git_remote'        => 'http://git.mycompany.com/tools/registry.git',
            'project_id'        => 2,                    //    可选，不填自动查 API
            'web_url'           => 'http://git.mycompany.com/tools/registry',
            'current_path'      => 'tools/registry',
            'harbor_repository' => 'mycode/code-runtime',
        ],
        // ----- 示例 2：不指定 git_platform，由系统根据 URL 自动检测 -----
        [
            'job_name'          => 'php/myapp',
            'project_id'        => 5,                    //    可选，不填自动查 API
            'harbor_repository' => 'mycode/myapp',
        ],
        // ----- 示例 3：自建 Gitea（URL 不含关键词，必须手动指定 git_platform）-----
        // [
        //     'job_name'          => 'gitea-project',
        //     'git_platform'      => 'gitea',            //    自建实例必须
        //     'git_remote'        => 'http://code.mycompany.com/team/project.git',
        //     'harbor_repository' => 'mycode/gitea-app',
        // ],
        // ----- 示例 4：gitee.com（SaaS，URL 含 gitee.com，自动检测即可）-----
        [
            'job_name'          => 'static',
            // git_platform 不填：URL https://gitee.com/... → 自动识别为 gitee
            'project_id'        => null,
            'harbor_repository' => 'mycode/static-app',
        ],
    ],
];