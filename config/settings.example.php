<?php
// config/settings.example.php
return [
    // Git/Harbor/Jenkins 连接配置已迁入 DB（ci_platform_connections，后台「平台接入状态」可编辑），
    // 彻底移除 env：下方 url/user/token/password 仅结构占位，实际值由容器从 DB 注入，不要再填 env。
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
            // 示例：自定义 Git 平台（凭证通过 env() 从 .env 读取）。
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
    'harbor' => [
        'url'      => '',
        'username' => 'admin',
        'password' => '',
    ],
    'build' => [
        // 自定义 Build Provider（推送式 CI）：默认已配置，开箱即用，无需修改。
        // 启用方式：管理后台配置模式 Tab 勾选启用 Custom_Push，即时生效。
        // build_mode 与 custom_push_enabled 相互独立，可任意组合。
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
        'log_path'      => env('LOG_PATH', '/data/logs/ci-platform/'),
        'log_retain_days' => max(0, (int) env('LOG_RETAIN_DAYS', '30')),
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
     * │ job_name     │ 是   │ Jenkins Job 完整路径                               │
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

        //     'job_name'          => 'java/registry',   // 必填
        //     'git_platform'      => 'gitlab',           //   自建实例必须
        //     'git_remote'        => 'http://git.company.com/group/project.git',
        //     'project_id'        => 2,
        //     'web_url'           => 'http://git.company.com/group/project',
        //     'current_path'      => 'group/project',
        //     'harbor_repository' => 'mycode/code-runtime',
        // ],
    ],
    /*
     * OAuth2 / OIDC 客户端白名单（供外部系统用 Glue 账号登录）
     *
     * 已迁入 DB（ci_oauth_clients，后台「平台接入状态 → OIDC/SSO」页可编辑），
     * 彻底移除 env：此数组恒为空，唯一权威由 OAuthClientRepository 从 DB 读取。
     * 客户端在后台配置：client_id（如 jenkins / cd）、secret（强随机串）、
     * redirect_uri（与外部系统回调逐字符一致）；secret/redirect_uri 留空即 fail-closed 剔除。
     */
    'oauth_clients' => [],

    /*
     * OIDC Provider 配置（Glue 作为 OIDC Provider，供 Jenkins / Harbor / GitLab 单点登录）
     *
     *   issuer / id_token_ttl — 已迁入 DB（ci_app_settings，后台 OIDC 配置页可编辑），
     *                           此处不再读 env，仅结构占位。
     *   private_key  — id_token 签名私钥（RS256，RSA >= 2048 位 PEM）。保留 env 覆盖：
     *                  多副本部署必须注入固定 OIDC_RSA_PRIVATE_KEY，否则各副本 kid 不一致、验签失败；
     *                  留空则自动生成并持久化到 key_file。
     *   key_file     — 自动生成私钥时的持久化路径（落盘后 chmod 0600）。
     */
    'oidc' => [
        'issuer'       => '',
        'id_token_ttl' => 3600,
        'private_key'  => env('OIDC_RSA_PRIVATE_KEY', ''),
        'key_file'     => env('OIDC_KEY_FILE', __DIR__ . '/data/oidc_rsa.pem'),
    ],
];

