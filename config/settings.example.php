<?php
// config/settings.example.php
return [
    // Git/Harbor/Jenkins 连接配置已迁入 DB（ci_platform_connections，后台「平台接入状态」可编辑）
    'jenkins' => [
        'url'   => '',
        'user'  => 'admin',
        'token' => '',
    ],
    'git' => [
        'default_platform' => env('DEFAULT_GIT_PLATFORM', 'gitlab'),

        'gitlab' => [
            'base_url' => '',
            'token'    => '',
        ],
        'gitee' => [
            'base_url' => 'https://gitee.com/api/v5',
            'token'    => '',
        ],
        'github' => [
            'base_url' => 'https://api.github.com',
            'token'    => '',
        ],
        'gitea' => [
            'base_url' => '',
            'token'    => '',
        ],
        'custom_providers' => [
            // 示例：自定义 Git 平台
            // class 实现 GitProviderInterface，构造签名为 (array $config, ?Logger $logger)，详见 src/Service/Git/。
            // Bitbucket Cloud（API v2.0；App Password 填 username，仅 OAuth/仓库访问令牌可留空走 Bearer）：
            // [
            //     'class'  => 'App\\Service\\Git\\BitbucketService',
            //     'config' => [
            //         'name'     => 'bitbucket',
            //         'base_url' => 'https://api.bitbucket.org/2.0',
            //         'username' => env('BITBUCKET_USERNAME', ''),
            //         'token'    => env('BITBUCKET_TOKEN', ''),
            //         'matcher'  => function (string $url): bool {
            //             return str_contains($url, 'bitbucket.org') || str_contains($url, 'bitbucket');
            //         },
            //     ],
            // ],
            // GitCode（Gitee 风格 API v5，认证用 PRIVATE-TOKEN）：
            // [
            //     'class'  => 'App\\Service\\Git\\GitCodeService',
            //     'config' => [
            //         'name'     => 'gitcode',
            //         'base_url' => 'https://api.gitcode.com/api/v5',
            //         'token'    => env('GITCODE_TOKEN', ''),
            //         'matcher'  => function (string $url): bool {
            //             return str_contains($url, 'gitcode.com') || str_contains($url, 'gitcode');
            //         },
            //     ],
            // ],
        ],
    ],
    // 注意：镜像仓库类型不在这里配。ci_app_settings.registry_type（后台「平台接入状态」卡片，
    // 无环境变量种子）是唯一事实源，container.php 会用它覆盖本节的 registry_type；
    // 所以以前写在这里的 'registry_type' => 'harbor' 不生效，已删除
    // （Settings::getRegistryType() 在缺省时同样按 'harbor' 归一）。
    'harbor' => [
        'url'      => '',
        'username' => 'admin',
        'password' => '',
    ],
    'build' => [
        'custom_providers' => [
            [
                'name'   => 'custom_push',
                'class'  => 'App\\Service\\Build\\CustomPushBuildProvider',
                'config' => [
                    'variables' => [
                        'env'         => ['type' => 'choice', 'choices' => ['dev', 'staging', 'prod'], 'description' => '构建镜像目标环境（可选）', 'required' => false],
                    ],
                ],
            ],
        ],
    ],
    'app' => [
        'env'           => env('APP_ENV', 'production'),
        'debug'         => env('APP_DEBUG') === 'true',
        'log_path'      => env('LOG_PATH', '/data/logs/ci-platform/'),
        'log_retain_days' => max(0, (int) env('LOG_RETAIN_DAYS', '30')),
    ],
    'cors' => [
        'allowed_origins' => ['*'], 
        'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS'],
        'allowed_headers' => ['Content-Type', 'Authorization', 'X-Requested-With', 'Accept'],
    ],
    /*
     * Job ↔ Git ↔ Harbor 三方映射表
     *
     * ┌──────────────┬──────┬──────────────────────────────────────────────────┐
     * │ 字段           │ 必填  │ 说明                                             │
     * ├──────────────┼──────┼──────────────────────────────────────────────────┤
     * │ job_name     │ 是   │ Jenkins Job 完整路径                               │
     * │ git_platform │      │ 自建实例强烈建议。可选: gitlab|gitee|github|gitea    │
     * │ git_remote   │      │ 不填则从 Jenkins 自动获取                           │
     * │ project_id   │      │ GitLab: 不填自动查 API                              │
     * │ web_url      │      │ 项目主页链接，仅展示                                 │
     * │ current_path │      │ 不填则从 git_remote 自动推导                        │
     * │ registry_    │      │ 关联镜像仓库 "project/repository"                   │
     * │   repository │      │                                                   │
     * │ api_version  │      │ 纯元数据，不影响 API 路由                            │
     * └──────────────┴──────┴──────────────────────────────────────────────────┘
     */
    'job_git_map' => [
        // [

        //     'job_name'          => 'java/registry',   // 必填
        //     'git_platform'      => 'gitlab',           //   自建实例必须
        //     'git_remote'        => 'http://git.company.com/group/project.git',
        //     'project_id'        => 2,
        //     'web_url'           => 'http://git.company.com/group/project',
        //     'current_path'      => 'group/project',
        //     'registry_repository' => 'mycode/code-runtime',
        // ],
    ],
    // OAuth2 / OIDC 客户端白名单

    'oauth_clients' => [],

    //OIDC Provider 配置

    'oidc' => [
        'issuer'       => '',
        'id_token_ttl' => 3600,
        'private_key'  => env('OIDC_RSA_PRIVATE_KEY', ''),
        'key_file'     => env('OIDC_KEY_FILE', __DIR__ . '/data/oidc_rsa.pem'),
    ],
];

