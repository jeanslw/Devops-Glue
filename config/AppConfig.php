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

    // GitLab 配置（必须存在）
    public function getGitlabConfig(): array
    {
        return $this->config['git']['gitlab'] ?? [];
    }

    // Gitee 配置（必须存在）
    public function getGiteeConfig(): array
    {
        return $this->config['git']['gitee'] ?? [];
    }

    // GitHub 配置
    public function getGithubConfig(): array
    {
        return $this->config['git']['github'] ?? [];
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

    // getGitPlatformsConfig 方法
    public function getGitPlatformsConfig(): array
    {
        $platforms = [];
        $gitConfig = $this->config['git'] ?? [];
        foreach (['gitlab', 'gitee', 'github'] as $name) {
            $cfg = $gitConfig[$name] ?? [];
            // 有 base_url 或 api_base_url 任一非空即认为已配置
            if (!empty($cfg['base_url']) || !empty($cfg['api_base_url'])) {
                $baseUrl = $cfg['api_base_url'] ?? $cfg['base_url'];
                $version = $cfg['api_version'] ?? $this->getDefaultApiVersion($name);
                
                // 如果 URL 中不包含预期的 API 版本路径，则自动拼接
                $expectedPath = '/api/' . $version;
                if (strpos($baseUrl, $expectedPath) === false) {
                    $baseUrl = rtrim($baseUrl, '/') . $expectedPath;
                }
                
                $platforms[] = [
                    'name'         => $name,
                    'api_base_url' => $baseUrl,
                    'api_version'  => $version,
                ];
            }
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
            'github' => 'v3',
            default  => 'unknown',
        };
    }
}