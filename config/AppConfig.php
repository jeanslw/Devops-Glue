<?php
namespace App\Config;

/**
 * 全局常量目录：表名、角色、权限种子、状态、构建模式/provider、scope 目录等纯静态数据。
 *
 * 本类刻意保持「无状态、无 IO」：
 *   - settings.php / env 配置读取 → {@see \App\Service\Settings}
 *   - ci_app_settings 运行时开关（build_mode/custom_push/tag 关键字等）→ {@see \App\Service\AppSettingRepository}
 *   - ci_platform_versions 读写 → {@see \App\Service\PlatformVersionRepository}
 *   - ci_job_git_map 读写/过滤 → {@see \App\Service\MappingManager}
 *   - API token 路由 scope 解析 → {@see \App\Support\ApiScopeResolver}
 */
final class AppConfig
{
    public const APP_VERSION = '2.8.9';


    // ── 表名常量 ──
    public const TABLE_JOB_GIT_MAP       = 'ci_job_git_map';
    public const TABLE_PIPELINE_ARTIFACTS = 'ci_pipeline_artifacts';
    public const TABLE_PIPELINE_BUILD_LOG = 'ci_pipeline_build_log'; // 拉取式记录「镜像 Tag」日志兜底的懒解析缓存（sha→tag）
    public const TABLE_CUSTOM_BUILDS     = 'ci_custom_builds';
    public const TABLE_SECURITY_CHECKS   = 'ci_security_checks';
    public const TABLE_ADMIN_USERS       = 'admin_users';
    public const TABLE_PLATFORM_VERSIONS = 'ci_platform_versions';
    public const TABLE_APP_SETTINGS      = 'ci_app_settings';
    public const TABLE_CACHE             = 'cache';
    public const TABLE_ROLES             = 'roles';
    public const TABLE_PERMISSIONS       = 'permissions';
    public const TABLE_ROLE_PERMISSIONS  = 'role_permissions';
    public const TABLE_IMPLIED_RULES     = 'implied_rules';
    public const TABLE_API_TOKENS        = 'api_tokens';
    public const TABLE_USER_IDENTITIES   = 'user_identities'; // 身份源关联表（v2.6.3 引入，支持 ldap/local 等多登录方式）
    public const TABLE_OPERATION_LOGS    = 'ci_operation_logs'; // 后台操作审计日志
    public const TABLE_API_ACCESS_LOGS   = 'ci_api_access_logs'; // API token 调用日志（只记名不记 token）
    public const TABLE_CD_DEPLOY_LOG_VIEW = 'v_glue_deploy_logs'; // CD 部署记录契约视图（只读审计）

    // ── 角色常量 ──
    public const ROLE_SUPER_ADMIN = 'super_admin';
    public const ROLE_ADMIN       = 'admin';
    public const ROLE_CI_ADMIN    = 'ci_admin';
    public const ROLE_CD_ADMIN    = 'cd_admin';
    public const ROLE_DEPLOYER    = 'deployer';
    public const ROLE_VIEWER      = 'viewer';

    // 请求 attribute 标记：API token 鉴权成功后的 currentRole 值。
    // 注意：它不是 RBAC 角色（不进 roles 表），仅用于区分「服务账号 token」与「管理登录」两种鉴权来源。
    public const ROLE_API_TOKEN   = 'api_token';

