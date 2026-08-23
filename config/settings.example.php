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
     * OAuth2 / OIDC 客户端白名单（供 Grafana / Jenkins / Harbor / GitLab 用 Glue 账号登录）
     *
     * 每个客户端需配置：
     *   client_id     — 客户端标识（数组键名，如 grafana / jenkins）
     *   secret        — 客户端密钥（必须设强随机字符串；空/纯空白会被 fail-closed 剔除）
     *   redirect_uri  — 授权回调地址（必须与外部系统配置逐字符一致，含 scheme/host/端口/路径）
     *
     * 各家回调地址：
     *   Grafana — http://your-grafana:3000/login/generic_oauth
     *   Jenkins — http://your-jenkins/securityRealm/finishLogin
     *   Harbor  — https://your-harbor/c/oidc/callback
     *   GitLab  — https://your-gitlab/users/auth/openid_connect/callback
     */
    'oauth_clients' => [
        // 'grafana' => [
        //     'secret'       => env('GRAFANA_OAUTH_SECRET', ''),
        //     'redirect_uri' => 'http://your-grafana:3000/login/generic_oauth',
        // ],
        // 'jenkins' => [
        //     'secret'       => env('JENKINS_OIDC_SECRET', ''),
        //     'redirect_uri' => 'http://your-jenkins/securityRealm/finishLogin',
        // ],
        // 'harbor' => [
        //     'secret'       => env('HARBOR_OIDC_SECRET', ''),
        //     'redirect_uri' => 'https://your-harbor/c/oidc/callback',
        // ],
        // 'gitlab' => [
        //     'secret'       => env('GITLAB_OIDC_SECRET', ''),
        //     'redirect_uri' => 'https://your-gitlab/users/auth/openid_connect/callback',
        // ],
    ],

    /*
     * OIDC Provider 配置（Glue 作为 OIDC Provider，供 Jenkins / Harbor / GitLab 单点登录）
     *
     *   issuer       — Glue 对外地址（issuer）。留空则运行时从请求 scheme+host+port 推导；
     *                  生产环境建议显式配置为对外可达的完整 URL（如 https://glue.example.com）。
     *   private_key  — id_token 签名私钥（RS256，RSA >= 2048 位 PEM）。留空则自动生成并持久化到 key_file。
     *   key_file     — 自动生成私钥时的持久化路径（落盘后 chmod 0600）。
     *   id_token_ttl — id_token 有效期（秒）。
     */
    'oidc' => [
        'issuer'       => env('OIDC_ISSUER', ''),
        'private_key'  => env('OIDC_RSA_PRIVATE_KEY', ''),
        'key_file'     => env('OIDC_KEY_FILE', __DIR__ . '/data/oidc_rsa.pem'),
        'id_token_ttl' => (int) env('OIDC_ID_TOKEN_TTL', '3600'),
    ],
];

