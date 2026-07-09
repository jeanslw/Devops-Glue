<?php
namespace App\Service\Git;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use App\Service\Logger;

/**
 * Gitea API v1 适配器
 *
 * Gitea 是自建 Git 服务，API 路径为 /api/v1/，认证方式与 GitHub 兼容。
 * 官方文档: https://docs.gitea.com/api/
 */
class GiteaService implements GitProviderInterface
{
    private Client $client;
    private string $baseUrl;
    private ?Logger $logger;

    public function __construct(string $baseUrl, string $token, ?Logger $logger = null)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->logger  = $logger;
        $this->client = new Client([
            'headers' => ['Authorization' => 'token ' . $token],
            'timeout' => 15,
        ]);
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
        // repository 格式: owner/repo
        $parts = explode('/', $repository, 2);
        $owner = $parts[0] ?? '';
        $repo  = $parts[1] ?? '';
        if (empty($owner) || empty($repo)) {
            $this->logger?->warning('Gitea 仓库路径解析失败', ['repository' => $repository]);
            return [];
        }

        $url = "{$this->baseUrl}/api/v1/repos/{$owner}/{$repo}/branches";
        try {
            $response = $this->client->get($url);
            $branches = json_decode($response->getBody(), true);
            return array_column($branches, 'name');
        } catch (GuzzleException $e) {
            $this->logger?->warning('Gitea 分支查询失败', [
                'repository' => $repository,
                'error'      => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
