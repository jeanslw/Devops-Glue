<?php

namespace App\Service\Git;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class GithubService implements GitProviderInterface
{
    private Client $client;
    private string $baseUrl;   // GitHub API base: https://api.github.com (公有云) 或 https://your-ghe.com/api/v3 (企业版)
    private string $token;

    public function __construct(Client $client, string $baseUrl, string $token)
    {
        $this->client  = $client;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->token   = $token;
    }

    public function getName(): string
    {
        return 'github';
    }

    public function supports(string $gitUrl): bool
    {
        // 支持 github.com 和企业版 GitHub 域名
        if (empty($this->baseUrl)) return false;
        $host = parse_url($this->baseUrl, PHP_URL_HOST);
        // 公有云兜底判断
        if (stripos($gitUrl, 'github.com') !== false) return true;
        return $host && stripos($gitUrl, $host) !== false;
    }

    private function request(string $method, string $uri, array $options = []): array
    {
        try {
            $fullUrl = $this->baseUrl . '/' . ltrim($uri, '/');
            if (!isset($options['headers'])) $options['headers'] = [];
            // GitHub 使用 Authorization: token xxx 或 Bearer xxx
            $options['headers']['Authorization'] = 'token ' . $this->token;
            $options['headers']['Accept']        = 'application/vnd.github.v3+json';
            $options['verify'] = false;

            $response = $this->client->request($method, $fullUrl, $options);
            $body = (string) $response->getBody();
            return json_decode($body, true) ?? [];
        } catch (GuzzleException $e) {
            return ['error' => $e->getMessage(), 'code' => $e->getCode()];
        }
    }

    public function parseProjectPath(string $gitUrl): string
    {
        // https://github.com/owner/repo.git → owner/repo
        // git@github.com:owner/repo.git → owner/repo
        $path = preg_replace('/\.git$/', '', $gitUrl);
        $parsed = parse_url($path);
        if (isset($parsed['path'])) return ltrim($parsed['path'], '/');
        if (preg_match('/:(.+)$/', $path, $matches)) return $matches[1];
        return '';
    }

    public function getBranches(string $owner, string $repo): array
    {
        $branches = [];
        $page = 1;

        // GitHub 分页获取，每页最多 100
        do {
            $data = $this->request('GET', "/repos/{$owner}/{$repo}/branches?per_page=100&page={$page}");
            if (isset($data['error'])) {
                return ['error' => 'GitHub API 请求失败: ' . $data['error']];
            }
            if (!is_array($data) || empty($data)) break;

            foreach ($data as $branch) {
                $branches[] = $branch['name'] ?? '';
            }
            $page++;
        } while (count($data) === 100);

        return array_filter($branches);
    }

    public function getProjectMeta(string $owner, string $repo): ?array
    {
        $data = $this->request('GET', "/repos/{$owner}/{$repo}");

        if (isset($data['error']) || empty($data['id'])) {
            return null;
        }

        return $this->formatProjectMeta($data);
    }

    public function searchProject(string $keyword): ?array
    {
        if (strlen($keyword) < 2) return null;

        $searchEncoded = urlencode($keyword);
        $data = $this->request('GET', "/search/repositories?q={$searchEncoded}&per_page=5");

        if (isset($data['error'])) return null;

        $items = $data['items'] ?? [];
        if (is_array($items) && count($items) > 0) {
            return $this->formatProjectMeta($items[0]);
        }
        return null;
    }

    private function formatProjectMeta(array $data): array
    {
        // GitHub: full_name = "owner/repo"
        return [
            'project_id'          => $data['id'],
            'web_url'             => $data['html_url'] ?? '',
            'path_with_namespace' => $data['full_name'] ?? ''
        ];
    }
}