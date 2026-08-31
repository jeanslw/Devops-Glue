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

    private const PAGE_SIZE = 100;
    private const MAX_PAGES = 20; // 最多 2000 条

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
        $parts = explode('/', $repository, 2);
        $owner = $parts[0] ?? '';
        $repo  = $parts[1] ?? '';
        if (empty($owner) || empty($repo)) {
            $this->logger?->warning('Gitea 仓库路径解析失败', ['repository' => $repository]);
            return [];
        }
        return $this->paginatedList("/api/v1/repos/{$owner}/{$repo}/branches", 'name');
    }

    public function getTags(string $repository): array
    {
        $parts = explode('/', $repository, 2);
        $owner = $parts[0] ?? '';
        $repo  = $parts[1] ?? '';
        if (empty($owner) || empty($repo)) {
            $this->logger?->warning('Gitea 仓库路径解析失败', ['repository' => $repository]);
            return [];
        }
        return $this->paginatedList("/api/v1/repos/{$owner}/{$repo}/tags", 'name');
    }

    public function setCommitStatus(string $repository, string $sha, string $state, string $context, string $description, string $targetUrl = ''): array
    {
        $parts = explode('/', $repository, 2);
        $owner = $parts[0] ?? '';
        $repo  = $parts[1] ?? '';
        if (empty($owner) || empty($repo)) {
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

        try {
            $url = "{$this->baseUrl}/api/v1/repos/{$owner}/{$repo}/statuses/{$sha}";
            $response = $this->client->post($url, ['json' => $body]);
            return [
                'success' => $response->getStatusCode() < 400,
                'message' => $response->getStatusCode() < 400 ? 'status 已回写' : '回写失败',
            ];
        } catch (GuzzleException $e) {
            $this->logger?->warning('Gitea commit status 回写失败', [
                'repository' => $repository, 'sha' => $sha, 'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'message' => '回写失败: ' . $e->getMessage()];
        }
    }

    /** 通用分页列表获取 */
    private function paginatedList(string $path, string $key): array
    {
        $all = [];
        $page = 1;
        do {
            $url = "{$this->baseUrl}{$path}?limit=" . self::PAGE_SIZE . "&page={$page}";
            try {
                $response = $this->client->get($url);
                $data = json_decode($response->getBody(), true);
                if (!is_array($data) || empty($data)) {
                    break;
                }
                $all = array_merge($all, array_column($data, $key));
                $page++;
            } catch (GuzzleException $e) {
                $this->logger?->warning('Gitea API 请求失败', [
                    'path' => $path, 'page' => $page, 'error' => $e->getMessage(),
                ]);
                return $page === 1 ? [] : $all;
            }
        } while (count($data) === self::PAGE_SIZE && $page <= self::MAX_PAGES);

        if ($page > self::MAX_PAGES) {
            $this->logger?->warning('Gitea 列表达到最大分页上限', [
                'path' => $path, 'max_pages' => self::MAX_PAGES, 'total' => count($all),
            ]);
        }
        return $all;
    }
}