    // ── 权限键常量 ──
    public const PERM_CI_MANAGE             = 'ci.manage';
    public const PERM_CI_USERS_MANAGE       = 'ci.users.manage';
    public const PERM_CI_USERS_LIST         = 'ci.users.list';
    public const PERM_CI_USERS_PASSWORD     = 'ci.users.password';
    public const PERM_CI_USERS_MANAGE_ADMIN = 'ci.users.manage_admin';
    public const PERM_CI_PERMISSIONS_MANAGE   = 'ci.permissions.manage';    // 权限管理一级父（权限管理分组）
    public const PERM_CI_PERMISSIONS_LIST     = 'ci.permissions.list';      // 权限列表查看
    public const PERM_CI_PERMISSIONS_REGISTER = 'ci.permissions.register';  // 权限注册/删除
    public const PERM_CI_PERMISSIONS_RULES    = 'ci.permissions.rules';     // 隐含规则增删
    public const PERM_CI_MAPPING_EDIT       = 'ci.mapping.edit';
    public const PERM_CI_PLATFORM_EDIT      = 'ci.platform.edit';
    public const PERM_CI_MODE_EDIT          = 'ci.mode.edit';
    public const PERM_CI_DISCOVER           = 'ci.discover';
    public const PERM_CI_TRIGGER            = 'ci.trigger';
    // 构建记录（一级菜单 + 拉取式/推送式两个二级只读视图）
    public const PERM_CI_BUILD_RECORDS      = 'ci.build-records';
    public const PERM_CI_BUILD_RECORDS_PULL = 'ci.build-records.pull';
    public const PERM_CI_BUILD_RECORDS_PUSH = 'ci.build-records.push';
    // 日志中心（一级父菜单：操作日志 + 部署日志两个只读子菜单）
    public const PERM_CI_LOGS             = 'ci.logs';
    // 操作日志（后台审计，日志中心子菜单）
    public const PERM_CI_OPERATION_LOGS   = 'ci.operation-logs';
    // 部署日志（只读 CD 部署记录审计视图，日志中心子菜单）
    public const PERM_CI_DEPLOY_LOGS      = 'ci.deploy-logs';
    // API 调用日志（API token 调用审计，日志中心子菜单）
    public const PERM_CI_API_LOGS         = 'ci.api-logs';
    // 系统设置（一级父权限：平台管理 + 数据管理两个子权限，对应「系统设置」菜单分组）
    public const PERM_CI_SETTINGS         = 'ci.settings';
    // 数据管理（DB schema 状态面板，系统设置子菜单，仅 super_admin 可触发迁移）
    public const PERM_CI_SYSTEM           = 'ci.system';
    // 平台管理（平台接入状态 + 平台级 tag 设置，系统设置子菜单）
    public const PERM_CI_PLATFORM_CONFIG  = 'ci.platform-config';
    // CD 权限（对应 CD 系统侧边栏菜单）
    public const PERM_CD_BUILD   = 'cd.build-manage';
    public const PERM_CD_DEPLOY  = 'cd.deploy-manage';
    public const PERM_CD_SERVER  = 'cd.server-manage';
    public const PERM_CD_WEBSHELL = 'cd.webshell';
    public const PERM_CD_HISTORY = 'cd.deploy-record';
    public const PERM_CD_REGISTRY = 'cd.image-registry';
    public const PERM_CD_MONITOR = 'cd.resource-monitor';
    public const PERM_CD_NOTIFY  = 'cd.notification-manage';
    public const PERM_CD_BOT     = 'cd.bot';
    public const PERM_CD_WEBHOOK = 'cd.webhook';
    public const PERM_CD_APPROVAL_CENTER = 'cd.approval-center'; // 审批中心一级菜单
    public const PERM_CD_APPROVE         = 'cd.deploy.approve';  // 审批/驳回操作（刻意挂在 cd.approval-center 一级菜单下，不走 cd.deploy 菜单）

