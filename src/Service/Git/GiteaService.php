<?php

namespace App\Service\Git;

use App\Service\Logger;

/**
 * Gitea API v1 适配器
 *
 * Gitea 是自建 Git 服务，API 路径为 /api/v1/，认证方式与 GitHub 兼容。
 * 官方文档: https://docs.gitea.com/api/
 */
class GiteaService extends AbstractGitProvider
{
    public function __construct(string $baseUrl, string $token, ?Logger $logger = null)
    {
        $this->initClient($baseUrl, ['Authorization' => 'token ' . $token], $logger);
    }

    public function getName(): string
    {
        return 'gitea';
    }

    public function matchUrl(string $url): bool
    {
        return str_contains($url, 'gitea');
    }

    public function getApiVersion(): string
    {
        return 'v1';
    }

    public function getBranches(string $repository): array
    {
        [$owner, $repo] = $this->splitOwnerRepo($repository);
        if ($owner === '' || $repo === '') {
            $this->logger?->warning('Gitea 仓库路径解析失败', ['repository' => $repository]);
            return [];
        }
        return $this->paginatedList("/api/v1/repos/{$owner}/{$repo}/branches", 'limit');
    }

    public function getTags(string $repository): array
    {
        [$owner, $repo] = $this->splitOwnerRepo($repository);
        if ($owner === '' || $repo === '') {
            $this->logger?->warning('Gitea 仓库路径解析失败', ['repository' => $repository]);
            return [];
        }
        return $this->paginatedList("/api/v1/repos/{$owner}/{$repo}/tags", 'limit');
    }

    /**
     * @return array{success:bool, message:string}
     */
    public function setCommitStatus(string $repository, string $sha, string $state, string $context, string $description, string $targetUrl = ''): array
    {
        [$owner, $repo] = $this->splitOwnerRepo($repository);
        if ($owner === '' || $repo === '') {
            return ['success' => false, 'message' => '仓库路径格式错误'];
        }
        $body = [
            'state'       => $state,
            'description' => $description,
            'context'     => $context,
        ];
        if ($targetUrl) {
            $body['target_url'] = $targetUrl;
        }
        return $this->postStatus("{$this->baseUrl}/api/v1/repos/{$owner}/{$repo}/statuses/{$sha}", $body);
    }
}
