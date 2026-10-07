<?php

namespace App\Service\Git;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\ServerException;
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
        $this->client = new Client(['timeout' => 15, 'connect_timeout' => 10]);
    }

    /**
     * @param array<string,mixed> $options
     * @return \Psr\Http\Message\ResponseInterface
     */
    private function request(string $method, string $url, array $options = []): \Psr\Http\Message\ResponseInterface
    {
        // 凭证放 Authorization 头，避免 token 进入 URL/代理访问日志
        if ($this->token !== '') {
            $options['headers'] = array_merge($options['headers'] ?? [], ['Authorization' => 'token ' . $this->token]);
        }

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

    /**
     * @return array{success:bool, message:string}
     */
    public function setCommitStatus(string $repository, string $sha, string $state, string $context, string $description, string $targetUrl = ''): array
    {
        $body = [
            'state'       => $state,
            'description' => $description,
            'context'     => $context,
        ];
        if ($targetUrl) {
            $body['target_url'] = $targetUrl;
        }

        try {
            $url = "{$this->baseUrl}/repos/{$repository}/commits/{$sha}/statuses";
            $response = $this->request('POST', $url, ['json' => $body]);
            return [
                'success' => $response->getStatusCode() < 400,
                'message' => $response->getStatusCode() < 400 ? 'status 已回写' : '回写失败',
            ];
        } catch (ConnectException | ServerException $e) {
            // 连接级失败与「公开版不支持 API」是两回事：不可达必须如实上报，
            // 不得误报为平台能力限制（原始异常仍只进服务端日志，不下发）
            $this->logger?->warning('Gitee commit status 回写失败（服务不可达）', [
                'repository' => $repository, 'sha' => $sha, 'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'message' => '回写失败: Gitee 服务不可达'];
        } catch (GuzzleException $e) {
            // 原始异常（含完整 URL / repo / SHA）只进服务端日志，绝不下发到接口响应或落库，
            // 避免把内部 Git 拓扑泄露给 API 调用方。
            $this->logger?->warning('Gitee commit status 回写失败', [
                'repository' => $repository, 'sha' => $sha, 'error' => $e->getMessage(),
            ]);
            $hint = 'Gitee 公开版不支持 commit status API（企业版未知）';
            return ['success' => false, 'message' => $hint];
        }
    }

    /**
     * 通用分页列表获取
     * @return list<string>
     */
    private function paginatedList(string $path, string $key): array
    {
        $all = [];
        $page = 1;
        do {
            $url = "{$this->baseUrl}{$path}";
            try {
                $response = $this->request('GET', $url, ['query' => ['per_page' => self::PAGE_SIZE, 'page' => $page]]);
                $data = json_decode($response->getBody(), true);
                if (!is_array($data) || empty($data)) {
                    break;
                }
                $all = array_merge($all, array_column($data, $key));
                $page++;
            } catch (ConnectException | ServerException $e) {
                // 连接级失败（拒绝/DNS/超时）与 5xx：上抛给 GitService 统一包装为
                // 「平台不可达」（→ 502），不得吞成空数组（否则与「仓库无分支」无法区分）
                $this->logger?->warning('Gitee 平台不可达', [
                    'path' => $path, 'page' => $page, 'error' => $e->getMessage(),
                ]);
                throw $e;
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
