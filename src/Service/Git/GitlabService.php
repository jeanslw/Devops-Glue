<?php

namespace App\Service\Git;

use App\Service\Logger;

class GitlabService extends AbstractGitProvider
{
    public function __construct(string $baseUrl, string $token, ?Logger $logger = null)
    {
        $this->initClient($baseUrl, ['PRIVATE-TOKEN' => $token], $logger);
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
        return $this->paginatedList("/api/v4/projects/{$repository}/repository/branches", 'per_page');
    }

    /**
     * @see getBranches()：$repository 同为上游已 urlencode 的 path，不得二次编码。
     */
    public function getTags(string $repository): array
    {
        return $this->paginatedList("/api/v4/projects/{$repository}/repository/tags", 'per_page');
    }

    /**
     * @return array{success:bool, message:string}
     */
    public function setCommitStatus(string $repository, string $sha, string $state, string $context, string $description, string $targetUrl = ''): array
    {



        $encoded = urlencode($repository);
        $body = [
            'state'       => $state,
            'name'        => $context,
            'description' => $description,
        ];
        if ($targetUrl) {
            $body['target_url'] = $targetUrl;
        }
        return $this->postStatus("{$this->baseUrl}/api/v4/projects/{$encoded}/statuses/{$sha}", $body);
    }
}
