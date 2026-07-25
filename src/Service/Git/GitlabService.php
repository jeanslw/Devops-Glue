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

    private const PAGE_SIZE = 100;
    private const MAX_PAGES = 20; // 最多 2000 条

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
        $path = "/api/v4/projects/{$repository}/repository/branches";
        return $this->paginatedList($path, 'name');
    }

    public function getTags(string $repository): array
    {
        $path = "/api/v4/projects/{$repository}/repository/tags";
        return $this->paginatedList($path, 'name');
    }

    public function setCommitStatus(string $repository, string $sha, string $state, string $context, string $description, string $targetUrl = ''): array
    {
        $encoded = urlencode($repository);
        $url = "{$this->baseUrl}/api/v4/projects/{$encoded}/statuses/{$sha}";
        try {
            $body = [
                'state'       => $state,
                'name'        => $context,
                'description' => $description,
            ];
            if ($targetUrl) $body['target_url'] = $targetUrl;

            $response = $this->client->post($url, ['json' => $body]);
            return [
                'success' => $response->getStatusCode() < 400,
                'message' => $response->getStatusCode() < 400 ? 'status 已回写' : '回写失败',
            ];
        } catch (GuzzleException $e) {
            $this->logger?->warning('GitLab commit status 回写失败', [
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
            $url = "{$this->baseUrl}{$path}?per_page=" . self::PAGE_SIZE . "&page={$page}";
            try {
                $response = $this->client->get($url);
                $data = json_decode($response->getBody(), true);
                if (!is_array($data) || empty($data)) break;
                $all = array_merge($all, array_column($data, $key));
                $page++;
            } catch (GuzzleException $e) {
                $this->logger?->warning('GitLab API 请求失败', [
                    'path' => $path, 'page' => $page, 'error' => $e->getMessage(),
                ]);
                return $page === 1 ? [] : $all;
            }
        } while (count($data) === self::PAGE_SIZE && $page <= self::MAX_PAGES);

        if ($page > self::MAX_PAGES) {
            $this->logger?->warning('GitLab 列表达到最大分页上限', [
                'path' => $path, 'max_pages' => self::MAX_PAGES, 'total' => count($all),
            ]);
        }
        return $all;
    }
}
