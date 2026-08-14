<?php
namespace App\Config;

class AppConfig
{
    public const APP_VERSION = '2.5.1';

    // ── 表名常量 ──
    public const TABLE_JOB_GIT_MAP       = 'ci_job_git_map';
    public const TABLE_PIPELINE_TAGS     = 'ci_pipeline_tags';
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
        'cd.monitor.alert'  => ['name' => 'Alert Rules', 'parent' => self::PERM_CD_MONITOR],
    ];

    /**
     * 权限隐含关系：选了一方自动拥有另一方
     *   - 父→子：选了「构建管理」自动拥有「触发构建」
     *   - 子→父：选了「部署到单机」自动拥有「部署管理」菜单
     */
    public const IMPLIED_PERMISSIONS = [
        // 父→子
        self::PERM_CD_BUILD => [self::PERM_CI_TRIGGER],
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
    ];

    /** 默认角色种子数据：只有 root 内置，'*' 表示拥有所有权限 */
    public const DEFAULT_ROLES = [
        self::ROLE_SUPER_ADMIN => '*',
    ];

    // ── 状态常量 ──
    public const STATUS_ACTIVE   = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_PENDING  = 'pending';

    // ── 系统内置角色（不可删除）──
    public const DEFAULT_SYSTEM_ROLES = [
        self::ROLE_SUPER_ADMIN,
    ];

    // ── 系统类型常量 ──
    public const SYSTEM_CI   = 'ci';
    public const SYSTEM_CD   = 'cd';
    public const SYSTEM_BOTH = 'both';

    // ── 构建模式常量 ──
    public const BUILD_MODE_JENKINS   = 'jenkins';
    public const BUILD_MODE_GITLAB_CI = 'gitlab_ci';
    public const BUILD_MODE_BOTH      = 'both';

    // ── 构建提供者常量 ──
    public const PROVIDER_JENKINS   = 'jenkins';
    public const PROVIDER_GITLAB_CI = 'gitlab_ci';

    // ── 缓存键前缀常量 ──
    public const CACHE_KEY_ADMIN_TOKEN_PREFIX = 'admin_token_';
    public const CACHE_KEY_MAP_LIST_PREFIX    = 'map_list_';
    public const CACHE_KEY_HARBOR_VERSION     = 'harbor_api_version';

    // ── TTL 常量（秒）──
    public const TTL_TOKEN = 86400;  // 登录 token 有效期（24h）
    public const TTL_CACHE = 3600;   // 通用缓存有效期（1h）

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

    /** 可选 scope 目录：key => i18n 翻译键 */
    public const API_SCOPES = [
        self::API_SCOPE_MAIN         => 'api.scope.main',
        self::API_SCOPE_GIT          => 'api.scope.git',
        self::API_SCOPE_HARBOR_READ  => 'api.scope.harbor_read',
        self::API_SCOPE_HARBOR_SCAN  => 'api.scope.harbor_scan',
        self::API_SCOPE_BUILD_READ   => 'api.scope.build_read',
        self::API_SCOPE_BUILD_WRITE  => 'api.scope.build_write',
        self::API_SCOPE_BUILD_REPORT => 'api.scope.build_report',
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
    ];

    /**
     * 根据 HTTP 方法 + 路径解析所需的 API scope。
     *
     * 返回值约定：
     *   - null          → API token 禁止访问（/api/admin/* 等管理端点，fail-closed）
     *   - '*'           → 任意有效 token 均可访问（如 /api/health）
     *   - 具体 scope    → token 必须持有该 scope
     *
     * 只对「已被 AuthMiddleware 保护」的路径生效；公开路由（i18n/docs 等）不经过此方法。
     */
    public static function resolveRequiredScope(string $method, string $path): ?string
    {
        $m = strtoupper($method);
        // 归一化：去掉查询串、统一斜杠
        $path = parse_url($path, PHP_URL_PATH) ?? $path;
        $path = '/' . trim($path, '/');
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        // 健康检查：任意有效 token
        if ($path === '/api/health') {
            return '*';
        }

        // 管理端点：API token 一律禁止（super_admin 交互式专属）
        if (preg_match('#^/api/admin($|/)#', $path)) {
            return null;
        }

        // MAIN：只读
        if (preg_match('#^/api/main($|/)#', $path)) {
            return self::API_SCOPE_MAIN;
        }

        // GIT：只读
        if (preg_match('#^/api/git($|/)#', $path)) {
            return self::API_SCOPE_GIT;
        }

        // Harbor：触发扫描（写）优先于读判断
        if (preg_match('#^/api/harbor/.+/repositories/.+/tags/.+/scan$#', $path) && $m === 'POST') {
            return self::API_SCOPE_HARBOR_SCAN;
        }
        if (preg_match('#^/api/harbor($|/)#', $path)) {
            return self::API_SCOPE_HARBOR_READ;
        }

        // Build：写操作（trigger / retry / cancel）
        if (preg_match('#^/api/build/.+/pipelines/\d+/(retry|cancel)$#', $path)) {
            return self::API_SCOPE_BUILD_WRITE;
        }
        if (preg_match('#^/api/build/.+/trigger$#', $path)) {
            return self::API_SCOPE_BUILD_WRITE;
        }

        // Build：CI 流水线回写（scan-sync / commit-status）
        if (preg_match('#^/api/build/.+/(scan-sync|commit-status)$#', $path)) {
            return self::API_SCOPE_BUILD_REPORT;
        }

        // Build：其余全部只读
        if (preg_match('#^/api/build($|/)#', $path)) {
            return self::API_SCOPE_BUILD_READ;
        }

        // 未知路径：fail-closed
        return null;
    }

    private array $config;
    private ?\PDO $pdo;

    public function __construct(array $config, ?\PDO $pdo = null)
    {
        $this->config = $config;
        $this->pdo = $pdo;
    }

    private function getPdo(): \PDO
    {
        if ($this->pdo === null) {
            throw new \RuntimeException('AppConfig requires an injected PDO instance; do not use Database::getPdo() directly.');
        }
        return $this->pdo;
    }

    // Jenkins
    public function getJenkinsConfig(): array
    {
        return [
            'url'   => $this->config['jenkins']['url'] ?? 'http://localhost:8083',
            'user'  => $this->config['jenkins']['user'] ?? '',
            'token' => $this->config['jenkins']['token'] ?? '',
        ];
    }

    // GitLab 配置
    public function getGitlabConfig(): array
    {
        return $this->config['git']['gitlab'] ?? [];
    }

    // Gitee 配置
    public function getGiteeConfig(): array
    {
        return $this->config['git']['gitee'] ?? [];
    }

    // GitHub 配置
    public function getGithubConfig(): array
    {
        return $this->config['git']['github'] ?? [];
    }

    // Gitea 配置
    public function getGiteaConfig(): array
    {
        return $this->config['git']['gitea'] ?? [];
    }

    // 应用环境
    public function getAppEnv(): string
    {
        return $this->config['app']['env'] ?? 'production';
    }

    // 日志路径
    public function getLogPath(): string
    {
        return $this->config['app']['log_path'] ?? '';
    }

    // API 外部访问地址（用于 Swagger UI / OpenAPI，不设返回空字符串由调用方自动推导）
    public function getApiBaseUrl(): string
    {
        return $this->config['app']['api_base_url'] ?? '';
    }

    // 当前实例系统类型：ci / cd / both
    public function getSystemType(): string
    {
        $type = $this->config['app']['system_type'] ?? self::SYSTEM_CI;
        return in_array($type, [self::SYSTEM_CI, self::SYSTEM_CD, self::SYSTEM_BOTH]) ? $type : self::SYSTEM_CI;
    }

    // CORS 配置
    public function getCorsConfig(): array
    {
        return $this->config['cors'] ?? ['allowed_origins' => ['*']];
    }

    // 手动映射 —— 从 SQLite 读写
    public function getJobGitMap(): array
    {
        $pdo = $this->getPdo();
        return $pdo->query("SELECT * FROM " . self::TABLE_JOB_GIT_MAP . " ORDER BY job_name")->fetchAll();
    }

    public function saveJobGitMap(array $data): void
    {
        $pdo = $this->getPdo();
        $cols = 'job_name,git_platform,build_provider,git_remote,project_id,web_url,current_path,harbor_repository,api_version,status';
        $upsertSql = \App\Service\Database::sqlUpsert(self::TABLE_JOB_GIT_MAP, $cols, '?,?,?,?,?,?,?,?,?,?');
        $upsertStmt = $pdo->prepare($upsertSql);

        $incomingNames = [];
        foreach ($data as $row) {
            if (empty($row['job_name'])) continue;
            $incomingNames[] = $row['job_name'];
            $upsertStmt->execute([
                $row['job_name'],
                $row['git_platform'] ?? null,
                $row['build_provider'] ?? self::PROVIDER_JENKINS,
                $row['git_remote'] ?? null,
                $row['project_id'] ?? null,
                $row['web_url'] ?? null,
                $row['current_path'] ?? null,
                $row['harbor_repository'] ?? null,
                $row['api_version'] ?? null,
                $row['status'] ?? self::STATUS_ACTIVE,
            ]);
        }

        // 删除 DB 中存在但传入数据里已移除的行（不再全表删除）
        if (!empty($incomingNames)) {
            $placeholders = implode(',', array_fill(0, count($incomingNames), '?'));
            $pdo->prepare("DELETE FROM " . self::TABLE_JOB_GIT_MAP . " WHERE job_name NOT IN ({$placeholders})")->execute($incomingNames);
        } else {
            $pdo->exec("DELETE FROM " . self::TABLE_JOB_GIT_MAP);
        }
    }

    /** 单条删除映射 */
    public function deleteJobGitMap(string $jobName): void
    {
        $pdo = $this->getPdo();
        $pdo->prepare("DELETE FROM " . self::TABLE_JOB_GIT_MAP . " WHERE job_name = ?")->execute([$jobName]);
    }

    // Harbor
    public function getHarborConfig(): array
    {
        return $this->config['harbor'] ?? [];
    }

    /**
     * 获取用户自定义 Git Provider 列表
     * @return array 每个元素包含 class (完整类名) 和 config (构造参数数组)
     */
    public function getCustomGitProviders(): array
    {
        return $this->config['git']['custom_providers'] ?? [];
    }

    // getGitPlatformsConfig 方法
    public function getGitPlatformsConfig(): array
    {
        $platforms = [];
        $gitConfig = $this->config['git'] ?? [];

        // 内置平台
        foreach (['gitlab', 'gitee', 'github', 'gitea'] as $name) {
            $cfg = $gitConfig[$name] ?? [];
            // 有 base_url 或 api_base_url 任一非空即认为已配置
            if (!empty($cfg['base_url']) || !empty($cfg['api_base_url'])) {
                $baseUrl = $cfg['api_base_url'] ?? $cfg['base_url'];
                $version = $cfg['api_version'] ?? ($this->getPlatformApiVersions()[$name] ?? $this->getDefaultApiVersion($name));

                // 拼接 API 版本路径（GitHub 除外：版本通过 HTTP header 传递）
                if ($name !== 'github') {
                    $expectedPath = '/api/' . $version;
                    if (strpos($baseUrl, $expectedPath) === false) {
                        $baseUrl = rtrim($baseUrl, '/') . $expectedPath;
                    }
                }

                $platforms[] = [
                    'name'         => $name,
                    'api_base_url' => $baseUrl,
                    'api_version'  => $version,
                ];
            }
        }

        // 自定义平台
        foreach ($this->getCustomGitProviders() as $provider) {
            $class = $provider['class'] ?? '';
            $cfg   = $provider['config'] ?? [];
            if (empty($class)) continue;

            $name    = $cfg['name'] ?? strtolower(substr(strrchr($class, '\\'), 1));
            $baseUrl = $cfg['api_base_url'] ?? $cfg['base_url'] ?? '';
            $version = $cfg['api_version'] ?? 'custom';

            $platforms[] = [
                'name'         => $name,
                'api_base_url' => $baseUrl,
                'api_version'  => $version,
            ];
        }

        return $platforms;
    }

    // 获取 Harbor 的 API 配置
    public function getHarborApiInfo(): array
    {
        $harbor = $this->config['harbor'] ?? [];
        $baseUrl = rtrim($harbor['url'] ?? '', '/');
        $version = $harbor['api_version'] ?? ($this->getPlatformApiVersions()['harbor'] ?? 'v2.0');
        $expectedPath = '/api/' . $version;
        if (strpos($baseUrl, $expectedPath) === false) {
            $baseUrl .= $expectedPath;
        }
        return [
            'api_base_url' => $baseUrl,
            'api_version'  => $version,
        ];
    }

    // 按名称获取单个 Git 平台配置
    public function getGitPlatformConfig(string $name): array
    {
        return $this->config['git'][$name] ?? [];
    }

    /**
     * URL 无法匹配时使用的默认平台名
     */
    public function getDefaultGitPlatform(): string
    {
        return $this->config['git']['default_platform'] ?? 'gitlab';
    }

    // 判断某个平台是否已在配置中（用于 discovery 对比）
    public function isPlatformConfigured(string $platformName): bool
    {
        $cfg = $this->config['git'][$platformName] ?? null;
        if (!$cfg) return false;
        return !empty($cfg['base_url']) || !empty($cfg['api_base_url']);
    }

    /**
     * 根管理员用户名（从 .env ADMIN_USER 读取，默认 'admin'）
     * 这是唯一的根账号标识，所有权限判断都从这里取，不散落写死
     */
    public function getRootAdminUser(): string
    {
        return $this->config['admin']['user'] ?? 'admin';
    }

    /**
     * 管理后台登录凭证（从 .env 读取）
     */
    public function getAdminCredentials(): array
    {
        return [
            'user'     => $this->getRootAdminUser(),
            'password' => $this->config['admin']['password'] ?? '',
        ];
    }

    // ──────────────────── 平台 API 版本 ────────────────────

    private static array $DEFAULT_API_VERSIONS = [
        'gitlab' => 'v4',
        'gitee'  => 'v5',
        'github' => 'v3',
        'gitea'  => 'v1',
        'harbor' => 'v2.0',
    ];

    /** 获取所有平台的 API 版本（SQLite 覆盖默认值） */
    public function getPlatformApiVersions(): array
    {
        $enriched = $this->getPlatformApiVersionsWithSource();
        $result = [];
        foreach ($enriched as $name => $info) {
            $result[$name] = $info['value'];
        }
        return $result;
    }

    /**
     * 获取版本号 + 来源标识（供管理界面展示）
     * source: 'config' = settings.php 显式配置（最高优先级，UI 只读）
     *         'json'   = platform_versions.json（管理界面可改）
     *         'default'= 系统硬编码默认值（管理界面可覆盖）
     */
    public function getPlatformApiVersionsWithSource(): array
    {
        $result = [];
        foreach (self::$DEFAULT_API_VERSIONS as $name => $default) {
            $result[$name] = ['value' => $default, 'source' => 'default'];
        }

        // SQLite 覆盖默认
        try {
            $pdo = $this->getPdo();
            $rows = $pdo->query("SELECT platform, version FROM " . self::TABLE_PLATFORM_VERSIONS)->fetchAll();
            foreach ($rows as $r) {
                if (isset($result[$r['platform']])) {
                    $result[$r['platform']] = ['value' => $r['version'], 'source' => 'json'];
                }
            }
        } catch (\Exception $e) {
            // DB 不可用时保持默认
        }

        // settings.php 显式配置优先级最高
        foreach (['gitlab', 'gitee', 'github', 'gitea'] as $name) {
            $cfg = $this->config['git'][$name] ?? [];
            if (!empty($cfg['api_version'])) {
                $result[$name] = ['value' => $cfg['api_version'], 'source' => 'config'];
            }
        }
        if (!empty($this->config['harbor']['api_version'])) {
            $result['harbor'] = ['value' => $this->config['harbor']['api_version'], 'source' => 'config'];
        }

        return $result;
    }

    public function savePlatformApiVersions(array $data): void
    {
        $pdo = $this->getPdo();
        $pdo->exec("DELETE FROM " . self::TABLE_PLATFORM_VERSIONS);
        $stmt = $pdo->prepare("INSERT INTO " . self::TABLE_PLATFORM_VERSIONS . " (platform, version) VALUES (?, ?)");
        foreach ($data as $name => $ver) {
            $default = self::$DEFAULT_API_VERSIONS[$name] ?? null;
            if ($ver !== $default && $ver !== '' && $ver !== null) {
                $stmt->execute([$name, $ver]);
            }
        }
    }

    // ─── 构建系统模式（数据库为唯一来源） ───

    /**
     * 获取构建模式。
     * 逻辑：DB ci_app_settings 表 → 返回。若 DB 无记录（首次运行），从 .env 取种子值写入 DB，然后返回。
     * 此后 DB 为唯一真相来源，.env 不再参与运行时决策。
     */
    public function getBuildMode(): string
    {
        try {
            $pdo = $this->getPdo();
            $row = $pdo->query("SELECT value FROM " . self::TABLE_APP_SETTINGS . " WHERE setting_key = 'build_mode'")->fetch();
            if ($row && in_array($row['value'], [self::BUILD_MODE_JENKINS, self::BUILD_MODE_GITLAB_CI, self::BUILD_MODE_BOTH])) {
                return $row['value'];
            }
            // DB 无记录 → 首次运行，以 .env 为种子写入 DB
            $envMode = $_ENV['BUILD_MODE'] ?? self::BUILD_MODE_BOTH;
            $this->setBuildMode($envMode);
            return $envMode;
        } catch (\Exception $e) {
            // DB 彻底不可用时的最后兜底
            return $_ENV['BUILD_MODE'] ?? self::BUILD_MODE_BOTH;
        }
    }

    /**
     * 获取当前构建模式的来源：'database' | 'env'
     */
    public function getBuildModeSource(): string
    {
        try {
            $pdo = $this->getPdo();
            $row = $pdo->query("SELECT value FROM " . self::TABLE_APP_SETTINGS . " WHERE setting_key = 'build_mode'")->fetch();
            if ($row && in_array($row['value'], [self::BUILD_MODE_JENKINS, self::BUILD_MODE_GITLAB_CI, self::BUILD_MODE_BOTH])) {
                return 'database';
            }
        } catch (\Exception $e) {
            \App\Helper\Log::exception($e);
        }
        return 'env';
    }

    /**
     * 设置构建模式（写入 app_settings 表）
     */
    public function setBuildMode(string $mode): void
    {
        if (!in_array($mode, [self::BUILD_MODE_JENKINS, self::BUILD_MODE_GITLAB_CI, self::BUILD_MODE_BOTH])) {
            throw new \InvalidArgumentException("Invalid build mode: {$mode}");
        }
        $pdo = $this->getPdo();
        $sql = \App\Service\Database::sqlUpsert(self::TABLE_APP_SETTINGS, 'setting_key, value, updated_at', '?, ?, ' . \App\Service\Database::sqlNow());
        $pdo->prepare($sql)->execute(['build_mode', $mode]);
    }

    // 私有：获取平台默认 API 版本
    private function getDefaultApiVersion(string $platform): string
    {
        return self::$DEFAULT_API_VERSIONS[$platform] ?? 'unknown';
    }
}
