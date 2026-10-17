<?php

namespace App\Service\Git;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\ServerException;
use App\Service\Logger;

/**
 * GitCode API v5 适配器（自定义平台，经 config/settings.php 的 git.custom_providers 注册）。
 *
 * GitCode 是 Gitee 风格的 REST API（路径 /repos/{owner}/{repo}/...，分页 per_page/page），
 * 认证为 GitLab 风格 PRIVATE-TOKEN。
 * 官方文档: https://docs.gitcode.com/docs/apis/
 */
class GitCodeService implements GitProviderInterface
{
    private Client $client;
    private string $baseUrl;
    private ?Logger $logger;

    private const PAGE_SIZE = 100;
    private const MAX_PAGES = 20; // 最多 2000 条

    /**
     * @param array<string,mixed> $config
     */
    public function __construct(array $config, ?Logger $logger = null)
    {
        $this->baseUrl = rtrim($config['base_url'] ?? '', '/');
        $this->logger  = $logger;
        $this->client = new Client([
            'headers'         => ['PRIVATE-TOKEN' => trim($config['token'] ?? '')],
            'timeout'         => 15,
            'connect_timeout' => 10,
        ]);
    }

    public function getName(): string
    {
        return 'gitcode';
    }

    public function matchUrl(string $url): bool
    {
        return str_contains($url, 'gitcode.com') || str_contains($url, 'gitcode');
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
        // GitCode 的 commit status 沿用 Gitea 语义：state 为 pending/success/error/failure。
        // 该端点需按实际 GitCode 实例的 OpenAPI 校验；不可达与 4xx 都如实返回失败，不吞。
        $gcState = $state === 'failed' ? 'failure' : $state;
        $body = [
            'state'       => $gcState,
            'context'     => $context,
            'description' => $description,
        ];
        if ($targetUrl) {
            $body['target_url'] = $targetUrl;
        }

        try {
            $url = "{$this->baseUrl}/repos/{$repository}/statuses/{$sha}";
            $response = $this->client->post($url, ['json' => $body]);
            return [
                'success' => $response->getStatusCode() < 400,
                'message' => $response->getStatusCode() < 400 ? 'status 已回写' : '回写失败',
            ];
        } catch (ConnectException | ServerException $e) {
            $this->logger?->warning('GitCode commit status 回写失败（服务不可达）', [
                'repository' => $repository, 'sha' => $sha, 'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'message' => '回写失败: GitCode 服务不可达'];
        } catch (GuzzleException $e) {
            $this->logger?->warning('GitCode commit status 回写失败', [
                'repository' => $repository, 'sha' => $sha, 'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'message' => '回写失败: ' . $e->getMessage()];
        }
    }

    /**
     * 通用分页列表获取（与 Gitee 相同：per_page/page，响应即数组）。
     * @return list<string>
     */
    private function paginatedList(string $path, string $key): array
    {
        $all = [];
        $page = 1;
        do {
            $url = "{$this->baseUrl}{$path}?per_page=" . self::PAGE_SIZE . "&page={$page}";
            try {
                $response = $this->client->get($url);
                $data = json_decode($response->getBody(), true);
                if (!is_array($data) || empty($data)) {
                    break;
                }
                $all = array_merge($all, array_column($data, $key));
                $page++;
            } catch (ConnectException | ServerException $e) {
                // 连接级失败（拒绝/DNS/超时）与 5xx：上抛给 GitService 统一包装为平台不可达（502），
                // 不得吞成空数组（否则与仓库无分支无法区分）
                $this->logger?->warning('GitCode 平台不可达', [
                    'path' => $path, 'page' => $page, 'error' => $e->getMessage(),
                ]);
                throw $e;
            } catch (GuzzleException $e) {
                $this->logger?->warning('GitCode API 请求失败', [
                    'path' => $path, 'page' => $page, 'error' => $e->getMessage(),
                ]);
                return $page === 1 ? [] : $all;
            }
        } while (count($data) === self::PAGE_SIZE && $page <= self::MAX_PAGES);

        if ($page > self::MAX_PAGES) {
            $this->logger?->warning('GitCode 列表达到最大分页上限', [
                'path' => $path, 'max_pages' => self::MAX_PAGES, 'total' => count($all),
            ]);
        }
        return $all;
    }
}