    /** 默认权限种子数据：key => ['name' => '显示名', 'parent' => null|parent_key]（英文规范数据，UI 通过 i18n 翻译） */
    public const DEFAULT_PERMISSIONS = [
        // CI（18 个：用户管理1父4子 + 权限管理1父3子 + 6 个独立权限 + 权限管理父）
        self::PERM_CI_MANAGE             => ['name' => 'CI Config', 'parent' => null],
        self::PERM_CI_USERS_MANAGE       => ['name' => 'User Management', 'parent' => null],
        self::PERM_CI_USERS_LIST         => ['name' => 'User List', 'parent' => self::PERM_CI_USERS_MANAGE],
        self::PERM_CI_USERS_PASSWORD     => ['name' => 'Change Password', 'parent' => self::PERM_CI_USERS_MANAGE],
        self::PERM_CI_USERS_MANAGE_ADMIN => ['name' => 'Roles', 'parent' => self::PERM_CI_USERS_MANAGE],
        self::PERM_CI_PERMISSIONS_MANAGE   => ['name' => 'Permission Management', 'parent' => null],
        self::PERM_CI_PERMISSIONS_LIST     => ['name' => 'Permission List', 'parent' => self::PERM_CI_PERMISSIONS_MANAGE],
        self::PERM_CI_PERMISSIONS_REGISTER => ['name' => 'Permission Register', 'parent' => self::PERM_CI_PERMISSIONS_MANAGE],
        self::PERM_CI_PERMISSIONS_RULES    => ['name' => 'Implied Rules', 'parent' => self::PERM_CI_PERMISSIONS_MANAGE],
        self::PERM_CI_MAPPING_EDIT       => ['name' => 'Edit Mapping', 'parent' => null],
        self::PERM_CI_PLATFORM_EDIT      => ['name' => 'Edit Platform', 'parent' => null],
        self::PERM_CI_MODE_EDIT          => ['name' => 'Edit Build Mode', 'parent' => null],
        self::PERM_CI_DISCOVER           => ['name' => 'View Discovery', 'parent' => null],
        self::PERM_CI_TRIGGER            => ['name' => 'Trigger Build', 'parent' => null],
        self::PERM_CI_BUILD_RECORDS      => ['name' => 'Build Records', 'parent' => null],
        self::PERM_CI_BUILD_RECORDS_PULL => ['name' => 'Pull Records', 'parent' => self::PERM_CI_BUILD_RECORDS],
        self::PERM_CI_BUILD_RECORDS_PUSH => ['name' => 'Push Records', 'parent' => self::PERM_CI_BUILD_RECORDS],
        self::PERM_CI_LOGS               => ['name' => 'Log Center', 'parent' => null],
        self::PERM_CI_OPERATION_LOGS     => ['name' => 'Operation Logs', 'parent' => self::PERM_CI_LOGS],
        self::PERM_CI_DEPLOY_LOGS        => ['name' => 'Deploy Logs', 'parent' => self::PERM_CI_LOGS],
        self::PERM_CI_API_LOGS           => ['name' => 'API Access Logs', 'parent' => self::PERM_CI_LOGS],
        self::PERM_CI_SETTINGS           => ['name' => 'System Settings', 'parent' => null],
        self::PERM_CI_PLATFORM_CONFIG    => ['name' => 'Platform Management', 'parent' => self::PERM_CI_SETTINGS],
        self::PERM_CI_SYSTEM             => ['name' => 'Data Management', 'parent' => self::PERM_CI_SETTINGS],
        // CD 一级菜单（8 个）
        self::PERM_CD_BUILD              => ['name' => 'Build Management', 'parent' => null],
        self::PERM_CD_DEPLOY             => ['name' => 'Deploy Management', 'parent' => null],
        self::PERM_CD_SERVER             => ['name' => 'Server Management', 'parent' => null],
        self::PERM_CD_WEBSHELL           => ['name' => 'Web Shell', 'parent' => null],
        self::PERM_CD_HISTORY            => ['name' => 'Deploy History', 'parent' => null],
        self::PERM_CD_REGISTRY           => ['name' => 'Image Registry', 'parent' => null],
        self::PERM_CD_MONITOR            => ['name' => 'Resource Monitor', 'parent' => null],
        self::PERM_CD_NOTIFY             => ['name' => 'Notification Management', 'parent' => null],
        // CD 三级菜单（通知管理下挂 Bot / WebHook，2 个）
        self::PERM_CD_BOT                => ['name' => 'Bot Config', 'parent' => self::PERM_CD_NOTIFY],
        self::PERM_CD_WEBHOOK            => ['name' => 'WebHook Config', 'parent' => self::PERM_CD_NOTIFY],
        // CD 二级菜单（7 个）
        'cd.deploy.single'  => ['name' => 'Deploy to SSH', 'parent' => self::PERM_CD_DEPLOY],
        'cd.deploy.docker'  => ['name' => 'Deploy to Docker', 'parent' => self::PERM_CD_DEPLOY],
        'cd.deploy.k8s'     => ['name' => 'Deploy to K8S', 'parent' => self::PERM_CD_DEPLOY],
        'cd.monitor.app'    => ['name' => 'App Resources', 'parent' => self::PERM_CD_MONITOR],
        'cd.monitor.system' => ['name' => 'System Resources', 'parent' => self::PERM_CD_MONITOR],
        'cd.monitor.custom' => ['name' => 'Custom Resources', 'parent' => self::PERM_CD_MONITOR],
        'cd.monitor.alert'   => ['name' => 'Alert Rules', 'parent' => self::PERM_CD_MONITOR],
        // CD 审批中心（一级菜单 + 审批操作子权限，2 个）
        self::PERM_CD_APPROVAL_CENTER => ['name' => 'Approval Center', 'parent' => null],
        self::PERM_CD_APPROVE         => ['name' => 'Approve', 'parent' => self::PERM_CD_APPROVAL_CENTER],
    ];

