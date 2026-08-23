<?php

if (!function_exists('env')) {
    function env(string $key, string $default = ''): string
    {
        return $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key) ?: $default;
    }
}

return [
    /*
     * ==================== Build Provider 配置模式 ====================
     *
     * job_git_map 中 build_provider 字段决定每个项目使用的 CI 系统：
     *   'jenkins'   — Jenkins（默认，不填即走）
     *   'gitlab_ci' — GitLab CI/CD
     *
     * 三种全局模式（通过 .env 控制哪些 Provider 注册到容器）：
     *   1. 纯 Jenkins  — GITLAB_BASE_URL 留空，仅 jenkins 注册
     *   2. 纯 GitLab CI — JENKINS_BASE_URL 留空 + GITLAB_BASE_URL 配置，仅 gitlab_ci 注册
     *   3. 共存模式     — 两者都配，job_git_map.build_provider 逐项目选择
     *
     * Jenkins 始终注册（向后兼容）；GitLab CI 仅在 GitLab 已配置且有 token 时注册。
     */
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

    // ==================== 自定义 Build Provider（推送式 CI） ====================
    // 默认已配置 CustomPushBuildProvider，开箱即用，无需修改。
    // 启用：管理后台「配置模式」Tab → 勾选「启用 Custom_Push」→ 即时生效。
    //
    // build_mode（jenkins/gitlab_ci/both）与 custom_push_enabled 正交，可任意组合。
    // job_git_map.build_provider 填 'custom_push' 即可选用。
    //
    // 用户 CI 对接一个接口（path = job_git_map.job_name）：
    //   POST /api/build/{path}/report — 一次性上报构建终态结果
    //      Body: { "pipeline_iid": 123, "status": "success", "finished_at": "2026-08-18 10:05:30",
    //              "started_at": "2026-08-18 10:00:00", "sha": "abc123", "ref": "main",
    //              "log_url": "https://ci.example.com/build/123/log",
    //              "web_url": "https://ci.example.com/build/123",
    //              "tag": "v1.0.0", "env": "prod", ... }
    //
    // 下方 variables 定义上报时允许传入的自定义构建参数（存入 variables_json）。
    // pipeline_iid/status/finished_at/tag 等控制字段不需要在此声明。
    // 如需更多参数（如 COMMIT_MSG、BUILDER），直接在下方追加即可。
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

    // ==================== 管理后台 ====================
    'admin' => [
        'user'     => env('ADMIN_USER', 'admin'),
        'password' => env('ADMIN_PASSWORD', ''),
    ],

    // ==================== CORS ====================
    'cors' => [
        'allowed_origins' => env('CORS_ORIGIN', '*') === '*'
            ? ['*']
            : array_map('trim', explode(',', env('CORS_ORIGIN', '*'))),
    ],

    // ==================== App ====================
    'app' => [
        'env'           => env('APP_ENV', 'production'),
        'debug'         => env('APP_DEBUG') === 'true',
        'build_timeout' => (int) env('BUILD_TIMEOUT', '300'),
        'log_path'      => env('LOG_PATH', '/data/logs/ci-platform/'),
        'api_base_url'  => env('API_BASE_URL', ''),
        // 当前实例类型：ci / cd / both（影响登录权限校验）
        'system_type'   => env('SYSTEM_TYPE', 'ci'),
    ],

    /*
     * Job ↔ Git ↔ Harbor 三方映射表
     *
     * ⚠️ 数据已迁移到 SQLite（config/data/data.db），通过管理界面 /admin 编辑。
     * ⚠️ 此处仅保留字段文档参考，以下数组为空，修改此处不会生效！
     * ⚠️ 请通过后台映射管理或直接操作数据库来配置，不要改这个空数组。
     *
     * 字段说明：
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
    'oauth_clients' => [
        'grafana' => [
            'secret'       => env('GRAFANA_OAUTH_SECRET', ''),
            'redirect_uri' => 'http://localhost:3000/login/generic_oauth',
        ],
        'jenkins' => [
            'secret'       => env('JENKINS_OIDC_SECRET', ''),
            'redirect_uri' => 'http://192.168.137.5:8083/securityRealm/finishLogin',
        ],
    ],

    // ==================== OIDC Provider（Jenkins / Harbor / GitLab 单点登录）====================
    // issuer 为空时运行时从请求 scheme+host 推导；私钥优先读 OIDC_RSA_PRIVATE_KEY，
    // 否则从 key_file 读，都没有则自动生成 RSA-2048 并持久化到 key_file（chmod 0600）。
    'oidc' => [
        'issuer'       => env('OIDC_ISSUER', ''),
        'private_key'  => env('OIDC_RSA_PRIVATE_KEY', ''),
        'key_file'     => env('OIDC_KEY_FILE', __DIR__ . '/data/oidc_rsa.pem'),
        'id_token_ttl' => (int) env('OIDC_ID_TOKEN_TTL', '3600'),
    ],

    'job_git_map' => [], // ⚠️ 已由 SQLite 接管，此处永远为空，修改无效！请使用 /admin 管理界面
];
