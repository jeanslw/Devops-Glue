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
    'build' => [
        // 自定义 Build Provider（推送式 CI）— 默认已配置，开箱即用，无需修改。
        // 启用：管理后台「配置模式」Tab → 勾选「启用 Custom_Push」→ 即时生效。
        // build_mode 与 custom_push_enabled 正交，可任意组合。
        //
        // 用户 CI 对接一个接口（path = job_git_map.job_name）：
        //   POST /api/build/{path}/report — 一次性上报构建终态结果（含 tag）
        //
        // variables 定义上报时允许传入的自定义构建参数。
        // 如需更多参数（如 COMMIT_MSG、BUILDER），在下方追加即可。
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
    /*
     * OAuth2 客户端白名单（供 Grafana 等外部系统用 Glue 账号登录）
     *
     * 每个客户端需配置：
     *   client_id     — 客户端标识（如 grafana）
     *   secret        — 客户端密钥（建议用随机长字符串）
     *   redirect_uri  — 授权回调地址（必须与外部系统配置完全一致）
     *
     * 示例（Grafana）：
     *   'grafana' => [
     *       'secret'       => env('GRAFANA_OAUTH_SECRET', 'change-me-to-random'),
     *       'redirect_uri' => 'http://your-grafana:3000/login/generic_oauth',
     *   ],
     */
    'oauth_clients' => [
        // 'grafana' => [
        //     'secret'       => env('GRAFANA_OAUTH_SECRET', ''),
        //     'redirect_uri' => 'http://localhost:3000/login/generic_oauth',
        // ],
    ],
];