    /**
     * 权限隐含关系：选了一方自动拥有另一方
     *   - 父→子：选了「构建管理」自动拥有「触发构建」
     *   - 子→父：选了「部署到单机」自动拥有「部署管理」菜单
     */
    public const IMPLIED_PERMISSIONS = [
        // 父→子
        self::PERM_CD_BUILD => [self::PERM_CI_TRIGGER],
        // 映射管理 → 拉取式记录只读：映射页「复制 Pipeline ID」按钮依赖 /api/build/{path}/pipelines
        self::PERM_CI_MAPPING_EDIT => [self::PERM_CI_BUILD_RECORDS_PULL],
        // 子→父（选了二级自动显示一级菜单）
        self::PERM_CI_USERS_LIST         => [self::PERM_CI_USERS_MANAGE],
        self::PERM_CI_USERS_PASSWORD     => [self::PERM_CI_USERS_MANAGE],
        self::PERM_CI_USERS_MANAGE_ADMIN => [self::PERM_CI_USERS_MANAGE],
        self::PERM_CI_PERMISSIONS_LIST     => [self::PERM_CI_PERMISSIONS_MANAGE],
        self::PERM_CI_PERMISSIONS_REGISTER => [self::PERM_CI_PERMISSIONS_MANAGE],
        self::PERM_CI_PERMISSIONS_RULES    => [self::PERM_CI_PERMISSIONS_MANAGE],
        'cd.deploy.single'  => [self::PERM_CD_DEPLOY],
        'cd.deploy.docker'  => [self::PERM_CD_DEPLOY],
        'cd.deploy.k8s'     => [self::PERM_CD_DEPLOY],
        'cd.monitor.app'    => [self::PERM_CD_MONITOR],
        'cd.monitor.system' => [self::PERM_CD_MONITOR],
        'cd.monitor.custom' => [self::PERM_CD_MONITOR],
        'cd.monitor.alert'  => [self::PERM_CD_MONITOR],
        // 通知管理 ↔ Bot/WebHook
        //   子→父：选了 Bot/WebHook 自动显示「通知管理」一级菜单
        self::PERM_CD_BOT     => [self::PERM_CD_NOTIFY],
        self::PERM_CD_WEBHOOK => [self::PERM_CD_NOTIFY],
        // 审批操作 → 审批中心（选了二级自动显示一级菜单）
        self::PERM_CD_APPROVE => [self::PERM_CD_APPROVAL_CENTER],
        // 构建记录：选了二级（拉取式/推送式）自动显示一级菜单
        self::PERM_CI_BUILD_RECORDS_PULL => [self::PERM_CI_BUILD_RECORDS],
        self::PERM_CI_BUILD_RECORDS_PUSH => [self::PERM_CI_BUILD_RECORDS],
        // 日志中心：选了二级（操作日志/部署日志）自动显示一级菜单
        self::PERM_CI_OPERATION_LOGS => [self::PERM_CI_LOGS],
        self::PERM_CI_DEPLOY_LOGS    => [self::PERM_CI_LOGS],
        self::PERM_CI_API_LOGS       => [self::PERM_CI_LOGS],
        // 系统设置：选了二级（平台管理/数据管理）自动显示一级菜单
        self::PERM_CI_PLATFORM_CONFIG => [self::PERM_CI_SETTINGS],
        self::PERM_CI_SYSTEM          => [self::PERM_CI_SETTINGS],
    ];

