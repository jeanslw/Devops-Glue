<?php

namespace App\Config;

/**
 * 全局常量目录：表名、角色、权限种子、状态、构建模式与 provider、scope 目录等纯静态数据。
 *
 * 本类保持无状态、无 IO：
 *   - settings.php / env 配置读取：{@see \App\Service\Settings}
 *   - ci_app_settings 运行时开关（build_mode/custom_push/tag 关键字等）：{@see \App\Service\AppSettingRepository}
 *   - ci_platform_versions 读写：{@see \App\Service\PlatformVersionRepository}
 *   - ci_job_git_map 读写/过滤：{@see \App\Service\MappingManager}
 *   - API token 路由 scope 解析：{@see \App\Support\ApiScopeResolver}
 */
final class AppConfig
{
    public const APP_VERSION = '2.11.2';


    // ── 表名常量 ──
    public const TABLE_JOB_GIT_MAP       = 'ci_job_git_map';
    public const TABLE_PIPELINE_ARTIFACTS = 'ci_pipeline_artifacts';
    public const TABLE_BUILD_TAG_CACHE = 'ci_build_tag_cache';
    public const TABLE_CUSTOM_BUILDS     = 'ci_custom_builds';
    public const TABLE_SECURITY_CHECKS   = 'ci_security_checks';
    public const TABLE_ADMIN_USERS       = 'ci_admin_users';
    public const TABLE_PLATFORM_VERSIONS = 'ci_platform_versions';
    public const TABLE_APP_SETTINGS      = 'ci_app_settings';
    public const TABLE_CACHE             = 'ci_cache';
    public const TABLE_ROLES             = 'ci_roles';
    public const TABLE_PERMISSIONS       = 'ci_permissions';
    public const TABLE_ROLE_PERMISSIONS  = 'ci_role_permissions';
    public const TABLE_IMPLIED_RULES     = 'ci_implied_rules';
    public const TABLE_API_TOKENS        = 'ci_api_tokens';
    public const TABLE_USER_IDENTITIES   = 'ci_user_identities';
    public const TABLE_OPERATION_LOGS    = 'ci_operation_logs';
    public const TABLE_API_ACCESS_LOGS   = 'ci_api_access_logs';
    public const TABLE_INTEGRATED_SERVICES = 'ci_integrated_services';
    public const TABLE_OAUTH_CLIENTS      = 'ci_oauth_clients';
    public const TABLE_PLATFORM_CONNECTIONS = 'ci_platform_connections';

    public const LEGACY_TABLE_NAMES = [
        'admin_users'        => 'ci_admin_users',
        'cache'              => 'ci_cache',
        'roles'              => 'ci_roles',
        'permissions'        => 'ci_permissions',
        'role_permissions'   => 'ci_role_permissions',
        'implied_rules'      => 'ci_implied_rules',
        'api_tokens'         => 'ci_api_tokens',
        'user_identities'    => 'ci_user_identities',
        'integrated_services' => 'ci_integrated_services',
        'ci_pipeline_build_log' => 'ci_build_tag_cache',
    ];

    // ── 集成服务──
    public const SERVICE_CD             = 'cd';
    public const SERVICE_PROVISION_AUTO   = 'auto';
    public const SERVICE_PROVISION_MANUAL = 'manual';

    /**
     * CD 启用向导自动签发的服务 token 限定 scope：
     *   - build.read  ：读取构建记录/pipelines/tags/日志
     *   - build.write ：触发构建 / retry / cancel
     *   - git         ：读 job 绑定 Git 仓库的分支 / tag
     *   - rbac.user.write：CD 账号管理通道
     */
    public const CD_SERVICE_TOKEN_SCOPES = [
        self::API_SCOPE_BUILD_READ,
        self::API_SCOPE_BUILD_WRITE,
        self::API_SCOPE_GIT,
        self::API_SCOPE_RBAC_USER_WRITE,
    ];

    // ── 角色常量 ──
    public const ROLE_SUPER_ADMIN = 'super_admin';
    public const ROLE_ADMIN       = 'admin';
    public const ROLE_CI_ADMIN    = 'ci_admin';
    public const ROLE_CD_ADMIN    = 'cd_admin';
    public const ROLE_DEPLOYER    = 'deployer';
    public const ROLE_VIEWER      = 'viewer';
    public const ROLE_API_TOKEN   = 'api_token';

