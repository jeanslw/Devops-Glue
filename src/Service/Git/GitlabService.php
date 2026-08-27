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

    /**
     * $repository 已由上游 GitService::parseRepositoryPath() 预先 urlencode
     * （形如 group%2Frepo）。此处必须原样拼接入 URL，严禁再次 urlencode，
     * 否则会把 %2F 二次编码成 %252F，导致 GitLab API 找不到项目。
     */
    public function getBranches(string $repository): array
    {
        $path = "/api/v4/projects/{$repository}/repository/branches";
        return $this->paginatedList($path, 'name');
    }

    /**
     * @see getBranches()：$repository 同为上游已 urlencode 的 path，不得二次编码。
     */
    public function getTags(string $repository): array
    {
        $path = "/api/v4/projects/{$repository}/repository/tags";
        return $this->paginatedList($path, 'name');
    }

    public function setCommitStatus(string $repository, string $sha, string $state, string $context, string $description, string $targetUrl = ''): array
    {
        // 注意：本方法入参与 getBranches/getTags 不同——调用方（BuildController）三级回退传入
        // 的是「未编码」值（数字 project_id，或原始 owner/repo 路径），故需在此 urlencode。
        // 纯数字 project_id 经 urlencode 无副作用；原始路径则被正确编码为 group%2Frepo。
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