    /** 默认角色种子数据：super_admin 内置全权限（'*'），viewer 内置只读（CI + CD 两侧的纯读视图 key）。
     *  只同步系统角色（不碰后台自定义角色），每次 bootstrap 幂等重建。 */
    public const DEFAULT_ROLES = [
        self::ROLE_SUPER_ADMIN => '*',
        self::ROLE_VIEWER      => [
            // CI 侧只读：用户列表 + 权限列表。
            // 刻意不含 ci.manage——它除了 gate 安全扫描外，还是 isAdminRole() 的判定源（=管理员标记），非纯读。
            self::PERM_CI_USERS_LIST,        // CI 用户列表
            self::PERM_CI_PERMISSIONS_LIST,  // CI 权限列表
            self::PERM_CI_BUILD_RECORDS,      // 构建记录一级菜单
            self::PERM_CI_BUILD_RECORDS_PULL, // 拉取式记录（只读）
            self::PERM_CI_BUILD_RECORDS_PUSH, // 自定义推送记录（只读）
            self::PERM_CI_LOGS,              // 日志中心一级菜单
            self::PERM_CI_OPERATION_LOGS,    // 操作日志（只读）
            self::PERM_CI_DEPLOY_LOGS,       // 部署日志（只读）
            self::PERM_CI_API_LOGS,          // API 调用日志（只读）
            // CD 侧只读。刻意不含 cd.image-registry——它在 CD 同时 gate 删除 tag 等写操作。
            self::PERM_CD_BUILD,            // CI 构建结果
            self::PERM_CD_HISTORY,          // 部署记录
            self::PERM_CD_MONITOR,          // 资源监控父菜单
            'cd.monitor.app',               // 应用资源
            'cd.monitor.system',            // 系统资源
            self::PERM_CD_APPROVAL_CENTER,  // 审批中心
        ],
    ];

    /** 系统角色描述（roles.description 种子）：CD 侧审批角色目录把它当显示名（空则回退 name），故用简短中文名。 */
    public const DEFAULT_ROLE_DESCRIPTIONS = [
        self::ROLE_SUPER_ADMIN => '超级管理员',
        self::ROLE_VIEWER      => '只读',
    ];

    // ── 状态常量 ──
    public const STATUS_ACTIVE   = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_PENDING  = 'pending';

    // ── 系统内置角色（不可删除）──
    public const DEFAULT_SYSTEM_ROLES = [
        self::ROLE_SUPER_ADMIN,
        self::ROLE_VIEWER,
    ];

    // ── 系统类型常量 ──
    public const SYSTEM_CI   = 'ci';
    public const SYSTEM_CD   = 'cd';
    public const SYSTEM_BOTH = 'both';

    // ── 构建模式常量 ──
    public const BUILD_MODE_JENKINS   = 'jenkins';
    public const BUILD_MODE_GITLAB_CI = 'gitlab_ci';
    public const BUILD_MODE_GITEA_CI  = 'gitea_ci';
    public const BUILD_MODE_BOTH      = 'both'; // 旧格式遗留，读取时映射为 jenkins,gitlab_ci，不再作为存储值
    // 注：custom_push 不再作为 build_mode 值，改为独立开关 custom_push_enabled

    // ── 构建提供者常量 ──
    public const PROVIDER_JENKINS     = 'jenkins';
    public const PROVIDER_GITLAB_CI   = 'gitlab_ci';
    public const PROVIDER_GITEA_CI    = 'gitea_ci';

