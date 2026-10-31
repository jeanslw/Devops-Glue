<?php

namespace App\Service\Git;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\ServerException;
use App\Service\Logger;
use App\Service\I18nService;

class GiteeService extends AbstractGitProvider
{
    public function __construct(string $baseUrl, string $token, I18nService $i18n, ?Logger $logger = null)
    {
        $token   = trim($token);
        $headers = $token !== '' ? ['Authorization' => 'token ' . $token] : [];
        $this->i18n = $i18n;
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
                'message' => $response->getStatusCode() < 400 ? $this->i18n->trans('git.status_written') : $this->i18n->trans('git.writeback_failed'),
            ];
        } catch (ConnectException | ServerException $e) {
            $this->logger?->warning('Gitee commit status 回写失败（服务不可达）', [
                'repository' => $repository, 'sha' => $sha, 'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'message' => $this->i18n->trans('git.writeback_unreachable', ['name' => 'Gitee'])];
        } catch (GuzzleException $e) {
            $this->logger?->warning('Gitee commit status 回写失败', [
                'repository' => $repository, 'sha' => $sha, 'error' => $e->getMessage(),
            ]);
            $hint = $this->i18n->trans('git.gitee_no_commit_status_api');
            return ['success' => false, 'message' => $hint];
        }
    }
}