    // ── 权限键常量 ──
    public const PERM_CI_MANAGE             = 'ci.manage';
    public const PERM_CI_USERS_MANAGE       = 'ci.users.manage';
    public const PERM_CI_USERS_LIST         = 'ci.users.list';
    public const PERM_CI_USERS_PASSWORD     = 'ci.users.password';
    public const PERM_CI_USERS_MANAGE_ADMIN = 'ci.users.manage_admin';
    public const PERM_CI_PERMISSIONS_MANAGE   = 'ci.permissions.manage';
    public const PERM_CI_PERMISSIONS_LIST     = 'ci.permissions.list';
    public const PERM_CI_PERMISSIONS_REGISTER = 'ci.permissions.register';
    public const PERM_CI_PERMISSIONS_RULES    = 'ci.permissions.rules';
    public const PERM_CI_MAPPING_EDIT       = 'ci.mapping.edit';
    public const PERM_CI_PLATFORM_EDIT      = 'ci.platform.edit';
    public const PERM_CI_MODE_EDIT          = 'ci.mode.edit';
    public const PERM_CI_DISCOVER           = 'ci.discover';
    public const PERM_CI_TRIGGER            = 'ci.trigger';
    public const PERM_CI_BUILD_RECORDS      = 'ci.build-records';
    public const PERM_CI_BUILD_RECORDS_PULL = 'ci.build-records.pull';
    public const PERM_CI_BUILD_RECORDS_PUSH = 'ci.build-records.push';
    public const PERM_CI_LOGS             = 'ci.logs';
    public const PERM_CI_OPERATION_LOGS   = 'ci.operation-logs';
    public const PERM_CI_DEPLOY_LOGS      = 'ci.deploy-logs';
    public const PERM_CI_API_LOGS         = 'ci.api-logs';
    public const PERM_CI_SETTINGS         = 'ci.settings';
    public const PERM_CI_SYSTEM           = 'ci.system';
    public const PERM_CI_PLATFORM_CONFIG  = 'ci.platform-config';
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
    public const PERM_CD_APPROVAL_CENTER = 'cd.approval-center';
    public const PERM_CD_APPROVE         = 'cd.deploy.approve';

    //默认权限种子数据
    public const DEFAULT_PERMISSIONS = [
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
        self::PERM_CD_BUILD              => ['name' => 'Build Management', 'parent' => null],
        self::PERM_CD_DEPLOY             => ['name' => 'Deploy Management', 'parent' => null],
        self::PERM_CD_SERVER             => ['name' => 'Server Management', 'parent' => null],
        self::PERM_CD_WEBSHELL           => ['name' => 'Web Shell', 'parent' => null],
        self::PERM_CD_HISTORY            => ['name' => 'Deploy History', 'parent' => null],
        self::PERM_CD_REGISTRY           => ['name' => 'Image Registry', 'parent' => null],
        self::PERM_CD_MONITOR            => ['name' => 'Resource Monitor', 'parent' => null],
        self::PERM_CD_NOTIFY             => ['name' => 'Notification Management', 'parent' => null],
        self::PERM_CD_BOT                => ['name' => 'Bot Config', 'parent' => self::PERM_CD_NOTIFY],
        self::PERM_CD_WEBHOOK            => ['name' => 'WebHook Config', 'parent' => self::PERM_CD_NOTIFY],
        'cd.deploy.single'  => ['name' => 'Deploy to SSH', 'parent' => self::PERM_CD_DEPLOY],
        'cd.deploy.docker'  => ['name' => 'Deploy to Docker', 'parent' => self::PERM_CD_DEPLOY],
        'cd.deploy.k8s'     => ['name' => 'Deploy to K8S', 'parent' => self::PERM_CD_DEPLOY],
        'cd.monitor.app'    => ['name' => 'App Resources', 'parent' => self::PERM_CD_MONITOR],
        'cd.monitor.system' => ['name' => 'System Resources', 'parent' => self::PERM_CD_MONITOR],
        'cd.monitor.custom' => ['name' => 'Custom Resources', 'parent' => self::PERM_CD_MONITOR],
        'cd.monitor.alert'   => ['name' => 'Alert Rules', 'parent' => self::PERM_CD_MONITOR],
        self::PERM_CD_APPROVAL_CENTER => ['name' => 'Approval Center', 'parent' => null],
        self::PERM_CD_APPROVE         => ['name' => 'Approve', 'parent' => self::PERM_CD_APPROVAL_CENTER],
    ];

    /**
     * 权限隐含关系：选中一方即自动拥有另一方。
     *   - 父到子：选了构建管理自动拥有触发构建
     *   - 子到父：选了部署到单机自动拥有部署管理菜单
     */
    public const IMPLIED_PERMISSIONS = [
        self::PERM_CD_BUILD => [self::PERM_CI_TRIGGER],
        self::PERM_CI_MAPPING_EDIT => [self::PERM_CI_BUILD_RECORDS_PULL],
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
        self::PERM_CD_BOT     => [self::PERM_CD_NOTIFY],
        self::PERM_CD_WEBHOOK => [self::PERM_CD_NOTIFY],
        self::PERM_CD_APPROVE => [self::PERM_CD_APPROVAL_CENTER],
        self::PERM_CI_BUILD_RECORDS_PULL => [self::PERM_CI_BUILD_RECORDS],
        self::PERM_CI_BUILD_RECORDS_PUSH => [self::PERM_CI_BUILD_RECORDS],
        self::PERM_CI_OPERATION_LOGS => [self::PERM_CI_LOGS],
        self::PERM_CI_DEPLOY_LOGS    => [self::PERM_CI_LOGS],
        self::PERM_CI_API_LOGS       => [self::PERM_CI_LOGS],
        self::PERM_CI_PLATFORM_CONFIG => [self::PERM_CI_SETTINGS],
        self::PERM_CI_SYSTEM          => [self::PERM_CI_SETTINGS],
    ];

