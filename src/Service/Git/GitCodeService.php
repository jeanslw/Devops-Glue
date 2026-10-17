<?php

namespace App\Service\Git;

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
class GitCodeService extends AbstractGitProvider
{
    /**
     * @param array<string,mixed> $config
     */
    public function __construct(array $config, ?Logger $logger = null)
    {
        $this->initClient(
            $config['base_url'] ?? '',
            ['PRIVATE-TOKEN' => trim($config['token'] ?? '')],
            $logger
        );
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
        return $this->paginatedList("/repos/{$repository}/branches", 'per_page');
    }

    public function getTags(string $repository): array
    {
        return $this->paginatedList("/repos/{$repository}/tags", 'per_page');
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
}
