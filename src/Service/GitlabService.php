<?php

namespace App\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class GitlabService
{
    private Client $client;
    private string $baseUrl;
    private string $token;

    public function __construct(Client $client, string $baseUrl, string $token)
    {
        $this->client = $client;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->token = $token;
    }

    private function request(string $method, string $uri, array $options = []): array
    {
        try {
            $fullUrl = $this->baseUrl . '/' . ltrim($uri, '/');
            if (!isset($options['headers'])) $options['headers'] = [];
            $options['headers']['PRIVATE-TOKEN'] = $this->token;
            $options['verify'] = false; 
            
            $response = $this->client->request($method, $fullUrl, $options);
            $body = (string) $response->getBody();
            return json_decode($body, true) ?? [];
        } catch (GuzzleException $e) {
            return ['error' => $e->getMessage(), 'code' => $e->getCode()];
        }
    }

    public function parseProjectPath(string $gitUrl): string
    {
        $path = preg_replace('/\.git$/', '', $gitUrl);
        $parsed = parse_url($path);
        if (isset($parsed['path'])) return ltrim($parsed['path'], '/');
        if (preg_match('/:(.+)$/', $path, $matches)) return $matches[1];
        return '';
    }

    public function getProjectBranches(string $projectId): array
    {
        $data = $this->request('GET', "/api/v4/projects/{$projectId}/repository/branches");
        return isset($data['error']) ? [] : array_column($data, 'name');
    }

    public function getProjectInfo(string $projectId): array
    {
        return $this->request('GET', "/api/v4/projects/{$projectId}");
    }

    public function getProjectIdByUrl(string $gitUrl): string
    {
        $projectPath = $this->parseProjectPath($gitUrl);
        $encodedPath = rawurlencode($projectPath);
        $data = $this->request('GET', "/api/v4/projects/{$encodedPath}");
        return $data['id'] ?? '';
    }

    public function getProjectIdByPath(string $projectPath): ?int
    {
        $encodedPath = urlencode($projectPath);
        $data = $this->request('GET', "/api/v4/projects/{$encodedPath}"); 
        if (isset($data['error'])) {
            error_log("GitLab API Error for {$projectPath}: " . $data['error']);
            return null; 
        }
        return $data['id'] ?? null;
    }

    public function getBranches(int|string $projectId): array
    {
        $data = $this->request('GET', "/api/v4/projects/{$projectId}/repository/branches?per_page=100");
        if (isset($data['error'])) return ['error' => 'GitLab API 请求失败: ' . $data['error']];
        return array_column($data, 'name');
    }

    public function getProjectMetaByJobName(string $jobNameOrPath): ?array
    {
        $encodedPath = urlencode($jobNameOrPath); 
        $data = $this->request('GET', "/api/v4/projects/{$encodedPath}");

        if (!isset($data['error']) && !empty($data['id'])) {
            return $this->formatProjectMeta($data);
        }

        $parts = explode('/', $jobNameOrPath);
        $searchKeyword = end($parts); 
        
        if (strlen($searchKeyword) < 2) {
            $this->logNotFound($jobNameOrPath, "精确匹配失败且关键词太短无法搜索!");
            return null;
        }

        $searchEncoded = urlencode($searchKeyword);
        $searchData = $this->request('GET', "/api/v4/projects?search={$searchEncoded}&per_page=5");

        if (isset($searchData['error'])) {
            $this->logNotFound($jobNameOrPath, "搜索 API 失败: " . $searchData['error']);
            return null;
        }

        if (is_array($searchData) && count($searchData) > 0) {
            foreach ($searchData as $project) {
                $fullPath = $project['path_with_namespace'] ?? '';
                if (stripos($fullPath, $jobNameOrPath) !== false || stripos($fullPath, $searchKeyword) !== false) {
                    return $this->formatProjectMeta($project);
                }
            }
            return $this->formatProjectMeta($searchData[0]);
        }

        $this->logNotFound($jobNameOrPath, "精确匹配和模糊搜索均未找到项目");
        return null;
    }

    private function formatProjectMeta(array $data): array
    {
        return [
            'project_id'          => $data['id'],
            'web_url'             => $data['web_url'] ?? '',
            'path_with_namespace' => $data['path_with_namespace'] ?? ''
        ];
    }

    private function logNotFound(string $jobName, string $reason): void
    {
        $msg = sprintf("[GitlabService] 项目 [%s] 未找到: %s", $jobName, $reason);
        error_log($msg);
    }
}