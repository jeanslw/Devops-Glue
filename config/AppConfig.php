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

    // CORS 配置
    public function getCorsConfig(): array
    {
        return $this->config['cors'] ?? ['allowed_origins' => ['*']];
    }

    // 手动映射 —— 从 JSON 文件读写，路径相对于 config/ 目录
    private function mapFilePath(): string
    {
        return __DIR__ . '/job_git_map.json';
    }

    public function getJobGitMap(): array
    {
        $file = $this->mapFilePath();
        if (!file_exists($file)) {
            // 首次启动自动创建空文件
            file_put_contents($file, "[]\n", LOCK_EX);
            return [];
        }
        $json = file_get_contents($file);
        if ($json === false) return [];
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }

    public function saveJobGitMap(array $data): void
    {
        $file = $this->mapFilePath();
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (file_put_contents($file, $json, LOCK_EX) === false) {
            throw new \RuntimeException("无法写入配置文件: {$file}");
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

    private function versionsFilePath(): string
    {
        return __DIR__ . '/platform_versions.json';
    }

    /**
     * 获取所有平台的 API 版本（JSON 覆盖默认值）
     */
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
        $jsonVersions = [];
        $file = $this->versionsFilePath();
        if (file_exists($file)) {
            $json = file_get_contents($file);
            if ($json !== false) {
                $data = json_decode($json, true);
                if (is_array($data)) $jsonVersions = $data;
            }
        }

        foreach (self::$DEFAULT_API_VERSIONS as $name => $default) {
            $result[$name] = ['value' => $default, 'source' => 'default'];
        }

        // JSON 覆盖默认
        foreach ($jsonVersions as $name => $ver) {
            if (isset($result[$name])) {
                $result[$name] = ['value' => $ver, 'source' => 'json'];
            }
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
        // 只保留有自定义值的（与默认不同的）
        $custom = [];
        foreach ($data as $name => $ver) {
            $default = self::$DEFAULT_API_VERSIONS[$name] ?? null;
            if ($ver !== $default && $ver !== '') {
                $custom[$name] = $ver;
            }
        }
        $file = $this->versionsFilePath();
        if (empty($custom)) {
            // 全部恢复默认时删除文件
            if (file_exists($file)) unlink($file);
            return;
        }
        $json = json_encode($custom, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (file_put_contents($file, $json, LOCK_EX) === false) {
            throw new \RuntimeException("无法写入配置文件: {$file}");
        }
    }

    // 私有：获取平台默认 API 版本
    private function getDefaultApiVersion(string $platform): string
    {
        return self::$DEFAULT_API_VERSIONS[$platform] ?? 'unknown';
    }
}
