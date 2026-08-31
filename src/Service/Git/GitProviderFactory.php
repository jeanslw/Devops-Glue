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
    public function __construct(private ProviderRegistry $registry)
    {
    }

    /**
     * 根据平台名创建 Provider 实例
     *
     * @param string $platform 平台名 (gitlab|gitee|github|gitea)
     * @return GitProviderInterface
     */
    public function create(string $platform): GitProviderInterface
    {
        return $this->registry->create($platform);
    }
}
