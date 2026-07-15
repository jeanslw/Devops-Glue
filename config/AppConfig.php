<?php
namespace App\Config;

class AppConfig
{
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

    // CORS 配置
    public function getCorsConfig(): array
    {
        return $this->config['cors'] ?? ['allowed_origins' => ['*']];
    }

    // 手动映射 —— 从 SQLite 读写
    public function getJobGitMap(): array
    {
        $pdo = \App\Service\Database::getPdo();
        return $pdo->query("SELECT * FROM ci_job_git_map ORDER BY job_name")->fetchAll();
    }

    public function saveJobGitMap(array $data): void
    {
        $pdo = \App\Service\Database::getPdo();
        $pdo->exec("DELETE FROM ci_job_git_map");
        $stmt = $pdo->prepare("INSERT INTO ci_job_git_map (job_name,git_platform,build_provider,git_remote,project_id,web_url,current_path,harbor_repository,api_version,status) VALUES (?,?,?,?,?,?,?,?,?,?)");
        foreach ($data as $row) {
            if (empty($row['job_name'])) continue;
            $stmt->execute([
                $row['job_name'],
                $row['git_platform'] ?? null,
                $row['build_provider'] ?? 'jenkins',
                $row['git_remote'] ?? null,
                $row['project_id'] ?? null,
                $row['web_url'] ?? null,
                $row['current_path'] ?? null,
                $row['harbor_repository'] ?? null,
                $row['api_version'] ?? null,
                $row['status'] ?? 'active',
            ]);
        }
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
     * 管理后台登录凭证（从 .env 读取）
     */
    public function getAdminCredentials(): array
    {
        return [
            'user'     => $this->config['admin']['user'] ?? 'admin',
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
            $rows = $pdo->query("SELECT platform, version FROM ci_platform_versions")->fetchAll();
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
        $pdo->exec("DELETE FROM ci_platform_versions");
        $stmt = $pdo->prepare("INSERT INTO ci_platform_versions (platform, version) VALUES (?, ?)");
        foreach ($data as $name => $ver) {
            $default = self::$DEFAULT_API_VERSIONS[$name] ?? null;
            if ($ver !== $default && $ver !== '' && $ver !== null) {
                $stmt->execute([$name, $ver]);
            }
        }
    }

    // 私有：获取平台默认 API 版本
    private function getDefaultApiVersion(string $platform): string
    {
        return self::$DEFAULT_API_VERSIONS[$platform] ?? 'unknown';
    }
}
