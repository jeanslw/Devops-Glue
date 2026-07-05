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
        if ($this->apiVersion !== null) {
            return $this->apiVersion;
        }
        try {
            $this->client->head('/api/v2.0/systeminfo', ['http_errors' => true]);
            $this->apiVersion = 'v2';
        } catch (\Throwable $e) {
            $this->apiVersion = 'v1';
        }
        return $this->apiVersion;
    }

    /**
     * 统一请求，成功返回数据数组，失败返回 ['error' => ...]
     */
    private function request(string $method, string $uri, array $options = []): array
    {
        try {
            $res = $this->client->request($method, $uri, array_merge($options, ['http_errors' => true]));
            $body = $res->getBody()->getContents();
            if (empty($body)) {
                // 某些操作（如触发扫描）成功时返回空响应，不报错
                return ['status' => 'ok'];
            }
            $data = json_decode($body, true);
            return is_array($data) ? $data : ['error' => 'Harbor返回数据格式异常'];
        } catch (ClientException $e) {
            $code = $e->getResponse()?->getStatusCode();
            $msg = $code === 404 ? "资源不存在(404)" : "Harbor服务响应异常(HTTP {$code})";
            return ['error' => $msg];
        } catch (\Throwable $e) {
            return ['error' => "Harbor请求失败: " . $e->getMessage()];
        }
    }

    /**
     * 获取项目名称列表
     */
    public function getProjects(): array
    {
        $version = $this->detectApiVersion();
        $path = ($version === 'v2') ? '/api/v2.0/projects' : '/api/projects';
        $data = $this->request('GET', $path, ['query' => ['page_size' => 100]]);
        if (isset($data['error'])) return $data;
        return array_values(array_column($data, 'name'));
    }

    /**
     * 获取指定项目下的仓库名称列表（已去掉项目前缀）
     */
    public function getRepositories(string $project): array
    {
        $version = $this->detectApiVersion();
        if ($version === 'v2') {
            $encodedProject = rawurlencode($project);
            $path = "/api/v2.0/projects/{$encodedProject}/repositories";
            $data = $this->request('GET', $path, ['query' => ['page_size' => 100]]);
        } else {
            // v1: 先根据项目名找到 project_id
            $projectsData = $this->request('GET', '/api/projects', ['query' => ['name' => $project]]);
            if (isset($projectsData['error'])) return $projectsData;
            $projectId = null;
            foreach ($projectsData as $p) {
                if (($p['name'] ?? '') === $project) {
                    $projectId = $p['project_id'] ?? null;
                    break;
                }
            }
            if (!$projectId) {
                return ['error' => "项目 '{$project}' 不存在"];
            }
            $path = "/api/repositories";
            $data = $this->request('GET', $path, ['query' => ['project_id' => $projectId, 'page_size' => 100]]);
        }

        if (isset($data['error'])) return $data;

        // 去除仓库名前的 "project/" 前缀
        $prefix = $project . '/';
        $names = array_map(function ($repo) use ($prefix) {
            $name = $repo['name'] ?? '';
            if (str_starts_with($name, $prefix)) {
                return substr($name, strlen($prefix));
            }
            return $name;
        }, $data);

        return array_values($names);
    }

    /**
     * 获取指定仓库的 tag 列表
     */
    public function getTags(string $project, string $repository): array
    {
        $version = $this->detectApiVersion();

        if ($version === 'v2') {
            $encodedProject = rawurlencode($project);
            // Harbor v2 要求对仓库名双重编码（处理内部斜杠）
            $encodedRepo = rawurlencode(rawurlencode($repository));
            $path = "/api/v2.0/projects/{$encodedProject}/repositories/{$encodedRepo}/artifacts";
            $data = $this->request('GET', $path, ['query' => ['page_size' => 100, 'with_tag' => 'true']]);
            if (isset($data['error'])) return $data;

            $tags = [];
            foreach ($data as $artifact) {
                foreach ($artifact['tags'] ?? [] as $tag) {
                    if (!empty($tag['name'])) {
                        $tags[] = $tag['name'];
                    }
                }
            }
            return array_values(array_unique($tags));
        } else {
            // v1: 拼接完整仓库名，注意已修复分页
            $fullRepoName = $project . '/' . $repository;
            $encodedRepoName = rawurlencode($fullRepoName);
            $path = "/api/repositories/{$encodedRepoName}/tags";
            $data = $this->request('GET', $path);
            if (isset($data['error'])) return $data;
            return array_values(array_column($data, 'name'));
        }
    }

    public function scanArtifact(string $project, string $repository, string $tag): array
    {
        $version = $this->detectApiVersion();
        if ($version === 'v2') {
            $encodedProject = rawurlencode($project);
            $encodedRepo = rawurlencode($repository);
            $encodedTag  = rawurlencode($tag);
            $path = "/api/v2.0/projects/{$encodedProject}/repositories/{$encodedRepo}/artifacts/{$encodedTag}/scan";
            return $this->request('POST', $path);
        }
        // v1 路径（Harbor 1.10 有效）
        $fullRepo = $project . '/' . $repository;
        $encodedRepo = rawurlencode($fullRepo);
        $encodedTag  = rawurlencode($tag);
        $path = "/api/repositories/{$encodedRepo}/tags/{$encodedTag}/scan";
        return $this->request('POST', $path);
    }

    public function getScanReport(string $project, string $repository, string $tag): array
    {
        $version = $this->detectApiVersion();
        if ($version === 'v2') {
            $encodedProject = rawurlencode($project);
            $encodedRepo = rawurlencode($repository);
            $encodedTag  = rawurlencode($tag);
            $path = "/api/v2.0/projects/{$encodedProject}/repositories/{$encodedRepo}/artifacts/{$encodedTag}/additions/vulnerabilities";
            $data = $this->request('GET', $path);
            if (isset($data['error'])) {
                return $data;
            }
            // Harbor v2 返回的漏洞数据在 mime type 键下
            foreach ($data as $key => $value) {
                if (str_contains($key, 'vulnerability') && is_array($value)) {
                    return $value;
                }
            }
            return $data;
        }
        $fullRepo = $project . '/' . $repository;
        $encodedRepo = rawurlencode($fullRepo);
        $encodedTag  = rawurlencode($tag);
        $path = "/api/repositories/{$encodedRepo}/tags/{$encodedTag}/scan";
        return $this->request('GET', $path);
    }
}