<?php
namespace App\Service\Git;

class GitProviderFactory
{
    public static function create(string $platform, array $gitSettings): GitProviderInterface
    {
        return match ($platform) {
            'gitlab' => new GitlabService(
                $gitSettings['gitlab']['base_url'],
                $gitSettings['gitlab']['token']
            ),
            'gitee'  => new GiteeService(
                $gitSettings['gitee']['base_url'],
                $gitSettings['gitee']['token']
            ),
            default => throw new \InvalidArgumentException("Unsupported git platform: $platform"),
        };
    }
}