    //默认角色种子数据
    public const DEFAULT_ROLES = [
        self::ROLE_SUPER_ADMIN => '*',
        self::ROLE_VIEWER      => [
            self::PERM_CI_USERS_LIST,
            self::PERM_CI_PERMISSIONS_LIST,
            self::PERM_CI_BUILD_RECORDS,
            self::PERM_CI_BUILD_RECORDS_PULL,
            self::PERM_CI_BUILD_RECORDS_PUSH,
            self::PERM_CI_LOGS,
            self::PERM_CI_OPERATION_LOGS,
            self::PERM_CI_DEPLOY_LOGS,
            self::PERM_CI_API_LOGS,
            self::PERM_CD_BUILD,
            self::PERM_CD_HISTORY,
            self::PERM_CD_MONITOR,
            'cd.monitor.app',
            'cd.monitor.system',
            self::PERM_CD_APPROVAL_CENTER,
        ],
    ];

    /** 系统角色描述（roles.description 种子）：CD 侧审批角色目录用它作显示名（空则回退 name），故用简短中文名。 */
    public const DEFAULT_ROLE_DESCRIPTIONS = [
        self::ROLE_SUPER_ADMIN => '超级管理员',
        self::ROLE_VIEWER      => '只读',
    ];

    // ── 状态常量 ──
    public const STATUS_ACTIVE   = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_PENDING  = 'pending';

    // ── 系统内置角色──
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
    public const BUILD_MODE_BOTH      = 'both';

    // ── 构建提供者常量 ──
    public const PROVIDER_JENKINS         = 'jenkins';
    public const PROVIDER_GITLAB_CI       = 'gitlab_ci';
    public const PROVIDER_GITEA_CI        = 'gitea_ci';
    public const PROVIDER_GITHUB_ACTIONS  = 'github_actions';

    //内置拉取式构建 provider 集合
    public const BUILTIN_PULL_PROVIDERS = [self::PROVIDER_JENKINS, self::PROVIDER_GITLAB_CI, self::PROVIDER_GITEA_CI, self::PROVIDER_GITHUB_ACTIONS];


    public const GIT_PLATFORM_CI_CAPABILITIES = [
        'gitlab' => self::PROVIDER_GITLAB_CI,
        'gitea'  => self::PROVIDER_GITEA_CI,
        'github' => self::PROVIDER_GITHUB_ACTIONS,
    ];


    public const SETTING_GIT_CI_ENABLED = 'git_ci_enabled';
    public const GIT_PLATFORMS = ['gitlab', 'github', 'gitee', 'gitea'];

    // 平台连接里「非镜像仓库」类平台（Jenkins + 各 Git 平台）。
    // 镜像仓库（harbor / registry）为动态单连接，见 PlatformConnectionRepository::REGISTRY_PLATFORMS。
    public const CONNECTION_PLATFORMS_NON_REGISTRY = ['jenkins', 'gitlab', 'github', 'gitee', 'gitea'];
    public const PROVIDER_CUSTOM_PUSH = 'custom_push';
    public const SETTING_TAG_LOG_KEYWORD = 'tag_log_keyword';
    public const DEFAULT_TAG_LOG_KEYWORD = 'digest';

    // 镜像 Tag 日志回填开关
    public const SETTING_BACKFILL_TAG_ENABLED = 'backfill_tag_enabled';

    // ── API 调用审计写入级别──
    //   all     全部（success + denied + failure）
    //   warning 仅 denied + failure
    //   error   仅 failure
    //   off     不写数据表
    public const SETTING_API_ACCESS_LOG_LEVEL = 'api_access_log_level';
    public const DEFAULT_API_ACCESS_LOG_LEVEL = 'all';
    public const API_ACCESS_LOG_LEVELS = ['all', 'warning', 'error', 'off'];

    // 审计日志保留天数与清理开关。
    public const SETTING_API_ACCESS_LOG_RETAIN_DAYS = 'api_access_log_retain_days';
    public const DEFAULT_API_ACCESS_LOG_RETAIN_DAYS = 90;
    public const MAX_API_ACCESS_LOG_RETAIN_DAYS = 3650;
    public const SETTING_API_ACCESS_LOG_CLEANUP_ENABLED = 'api_access_log_cleanup_enabled';

