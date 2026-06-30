<?php
namespace App\Service\Git;

class GitProviderFactory
{
    public static function create(string $platform, array $gitlabConfig, array $giteeConfig): GitProviderInterface
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
            default => throw new \InvalidArgumentException("Unsupported git platform: $platform"),
        };
    }
}