<?php

namespace App\Service\Git;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\ServerException;
use App\Service\Logger;

/**
 * Bitbucket Cloud API v2.0 适配器（自定义平台，经 config/settings.php 的 git.custom_providers 注册）。
 *
 * 官方文档: https://developer.atlassian.com/cloud/bitbucket/rest/api-group-refs/
 * 认证：配置 username 时走 App Password（Basic base64(username:app_password)），
 *       仅配 token 时退化为 Bearer（OAuth / 仓库访问令牌）。
 * 仓库标识为 {workspace}/{repo_slug} 两段，与上游下传的 owner/repo 一致。
 */
class BitbucketService implements GitProviderInterface
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

        $token    = trim($config['token'] ?? '');
        $username = trim($config['username'] ?? '');
        $headers  = [];
        if ($username !== '') {
            // App Password：username 为 Bitbucket 账号名，token 为 app password
            $headers['Authorization'] = 'Basic ' . base64_encode($username . ':' . $token);
        } elseif ($token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        $this->client = new Client([
            'headers'         => $headers,
            'timeout'         => 15,
            'connect_timeout' => 10,
        ]);
    }

    public function getName(): string
    {
        return 'bitbucket';
    }

    public function matchUrl(string $url): bool
    {
        return str_contains($url, 'bitbucket.org') || str_contains($url, 'bitbucket');
    }

    public function getApiVersion(): string
    {
        return '2.0';
    }

    public function getBranches(string $repository): array
    {
        return $this->refs($repository, 'branches');
    }

    public function getTags(string $repository): array
    {
        return $this->refs($repository, 'tags');
    }

    /**
     * Bitbucket refs 端点（branches/tags 同构）：响应包一层 values，仅取 name。
     * @return list<string>
     */
    private function refs(string $repository, string $kind): array
    {
        [$workspace, $slug] = $this->splitRepo($repository);
        if ($workspace === '' || $slug === '') {
            $this->logger?->warning('Bitbucket 仓库路径解析失败', ['repository' => $repository]);
            return [];
        }
        return $this->paginatedList("/repositories/{$workspace}/{$slug}/refs/{$kind}");
    }

    /**
     * @return array{success:bool, message:string}
     */
    public function setCommitStatus(string $repository, string $sha, string $state, string $context, string $description, string $targetUrl = ''): array
    {
        [$workspace, $slug] = $this->splitRepo($repository);
        if ($workspace === '' || $slug === '') {
            return ['success' => false, 'message' => '仓库路径格式错误'];
        }

        // Bitbucket build status 的 state 为大写枚举，且无 error/failed 之分，失败统一映射到 FAILED
        $bbState = match ($state) {
            'pending' => 'INPROGRESS',
            'success' => 'SUCCESSFUL',
            'failed'  => 'FAILED',
            'error'   => 'FAILED',
            default   => 'FAILED',
        };
        // key 是同一 commit 下的唯一标识，仅允许 [A-Z0-9_]
        $key = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', $context) ?? '');
        if ($key === '') {
            $key = 'BUILD';
        }

        $body = [
            'state'       => $bbState,
            'key'         => $key,
            'name'        => $context,
            'description' => $description,
        ];
        if ($targetUrl) {
            $body['url'] = $targetUrl;
        }

        try {
            $url = "{$this->baseUrl}/repositories/{$workspace}/{$slug}/commit/{$sha}/statuses/build";
            $response = $this->client->post($url, ['json' => $body]);
            return [
                'success' => $response->getStatusCode() < 400,
                'message' => $response->getStatusCode() < 400 ? 'status 已回写' : '回写失败',
            ];
        } catch (GuzzleException $e) {
            $this->logger?->warning('Bitbucket commit status 回写失败', [
                'repository' => $repository, 'sha' => $sha, 'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'message' => '回写失败: ' . $e->getMessage()];
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitRepo(string $repository): array
    {
        $parts = explode('/', $repository, 2);
        return [$parts[0], $parts[1] ?? ''];
    }

    /**
     * 通用分页列表获取（Bitbucket 用 pagelen/page，响应为 {values: [...]}）。
     * @return list<string>
     */
    private function paginatedList(string $path): array
    {
        $all = [];
        $page = 1;
        do {
            $url = "{$this->baseUrl}{$path}?pagelen=" . self::PAGE_SIZE . "&page={$page}";
            try {
                $response = $this->client->get($url);
                $data   = json_decode($response->getBody(), true);
                $values = is_array($data) ? ($data['values'] ?? []) : [];
                if (!is_array($values) || empty($values)) {
                    break;
                }
                $all = array_merge($all, array_column($values, 'name'));
                $page++;
            } catch (ConnectException | ServerException $e) {
                // 连接级失败（拒绝/DNS/超时）与 5xx：上抛给 GitService 统一包装为平台不可达（502），
                // 不得吞成空数组（否则与仓库无分支无法区分）
                $this->logger?->warning('Bitbucket 平台不可达', [
                    'path' => $path, 'page' => $page, 'error' => $e->getMessage(),
                ]);
                throw $e;
            } catch (GuzzleException $e) {
                $this->logger?->warning('Bitbucket API 请求失败', [
                    'path' => $path, 'page' => $page, 'error' => $e->getMessage(),
                ]);
                return $page === 1 ? [] : $all;
            }
        } while (count($values) === self::PAGE_SIZE && $page <= self::MAX_PAGES);

        if ($page > self::MAX_PAGES) {
            $this->logger?->warning('Bitbucket 列表达到最大分页上限', [
                'path' => $path, 'max_pages' => self::MAX_PAGES, 'total' => count($all),
            ]);
        }
        return $all;
    }
}
