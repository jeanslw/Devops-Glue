<?php

namespace App\Service\Git;

use App\Service\Logger;

/**
 * Bitbucket Cloud API v2.0 适配器（自定义平台，经 config/settings.php 的 git.custom_providers 注册）。
 *
 * 官方文档: https://developer.atlassian.com/cloud/bitbucket/rest/api-group-refs/
 * 认证：配置 username 时走 App Password（Basic base64(username:app_password)），
 *       仅配 token 时退化为 Bearer（OAuth / 仓库访问令牌）。
 * 仓库标识为 {workspace}/{repo_slug} 两段，与上游下传的 owner/repo 一致。
 */
class BitbucketService extends AbstractGitProvider
{
    /**
     * @param array<string,mixed> $config
     */
    public function __construct(array $config, ?Logger $logger = null)
    {
        $token    = trim($config['token'] ?? '');
        $username = trim($config['username'] ?? '');
        $headers  = [];
        if ($username !== '') {
            $headers['Authorization'] = 'Basic ' . base64_encode($username . ':' . $token);
        } elseif ($token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        }
        $this->initClient($config['base_url'] ?? '', $headers, $logger);
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
        [$workspace, $slug] = $this->splitOwnerRepo($repository);
        if ($workspace === '' || $slug === '') {
            $this->logger?->warning('Bitbucket 仓库路径解析失败', ['repository' => $repository]);
            return [];
        }
        return $this->paginatedList(
            "/repositories/{$workspace}/{$slug}/refs/{$kind}",
            'pagelen',
            static function (array $data) {
                $values = $data['values'] ?? [];
                return is_array($values) ? array_column($values, 'name') : [];
            }
        );
    }

    /**
     * @return array{success:bool, message:string}
     */
    public function setCommitStatus(string $repository, string $sha, string $state, string $context, string $description, string $targetUrl = ''): array
    {
        [$workspace, $slug] = $this->splitOwnerRepo($repository);
        if ($workspace === '' || $slug === '') {
            return ['success' => false, 'message' => '仓库路径格式错误'];
        }


        $bbState = match ($state) {
            'pending' => 'INPROGRESS',
            'success' => 'SUCCESSFUL',
            'failed'  => 'FAILED',
            'error'   => 'FAILED',
            default   => 'FAILED',
        };

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

        return $this->postStatus(
            "{$this->baseUrl}/repositories/{$workspace}/{$slug}/commit/{$sha}/statuses/build",
            $body
        );
    }
}
