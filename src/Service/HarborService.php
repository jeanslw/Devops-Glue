<?php
namespace App\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;

class HarborService
{
    private Client $client;
    private ?string $apiVersion = null;

    public function __construct(Client $harborClient)
    {
        $this->client = $harborClient;
    }

    private function detectApiVersion(): string
    {
        if ($this->apiVersion !== null) return $this->apiVersion;
        try {
            $this->client->head('/api/v2.0/systeminfo', ['http_errors' => true]);
            $this->apiVersion = 'v2';
        } catch (\Throwable $e) {
            $this->apiVersion = 'v1';
        }
        return $this->apiVersion;
    }

    private function request(string $method, string $uri, array $options = []): array
    {
        try {
            $res = $this->client->request($method, $uri, array_merge($options, ['http_errors' => true]));
            $data = json_decode($res->getBody()->getContents(), true);
            return is_array($data) ? $data : ['error' => 'Harbor返回了无效数据'];
        } catch (ClientException $e) {
            $code = $e->getResponse()?->getStatusCode();
            if ($code === 404) return ['error' => "请求路径 '{$uri}' 不存在(404)"];
            return ['error' => "Harbor服务响应异常(HTTP {$code})"];
        } catch (\Throwable $e) {
            return ['error' => "Harbor请求失败：" . $e->getMessage()];
        }
    }

    public function getProjects(): array
    {
        $version = $this->detectApiVersion();
        $path = ($version === 'v2') ? '/api/v2.0/projects' : '/api/projects';
        $data = $this->request('GET', $path, ['query' => ['page_size' => 100]]);
        if (isset($data['error'])) return $data;
        return array_column($data, 'name');
    }

    public function getRepositories(string $project): array
    {
        $version = $this->detectApiVersion();
        if ($version === 'v2') {
            $encodedProject = rawurlencode($project);
            $path = "/api/v2.0/projects/{$encodedProject}/repositories";
            $data = $this->request('GET', $path, ['query' => ['page_size' => 100]]);
        } else {
            $projectsData = $this->request('GET', '/api/projects', ['query' => ['name' => $project]]);
            if (isset($projectsData['error'])) return $projectsData;
            $projectId = null;
            foreach ($projectsData as $p) {
                if (($p['name'] ?? '') === $project) {
                    $projectId = $p['project_id'] ?? null;
                    break;
                }
            }
            if (!$projectId) return ['error' => "[v1] 找不到项目 [{$project}] 的 project_id"];
            $path = "/api/repositories";
            $data = $this->request('GET', $path, ['query' => ['project_id' => $projectId, 'page_size' => 100]]);
        }
        if (isset($data['error'])) return $data;

        // 提取纯净的仓库名（去掉 project/ 前缀）
        $prefix = $project . '/';
        return array_map(function ($repo) use ($prefix) {
            $name = $repo['name'] ?? '';
            if (str_starts_with($name, $prefix)) return substr($name, strlen($prefix));
            return $name;
        }, $data);
    }

    public function getTags(string $project, string $repository): array
    {
        $version = $this->detectApiVersion();

        if ($version === 'v2') {
            // v2.x 逻辑：需要拆分 project 和 repo，并且 repo 需要双重编码
            $encodedProject = rawurlencode($project);
            $encodedRepo = rawurlencode(rawurlencode($repository));
            $path = "/api/v2.0/projects/{$encodedProject}/repositories/{$encodedRepo}/artifacts";
            $data = $this->request('GET', $path, ['query' => ['page_size' => 100, 'with_tag' => 'true']]);
            if (isset($data['error'])) return $data;

            $tags = [];
            foreach ($data as $artifact) {
                foreach ($artifact['tags'] ?? [] as $tag) {
                    if (!empty($tag['name'])) $tags[] = $tag['name'];
                }
            }
            return $tags;

        } else {
            // 【v1.x 终极修复 - 修复分页 Bug】
            $fullRepoName = $project . '/' . $repository;
            $encodedRepoName = rawurlencode($fullRepoName);
            $path = "/api/repositories/{$encodedRepoName}/tags";
            
            // 移除 page_size 参数！
            $data = $this->request('GET', $path); // 不传任何 query 参数

            if (isset($data['error'])) return $data;
            return array_column($data, 'name');
        } 
    } 
} 