    // ── OIDC provider 配置──
    public const SETTING_OIDC_ISSUER       = 'oidc_issuer';
    public const SETTING_OIDC_ID_TOKEN_TTL = 'oidc_id_token_ttl';
    public const DEFAULT_OIDC_ID_TOKEN_TTL = 3600;

    // ── 缓存键前缀常量 ──
    public const CACHE_KEY_ADMIN_TOKEN_PREFIX = 'admin_token_';
    public const CACHE_KEY_MAP_LIST_PREFIX    = 'map_list_';
    public const CACHE_KEY_HARBOR_VERSION     = 'harbor_api_version';
    public const CACHE_KEY_HARBOR_SPECIFIC_VERSION = 'harbor_specific_version';

    // ── TTL 常量（秒）──
    public const TTL_TOKEN = 86400;
    public const TTL_CACHE = 3600;
    // 不稳定结论（如“疑似 v1 Harbor”）用的短 TTL：让瞬时故障快速自愈，避免长时降级。
    public const TTL_CACHE_SHORT = 120;

    // ── 登录失败限流──
    public const CACHE_KEY_LOGIN_FAIL_PREFIX = 'login_fail_';
    public const LOGIN_FAIL_MAX_ATTEMPTS     = 5;
    public const LOGIN_FAIL_LOCK_SECONDS     = 900;

    // API Token 作用域（scope）
    public const API_SCOPE_MAIN         = 'main';
    public const API_SCOPE_GIT          = 'git';
    public const API_SCOPE_REGISTRY_READ = 'registry.read';
    public const API_SCOPE_HARBOR_SCAN  = 'harbor.scan';
    public const API_SCOPE_BUILD_READ   = 'build.read';
    public const API_SCOPE_BUILD_WRITE  = 'build.write';
    public const API_SCOPE_BUILD_REPORT = 'build.report';
    public const API_SCOPE_RBAC_USER_WRITE = 'rbac.user.write';

    /** 可选 scope 目录：key => i18n 翻译键 */
    public const API_SCOPES = [
        self::API_SCOPE_MAIN         => 'api.scope.main',
        self::API_SCOPE_GIT          => 'api.scope.git',
        self::API_SCOPE_REGISTRY_READ => 'api.scope.registry_read',
        self::API_SCOPE_HARBOR_SCAN  => 'api.scope.harbor_scan',
        self::API_SCOPE_BUILD_READ   => 'api.scope.build_read',
        self::API_SCOPE_BUILD_WRITE  => 'api.scope.build_write',
        self::API_SCOPE_BUILD_REPORT => 'api.scope.build_report',
        self::API_SCOPE_RBAC_USER_WRITE => 'api.scope.rbac_user_write',
    ];

    /**
     * scope 到控制器内二次校验权限 key 的映射。
     */
    public const API_SCOPE_PERMS = [
        self::API_SCOPE_BUILD_WRITE  => [self::PERM_CI_TRIGGER],
        self::API_SCOPE_BUILD_REPORT => [self::PERM_CI_TRIGGER],
        self::API_SCOPE_HARBOR_SCAN  => [self::PERM_CI_TRIGGER],
        self::API_SCOPE_BUILD_READ   => [self::PERM_CI_BUILD_RECORDS_PULL],
        self::API_SCOPE_MAIN         => [self::PERM_CI_BUILD_RECORDS_PULL, self::PERM_CI_DISCOVER],
        self::API_SCOPE_GIT          => [self::PERM_CI_BUILD_RECORDS_PULL],
        self::API_SCOPE_REGISTRY_READ => [self::PERM_CI_BUILD_RECORDS_PULL],
    ];

    /**
     * scope 到该 scope 可访问接口能力清单的映射（用于 API 管理列表展示）。
     */
    public const API_SCOPE_CAPABILITIES = [
        self::API_SCOPE_MAIN         => ['main'],
        self::API_SCOPE_GIT          => ['git.branches', 'git.tags', 'git.refs'],
        self::API_SCOPE_REGISTRY_READ => ['registry.read', 'registry.projects', 'registry.repositories'],
        self::API_SCOPE_HARBOR_SCAN  => ['harbor.scan'],
        self::API_SCOPE_BUILD_READ   => ['build.read'],
        self::API_SCOPE_BUILD_WRITE  => ['trigger', 'retry', 'cancel'],
        self::API_SCOPE_BUILD_REPORT => ['scan-sync', 'registry-sync', 'commit-status', 'report'],
        self::API_SCOPE_RBAC_USER_WRITE => ['rbac.users', 'rbac.roles'],
    ];
}
