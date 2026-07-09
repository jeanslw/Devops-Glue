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

    // 手动映射
    public function getJobGitMap(): array
    {
        return $this->config['job_git_map'] ?? [];
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
                $version = $cfg['api_version'] ?? $this->getDefaultApiVersion($name);

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
        $version = $harbor['api_version'] ?? 'v2.0';
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

    // 私有：获取平台默认 API 版本
    private function getDefaultApiVersion(string $platform): string
    {
        return match ($platform) {
            'gitlab' => 'v4',
            'gitee'  => 'v5',
            'github' => 'v3',     // 版本通过 HTTP header 传递，显示为 v3
            'gitea'  => 'v1',
            default  => 'unknown',
        };
    }
}