    /** 内置「拉取式」构建 provider 集合（build_mode 只在这些值里选；custom_push 是独立开关，不在此列） */
    public const BUILTIN_PULL_PROVIDERS = [self::PROVIDER_JENKINS, self::PROVIDER_GITLAB_CI, self::PROVIDER_GITEA_CI];
    /**
     * 自定义推送式 CI（user push 模式）默认注册名。
     * custom_push 模式由用户在 settings.php 的 build.custom_providers 中通过 name 字段自定义，
     * 此常量仅作为默认值 / 占位，具体 name 以配置为准。
     */
    public const PROVIDER_CUSTOM_PUSH = 'custom_push';

    // ── 构建记录「镜像 Tag」日志兜底 ──
    // 推送成功关键字（日志兜底匹配用，用户可在「平台管理」页自定义）；默认 'digest'，
    // 命中 docker push 成功输出的 `digest: sha256:... size: ...` 片段。
    // 支持 `|` 分隔多个关键字，任一命中即视为推送成功片段。
    public const SETTING_TAG_LOG_KEYWORD = 'tag_log_keyword';
    public const DEFAULT_TAG_LOG_KEYWORD = 'digest';

    // 镜像 Tag 日志回填开关：开启后回填 cron 会把日志推导的 tag（经 Harbor 校验存在）
    // 提升写入 canonical ci_pipeline_artifacts。默认关闭（写入需显式授权）。
    public const SETTING_BACKFILL_TAG_ENABLED = 'backfill_tag_enabled';

    // ── API 调用审计写入级别（ci_api_access_logs）──
    // 控制哪些结果写入数据表（文件日志不受限，始终全量，便于排障）：
    //   all     全部（success + denied + failure）
    //   warning 仅 denied + failure
    //   error   仅 failure
    //   off     不写数据表
    public const SETTING_API_ACCESS_LOG_LEVEL = 'api_access_log_level';
    public const DEFAULT_API_ACCESS_LOG_LEVEL = 'all';
    public const API_ACCESS_LOG_LEVELS = ['all', 'warning', 'error', 'off'];

    // 审计日志保留天数（cli/cleanup-api-access-logs.php 每日清理依据）与清理开关。
    // 开关默认开启：保持「每日按保留期清理」的既有行为，需显式关闭才停止。
    public const SETTING_API_ACCESS_LOG_RETAIN_DAYS = 'api_access_log_retain_days';
    public const DEFAULT_API_ACCESS_LOG_RETAIN_DAYS = 90;
    public const MAX_API_ACCESS_LOG_RETAIN_DAYS = 3650;
    public const SETTING_API_ACCESS_LOG_CLEANUP_ENABLED = 'api_access_log_cleanup_enabled';

    // ── 缓存键前缀常量 ──
    public const CACHE_KEY_ADMIN_TOKEN_PREFIX = 'admin_token_';
    public const CACHE_KEY_MAP_LIST_PREFIX    = 'map_list_';
    public const CACHE_KEY_HARBOR_VERSION     = 'harbor_api_version';
    public const CACHE_KEY_HARBOR_SPECIFIC_VERSION = 'harbor_specific_version';

    // ── TTL 常量（秒）──
    public const TTL_TOKEN = 86400;  // 登录 token 有效期（24h）
    public const TTL_CACHE = 3600;   // 通用缓存有效期（1h）

    // ── 登录失败限流（防暴力破解，IP + 用户名 维度）──
    public const CACHE_KEY_LOGIN_FAIL_PREFIX = 'login_fail_';
    public const LOGIN_FAIL_MAX_ATTEMPTS     = 5;
    public const LOGIN_FAIL_LOCK_SECONDS     = 900;  // 连续失败锁定 15 分钟

    // ── API Token 作用域（scope）──
    // 独立于 RBAC 权限体系：token 直接携带 scopes，每个 scope 映射到一组接口的读写能力。
    // 值均为 i18n key（lang/zh_CN、lang/en），供「API 管理」UI 渲染与后端翻译复用。
    public const API_SCOPE_MAIN         = 'main';
    public const API_SCOPE_GIT          = 'git';
    public const API_SCOPE_HARBOR_READ  = 'harbor.read';
    public const API_SCOPE_HARBOR_SCAN  = 'harbor.scan';
    public const API_SCOPE_BUILD_READ   = 'build.read';
    public const API_SCOPE_BUILD_WRITE  = 'build.write';
    public const API_SCOPE_BUILD_REPORT = 'build.report';
    public const API_SCOPE_RBAC_USER_WRITE = 'rbac.user.write';

