<?php
namespace App\Config;

class AppConfig
{
    public const APP_VERSION = '2.4.0';

    // ── 表名常量 ──
    public const TABLE_JOB_GIT_MAP       = 'ci_job_git_map';
    public const TABLE_PIPELINE_TAGS     = 'ci_pipeline_tags';
    public const TABLE_SECURITY_CHECKS   = 'ci_security_checks';
    public const TABLE_ADMIN_USERS       = 'admin_users';
    public const TABLE_PLATFORM_VERSIONS = 'ci_platform_versions';
    public const TABLE_APP_SETTINGS      = 'ci_app_settings';
    public const TABLE_CACHE             = 'cache';

    // ── 角色常量 ──
    public const ROLE_SUPER_ADMIN = 'super_admin';
    public const ROLE_ADMIN       = 'admin';
    public const ROLE_DEPLOYER    = 'deployer';

    // ── 状态常量 ──
    public const STATUS_ACTIVE   = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_PENDING  = 'pending';

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
    public const TTL_TOKEN  = 86400;  // 登录 token 有效期（24h）
    public const TTL_CACHE  = 3600;   // 通用缓存有效期（1h）

    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
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
        $pdo = \App\Service\Database::getPdo();
        return $pdo->query("SELECT * FROM " . self::TABLE_JOB_GIT_MAP . " ORDER BY job_name")->fetchAll();
    }

    public function saveJobGitMap(array $data): void
    {
        $pdo = \App\Service\Database::getPdo();
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
        $pdo = \App\Service\Database::getPdo();
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
            $pdo = \App\Service\Database::getPdo();
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
        $pdo = \App\Service\Database::getPdo();
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
            $pdo = \App\Service\Database::getPdo();
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
            $pdo = \App\Service\Database::getPdo();
            $row = $pdo->query("SELECT value FROM " . self::TABLE_APP_SETTINGS . " WHERE setting_key = 'build_mode'")->fetch();
            if ($row && in_array($row['value'], [self::BUILD_MODE_JENKINS, self::BUILD_MODE_GITLAB_CI, self::BUILD_MODE_BOTH])) {
                return 'database';
            }
        } catch (\Exception $e) {}
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
        $pdo = \App\Service\Database::getPdo();
        $sql = \App\Service\Database::sqlUpsert(self::TABLE_APP_SETTINGS, 'setting_key, value, updated_at', '?, ?, ' . \App\Service\Database::sqlNow());
        $pdo->prepare($sql)->execute(['build_mode', $mode]);
    }

    // 私有：获取平台默认 API 版本
    private function getDefaultApiVersion(string $platform): string
    {
        return self::$DEFAULT_API_VERSIONS[$platform] ?? 'unknown';
    }
}
