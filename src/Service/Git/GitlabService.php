<?php
namespace App\Service\Git;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use App\Service\Logger;

class GitlabService implements GitProviderInterface
{
    private Client $client;
    private string $baseUrl;
    private ?Logger $logger;

    public function __construct(string $baseUrl, string $token, ?Logger $logger = null)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->logger  = $logger;
        $this->client = new Client([
            'headers' => ['PRIVATE-TOKEN' => $token],
            'timeout' => 15,
        ]);
    }

    public function getName(): string
    {
        return 'gitlab';
    }

    public function matchUrl(string $url): bool
    {
        return str_contains($url, 'gitlab');
    }

    public function getApiVersion(): string
    {
        return 'v4';
    }

    public function getBranches(string $repository): array
    {
        $url = "{$this->baseUrl}/api/v4/projects/{$repository}/repository/branches";
        try {
            $response = $this->client->get($url);
            $branches = json_decode($response->getBody(), true);
            return array_column($branches, 'name');
        } catch (GuzzleException $e) {
            $this->logger?->warning('GitLab 分支查询失败', [
                'repository' => $repository,
                'error'      => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
