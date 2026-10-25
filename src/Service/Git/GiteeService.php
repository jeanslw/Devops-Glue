<?php

namespace App\Service\Git;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\ServerException;
use App\Service\Logger;

class GiteeService extends AbstractGitProvider
{
    public function __construct(string $baseUrl, string $token, ?Logger $logger = null)
    {
        $token   = trim($token);
        $headers = $token !== '' ? ['Authorization' => 'token ' . $token] : [];
        $this->initClient($baseUrl, $headers, $logger);
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
        $body = [
            'state'       => $state === 'failed' ? 'failure' : $state,
            'description' => $description,
            'context'     => $context,
        ];
        if ($targetUrl) {
            $body['target_url'] = $targetUrl;
        }

        try {
            $url = "{$this->baseUrl}/repos/{$repository}/commits/{$sha}/statuses";
            $response = $this->client->post($url, ['json' => $body]);
            return [
                'success' => $response->getStatusCode() < 400,
                'message' => $response->getStatusCode() < 400 ? 'status 已回写' : '回写失败',
            ];
        } catch (ConnectException | ServerException $e) {
            $this->logger?->warning('Gitee commit status 回写失败（服务不可达）', [
                'repository' => $repository, 'sha' => $sha, 'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'message' => '回写失败: Gitee 服务不可达'];
        } catch (GuzzleException $e) {
            $this->logger?->warning('Gitee commit status 回写失败', [
                'repository' => $repository, 'sha' => $sha, 'error' => $e->getMessage(),
            ]);
            $hint = 'Gitee 公开版不支持 commit status API（企业版未知）';
            return ['success' => false, 'message' => $hint];
        }
    }
}
