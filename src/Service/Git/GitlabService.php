<?php
namespace App\Service\Git;

use GuzzleHttp\Client;

class GitlabService implements GitProviderInterface
{
    private Client $client;
    private string $baseUrl;

    public function __construct(string $baseUrl, string $token)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->client = new Client([
            'headers' => ['PRIVATE-TOKEN' => $token],
            'timeout' => 15,
        ]);
    }

    public function getBranches(string $repository): array
    {
        // repository 需要是 URL 编码后的项目全路径，如 group%2Fproject
        $url = "{$this->baseUrl}/api/v4/projects/{$repository}/repository/branches";
        $response = $this->client->get($url);
        $branches = json_decode($response->getBody(), true);
        return array_column($branches, 'name');
    }
}