    /** 可选 scope 目录：key => i18n 翻译键 */
    public const API_SCOPES = [
        self::API_SCOPE_MAIN         => 'api.scope.main',
        self::API_SCOPE_GIT          => 'api.scope.git',
        self::API_SCOPE_HARBOR_READ  => 'api.scope.harbor_read',
        self::API_SCOPE_HARBOR_SCAN  => 'api.scope.harbor_scan',
        self::API_SCOPE_BUILD_READ   => 'api.scope.build_read',
        self::API_SCOPE_BUILD_WRITE  => 'api.scope.build_write',
        self::API_SCOPE_BUILD_REPORT => 'api.scope.build_report',
        self::API_SCOPE_RBAC_USER_WRITE => 'api.scope.rbac_user_write',
    ];

    /**
     * scope → 控制器内二次校验的权限 key 映射。
     * 写操作端点（build trigger/retry/cancel、harbor scanTrigger）在 Controller 里还有一层
     * requirePermission('ci.trigger') 检查，API token 命中这些 scope 时须把对应权限注入 userPermissions，
     * 否则中间件放行但控制器会 403。
     */
    public const API_SCOPE_PERMS = [
        self::API_SCOPE_BUILD_WRITE  => [self::PERM_CI_TRIGGER],
        self::API_SCOPE_BUILD_REPORT => [self::PERM_CI_TRIGGER],
        self::API_SCOPE_HARBOR_SCAN  => [self::PERM_CI_TRIGGER],
        // build.read → ci.build-records.pull：构建记录只读端点（pipelines/logs 等）在 Controller 内二次校验，
        // 保证持有 build.read scope 的 CD 服务账号 token 读取拉取式记录时不被 403。
        self::API_SCOPE_BUILD_READ   => [self::PERM_CI_BUILD_RECORDS_PULL],
        // 只读 scope 也按「CI 读权限」注入：main/git/harbor.read 对应的列表/查询端点现都在 Controller 内
        // requirePermission(ci.build-records.pull)，token 命中这些 scope 时须注入，否则会 403。
        // main 另注入 ci.discover（gitDiscovery 端点）；该权限只 gate 发现视图，不构成写权限提权。
        self::API_SCOPE_MAIN         => [self::PERM_CI_BUILD_RECORDS_PULL, self::PERM_CI_DISCOVER],
        self::API_SCOPE_GIT          => [self::PERM_CI_BUILD_RECORDS_PULL],
        self::API_SCOPE_HARBOR_READ  => [self::PERM_CI_BUILD_RECORDS_PULL],
    ];

    /**
     * scope → 该 scope 实际可访问的接口能力清单（用于「API 管理」列表展示）。
     * 值直接以英文端点/操作名呈现（不经过 i18n），让用户一眼看清 token 具体能调哪些接口，
     * 避免只看到 `build.report` 这样抽象的 scope 键而误以为权限没设置好。
     */
    public const API_SCOPE_CAPABILITIES = [
        self::API_SCOPE_MAIN         => ['main'],
        self::API_SCOPE_GIT          => ['git'],
        self::API_SCOPE_HARBOR_READ  => ['harbor.read'],
        self::API_SCOPE_HARBOR_SCAN  => ['harbor.scan'],
        self::API_SCOPE_BUILD_READ   => ['build.read'],
        self::API_SCOPE_BUILD_WRITE  => ['trigger', 'retry', 'cancel'],
        self::API_SCOPE_BUILD_REPORT => ['scan-sync', 'commit-status', 'report'],
        self::API_SCOPE_RBAC_USER_WRITE => ['rbac.users', 'rbac.roles'],
    ];
}