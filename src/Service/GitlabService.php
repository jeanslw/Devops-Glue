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
        // 确保 baseUrl 没有多余的斜杠
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->token = $token;
    }

    /**
     * 🌟 核心请求方法：统一拼接 URL、统一注入 Token、统一处理异常
     */
    private function request(string $method, string $uri, array $options = []): array
    {
        try {
            // 1. 动态拼接完整 URL (因为 Guzzle Client 没有配置 base_uri)
            $fullUrl = $this->baseUrl . '/' . ltrim($uri, '/');
            
            // 2. 统一注入 GitLab 认证 Token (防止部分接口漏传导致 401)
            if (!isset($options['headers'])) {
                $options['headers'] = [];
            }
            $options['headers']['PRIVATE-TOKEN'] = $this->token;
            
            // 3. 忽略 SSL 证书校验 (内网环境必备)
            $options['verify'] = false; 
            
            $response = $this->client->request($method, $fullUrl, $options);
            $body = (string) $response->getBody();
            
            return json_decode($body, true) ?? [];
            
        } catch (GuzzleException $e) {
            // 返回标准错误格式，方便上层判断
            return ['error' => $e->getMessage(), 'code' => $e->getCode()];
        }
    }

    /**
     * 从 Git URL 中扒出 "group/project" 路径
     */
    public function parseProjectPath(string $gitUrl): string
    {
        $path = preg_replace('/\.git$/', '', $gitUrl);
        $parsed = parse_url($path);
        if (isset($parsed['path'])) {
            return ltrim($parsed['path'], '/');
        }
        if (preg_match('/:(.+)$/', $path, $matches)) {
            return $matches[1];
        }
        return '';
    }

    public function getProjectBranches(string $projectId): array
    {
        // 复用 request 方法，自动带 Token 和 URL 拼接
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
        
        if (isset($data['error'])) {
            return ['error' => 'GitLab API 请求失败: ' . $data['error']];
        }
        return array_column($data, 'name');
    }

    /**
     * 【兜底】通过 Job Name 获取项目元数据
     * 策略：先尝试精确路径匹配 -> 失败则提取最后一段进行模糊搜索
     */
    public function getProjectMetaByJobName(string $jobNameOrPath): ?array
    {
        // --- 第一步：尝试精确匹配 ---
        $encodedPath = urlencode($jobNameOrPath); 
        $data = $this->request('GET', "/api/v4/projects/{$encodedPath}");

        // 如果精确匹配成功了，直接返回
        if (!isset($data['error']) && !empty($data['id'])) {
            return $this->formatProjectMeta($data);
        }

        // --- 第二步：精确匹配失败 (404)，启动模糊搜索兜底 ---
        // 比如 job_name是"java/registry"，提取 "registry" 去搜索
        $parts = explode('/', $jobNameOrPath);
        $searchKeyword = end($parts); 
        
        // 如果关键词太短，就不搜索了
        if (strlen($searchKeyword) < 2) {
            $this->logNotFound($jobNameOrPath, "精确匹配失败且关键词太短无法搜索!");
            return null;
        }

        // 调用 GitLab Search API (模糊搜索)
        $searchEncoded = urlencode($searchKeyword);
        $searchData = $this->request('GET', "/api/v4/projects?search={$searchEncoded}&per_page=5");

        // 如果搜索 API 报错
        if (isset($searchData['error'])) {
            $this->logNotFound($jobNameOrPath, "搜索 API 失败: " . $searchData['error']);
            return null;
        }

        // 在搜索结果中寻找最匹配的项目
        if (is_array($searchData) && count($searchData) > 0) {
            // 优先找 path_with_namespace 包含原 job_name 的
            foreach ($searchData as $project) {
                $fullPath = $project['path_with_namespace'] ?? '';
                // 如果完整路径包含 job_name，或者完整路径以 job_name 结尾
                if (stripos($fullPath, $jobNameOrPath) !== false || stripos($fullPath, $searchKeyword) !== false) {
                    return $this->formatProjectMeta($project);
                }
            }
            
            // 如果都没匹配上，返回搜索结果的第一项作为妥协
            return $this->formatProjectMeta($searchData[0]);
        }

        // 彻底找不到了
        $this->logNotFound($jobNameOrPath, "精确匹配和模糊搜索均未找到项目");
        return null;
    }

    /**
     * 格式化项目元数据
     */
    private function formatProjectMeta(array $data): array
    {
        return [
            'project_id'          => $data['id'],
            'web_url'             => $data['web_url'] ?? '',
            'path_with_namespace' => $data['path_with_namespace'] ?? ''
        ];
    }

    /**
     * 记录找不到的日志 (不再抛出致命异常，让流程继续)
     */
    private function logNotFound(string $jobName, string $reason): void
    {
        $msg = sprintf("[GitlabService] 项目 [%s] 未找到: %s", $jobName, $reason);
        error_log($msg);
        // 注意：这里不再 throw Exception，而是返回 null，让上层代码处理
    }
}