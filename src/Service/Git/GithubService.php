<?php

namespace App\Service\Git;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use App\Service\Logger;

class GithubService implements GitProviderInterface
{
    private Client $client;
    private string $baseUrl;
    private string $token;
    private ?Logger $logger;

    private const MAX_PAGES = 20; // 最多 2000 条分支，防止极端仓库触发 rate limit
    private const PER_PAGE  = 100;

    public function __construct(string $baseUrl, string $token, ?Logger $logger = null)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->token   = $token;
        $this->logger  = $logger;
        $this->client  = new Client([
            'headers' => [
                'Authorization'        => 'token ' . $token,
                'Accept'               => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
            ],
            'timeout' => 15,
        ]);
    }

    public function getName(): string
    {
        return 'github';
    }

    public function matchUrl(string $url): bool
    {
        return str_contains($url, 'github.com') || str_contains($url, 'github');
    }

    public function getApiVersion(): string
    {
        return 'v3'; // 显示用；实际 API 调用使用 X-GitHub-Api-Version header
    }

    public function getBranches(string $repository): array
    {
        // repository 格式: owner/repo (与 GitLab/Gitee 保持统一)
        $parts = explode('/', $repository, 2);
        $owner = $parts[0] ?? '';
        $repo  = $parts[1] ?? '';
        if (empty($owner) || empty($repo)) {
            $this->logger?->warning('GitHub 仓库路径解析失败', ['repository' => $repository]);
            return [];
        }

        $branches = [];
        $page = 1;

        do {
            $data = $this->request(
                'GET',
                "/repos/{$owner}/{$repo}/branches?per_page=" . self::PER_PAGE . "&page={$page}"
            );

            if (isset($data['error']) || !is_array($data) || empty($data)) {
                break;
            }

            foreach ($data as $branch) {
                if (!empty($branch['name'])) {
                    $branches[] = $branch['name'];
                }
            }

            $page++;
        } while (count($data) === self::PER_PAGE && $page <= self::MAX_PAGES);

        if ($page > self::MAX_PAGES) {
            $this->logger?->warning('GitHub 分支查询达到最大分页上限', [
                'repository' => $repository,
                'max_pages'  => self::MAX_PAGES,
                'branches'   => count($branches),
            ]);
        }

        return $branches;
    }

    public function getProjectMeta(string $owner, string $repo): ?array
    {
        $data = $this->request('GET', "/repos/{$owner}/{$repo}");
        if (isset($data['error']) || empty($data['id'])) {
            return null;
        }
        return [
            'project_id'          => $data['id'],
            'web_url'             => $data['html_url'] ?? '',
            'path_with_namespace' => $data['full_name'] ?? '',
        ];
    }

    public function searchProject(string $keyword): ?array
    {
        if (strlen($keyword) < 2) {
            return null;
        }
        $searchEncoded = urlencode($keyword);
        $data = $this->request('GET', "/search/repositories?q={$searchEncoded}&per_page=5");
        if (isset($data['error'])) {
            return null;
        }
        $items = $data['items'] ?? [];
        if (is_array($items) && count($items) > 0) {
            return $this->formatProjectMeta($items[0]);
        }
        return null;
    }

    private function formatProjectMeta(array $data): array
    {
        return [
            'project_id'          => $data['id'],
            'web_url'             => $data['html_url'] ?? '',
            'path_with_namespace' => $data['full_name'] ?? '',
        ];
    }

    private function request(string $method, string $uri, array $options = []): array
    {
        try {
            $fullUrl = $this->baseUrl . '/' . ltrim($uri, '/');
            $response = $this->client->request($method, $fullUrl, $options);
            $body = (string) $response->getBody();
            return json_decode($body, true) ?? [];
        } catch (GuzzleException $e) {
            $this->logger?->warning('GitHub API 请求失败', [
                'method' => $method,
                'uri'    => $uri,
                'error'  => $e->getMessage(),
            ]);
            return ['error' => $e->getMessage(), 'code' => $e->getCode()];
        }
    }
}
