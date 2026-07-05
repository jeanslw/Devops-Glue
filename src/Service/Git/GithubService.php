<?php

namespace App\Service\Git;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class GithubService implements GitProviderInterface
{
    private Client $client;
    private string $baseUrl;
    private string $token;

    public function __construct(string $baseUrl, string $token)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->token   = $token;
        $this->client  = new Client([
            'headers' => [
                'Authorization' => 'token ' . $token,
                'Accept'        => 'application/vnd.github.v3+json',
            ],
            'timeout' => 15,
        ]);
    }

    public function getBranches(string $repository): array
    {
        // repository 格式: owner/repo (与 GitLab/Gitee 保持统一)
        $parts = explode('/', $repository, 2);
        $owner = $parts[0] ?? '';
        $repo  = $parts[1] ?? '';
        if (empty($owner) || empty($repo)) {
            return [];
        }

        $branches = [];
        $page = 1;

        do {
            $data = $this->request('GET', "/repos/{$owner}/{$repo}/branches?per_page=100&page={$page}");
            if (isset($data['error']) || !is_array($data) || empty($data)) {
                break;
            }
            foreach ($data as $branch) {
                if (!empty($branch['name'])) {
                    $branches[] = $branch['name'];
                }
            }
            $page++;
        } while (count($data) === 100);

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
            return ['error' => $e->getMessage(), 'code' => $e->getCode()];
        }
    }
}
