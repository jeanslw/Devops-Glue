<?php
namespace App\Service\Git;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use App\Service\Logger;

class GiteeService implements GitProviderInterface
{
    private Client $client;
    private string $baseUrl;
    private string $token;
    private ?Logger $logger;

    private const PAGE_SIZE = 100;
    private const MAX_PAGES = 20; // 最多 2000 条

    public function __construct(string $baseUrl, string $token, ?Logger $logger = null)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->token   = trim($token);
        $this->logger  = $logger;
        $this->client = new Client(['timeout' => 15]);
    }

    private function request(string $method, string $url, array $options = []): \Psr\Http\Message\ResponseInterface
    {
        $query = $options['query'] ?? [];
        if ($this->token !== '') {
            $query['access_token'] = $this->token;
        }
        $options['query'] = $query;

        return $this->client->request($method, $url, $options);
    }

    public function getName(): string
    {
        return 'gitee';
    }

    public function matchUrl(string $url): bool
    {
        return str_contains($url, 'gitee.com') || str_contains($url, 'gitee');
    }

    public function getApiVersion(): string
    {
        return 'v5';
    }

    public function getBranches(string $repository): array
    {
        return $this->paginatedList("/repos/{$repository}/branches", 'name');
    }

    public function getTags(string $repository): array
    {
        return $this->paginatedList("/repos/{$repository}/tags", 'name');
    }

    public function setCommitStatus(string $repository, string $sha, string $state, string $context, string $description, string $targetUrl = ''): array
    {
        $body = [
            'state'       => $state,
            'description' => $description,
            'context'     => $context,
        ];
        if ($targetUrl) $body['target_url'] = $targetUrl;

        try {
            $url = "{$this->baseUrl}/repos/{$repository}/commits/{$sha}/statuses";
            $response = $this->request('POST', $url, ['json' => $body]);
            return [
                'success' => $response->getStatusCode() < 400,
                'message' => $response->getStatusCode() < 400 ? 'status 已回写' : '回写失败',
            ];
        } catch (GuzzleException $e) {
            $this->logger?->warning('Gitee commit status 回写失败', [
                'repository' => $repository, 'sha' => $sha, 'error' => $e->getMessage(),
            ]);
            $hint = 'Gitee 公开版不支持 commit status API，企业版未知';
            return ['success' => false, 'message' => '回写失败: ' . $e->getMessage() . '（' . $hint . '）'];
        }
    }

    /** 通用分页列表获取 */
    private function paginatedList(string $path, string $key): array
    {
        $all = [];
        $page = 1;
        do {
            $url = "{$this->baseUrl}{$path}";
            try {
                $response = $this->request('GET', $url, ['query' => ['per_page' => self::PAGE_SIZE, 'page' => $page]]);
                $data = json_decode($response->getBody(), true);
                if (!is_array($data) || empty($data)) break;
                $all = array_merge($all, array_column($data, $key));
                $page++;
            } catch (GuzzleException $e) {
                $this->logger?->warning('Gitee API 请求失败', [
                    'path' => $path, 'page' => $page, 'error' => $e->getMessage(),
                ]);
                return $page === 1 ? [] : $all;
            }
        } while (count($data) === self::PAGE_SIZE && $page <= self::MAX_PAGES);

        if ($page > self::MAX_PAGES) {
            $this->logger?->warning('Gitee 列表达到最大分页上限', [
                'path' => $path, 'max_pages' => self::MAX_PAGES, 'total' => count($all),
            ]);
        }
        return $all;
    }
}
