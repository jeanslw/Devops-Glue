<?php
namespace App\Service\Git;

/**
 * Git Provider 工厂（向后兼容封装）
 *
 * 新版代码请直接使用 ProviderRegistry::create()。
 * 本类保留用于外部自定义集成场景，内部委托给 ProviderRegistry。
 */
class GitProviderFactory
{
    private ?ProviderRegistry $registry = null;

    /**
     * 注入 ProviderRegistry（由 DI 容器在组装时调用）
     */
    public function setRegistry(ProviderRegistry $registry): void
    {
        $this->registry = $registry;
    }

    /**
     * 根据平台名创建 Provider 实例
     *
     * @param string $platform      平台名 (gitlab|gitee|github|gitea)
     * @param array  $gitlabConfig  已废弃 — 由 Registry 内部的 factory 闭包自行读取
     * @param array  $giteeConfig   已废弃
     * @param array  $githubConfig  已废弃
     * @return GitProviderInterface
     */
    public function create(string $platform, array $gitlabConfig = [], array $giteeConfig = [], array $githubConfig = []): GitProviderInterface
    {
        if ($this->registry === null) {
            // 降级：直接构造（兼容未注入 Registry 的场景）
            return $this->createFallback($platform, $gitlabConfig, $giteeConfig, $githubConfig);
        }
        return $this->registry->create($platform);
    }

    /**
     * 降级模式：Registry 未注入时直接构造 Provider
     */
    private function createFallback(string $platform, array $gitlabConfig, array $giteeConfig, array $githubConfig): GitProviderInterface
    {
        return match ($platform) {
            'gitlab' => new GitlabService(
                $gitlabConfig['base_url'] ?? '',
                $gitlabConfig['token'] ?? ''
            ),
            'gitee'  => new GiteeService(
                $giteeConfig['base_url'] ?? 'https://gitee.com/api/v5',
                $giteeConfig['token'] ?? ''
            ),
            'github' => new GithubService(
                $githubConfig['base_url'] ?? 'https://api.github.com',
                $githubConfig['token'] ?? ''
            ),
            'gitea'  => new GiteaService(
                $githubConfig['base_url'] ?? '',
                $githubConfig['token'] ?? ''
            ),
            default => throw new \InvalidArgumentException("不支持的 Git 平台: {$platform}，当前支持: gitlab, gitee, github, gitea"),
        };
    }
}
