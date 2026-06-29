<?php
namespace App\Service\Git;

use GuzzleHttp\Client;

class GiteeService implements GitProviderInterface
{
    private Client $client;
    private string $baseUrl;

    public function __construct(string $baseUrl, string $token)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->client = new Client([
            'headers' => ['Authorization' => 'token ' . $token],
            'timeout' => 15,
        ]);
    }

    public function getBranches(string $repository): array
    {
        // repository 格式 owner/repo，如 mindev/myapp
        $url = "{$this->baseUrl}/repos/{$repository}/branches";
        $response = $this->client->get($url);
        $branches = json_decode($response->getBody(), true);
        return array_column($branches, 'name');
    }
}