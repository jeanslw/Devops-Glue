<?php
namespace App\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;

class HarborService
{
    private Client $client;
    private ?string $apiVersion = null;
    private ?Logger $logger = null;

    public function __construct(Client $harborClient)
    {
        $this->client = $harborClient;
    }

    public function setLogger(Logger $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * 获取当前探测到的 Harbor API 版本（v1 或 v2）
     */
    public function getApiVersion(): ?string
    {
        try {
            return $this->detectApiVersion();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 自动探测 Harbor API 版本（惰性求值，首次调用后缓存）
     *
     * 策略：
     *  1. 先尝试 v2.0 端点 /api/v2.0/projects
     *  2. HTTP 404 → 回退 v1
     *  3. 401/403 → 判定为 v2（认证问题，端点存在）
     *  4. 网络不通 → 再尝试 v1 端点 /api/projects
     *  5. 全部失败 → 默认 v2
     */
    private function detectApiVersion(): string
    {
        if ($this->apiVersion !== null) {
            return $this->apiVersion;
        }

        // 从缓存读取（1h TTL），避免每次请求都探测
        try {
            $pdo = \App\Service\Database::getPdo();
            $row = $pdo->prepare("SELECT value FROM cache WHERE cache_key = 'harbor_api_version' AND expires_at > ?");
            $row->execute([time()]);
            $cached = $row->fetch();
            if ($cached) {
                $this->apiVersion = $cached['value'];
                return $this->apiVersion;
            }
        } catch (\Exception $e) {}

        // 直接用 v2 项目列表端点探测，比 HEAD systeminfo 更可靠
        try {
            $this->client->get('/api/v2.0/projects', [
                'http_errors' => true,
                'query'       => ['page_size' => 1],
            ]);
            $this->apiVersion = 'v2';
            $this->logger?->info('Harbor 版本探测: v2.0');
        } catch (ClientException $e) {
            $code = $e->getResponse()?->getStatusCode();
            // 404 说明不是 v2，否则（401/403 等）v2 存在只是认证问题
            $this->apiVersion = ($code === 404) ? 'v1' : 'v2';
            $this->logger?->debug('Harbor v2 探测响应', [
                'http_code' => $code,
                'detected'  => $this->apiVersion,
            ]);
        } catch (\Throwable $e) {
            // 连不上，尝试 v1
            $this->logger?->debug('Harbor v2 探测网络异常，回退尝试 v1', [
                'error' => $e->getMessage(),
            ]);
            try {
                $this->client->get('/api/projects', ['http_errors' => false]);
                $this->apiVersion = 'v1';
                $this->logger?->info('Harbor 版本探测: v1 (v2 不可达后回退)');
            } catch (\Throwable $e2) {
                $this->apiVersion = 'v2'; // 都连不上默认 v2
                $this->logger?->warning('Harbor 版本探测: v1/v2 均不可达，默认 v2', [
                    'error' => $e2->getMessage(),
                ]);
            }
        }
        // 缓存探测结果（1h TTL），避免后续请求重复 API 调用
        try {
            $pdo = \App\Service\Database::getPdo();
            $sql = \App\Service\Database::sqlUpsert('cache', 'cache_key, value, expires_at', '?, ?, ?');
            $pdo->prepare($sql)->execute(['harbor_api_version', $this->apiVersion, time() + 3600]);
        } catch (\Exception $e) {}
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
            $this->logger?->warning('Harbor 请求失败', [
                'method'    => $method,
                'uri'       => $uri,
                'http_code' => $code,
                'message'   => $msg,
            ]);
            return ['error' => $msg];
        } catch (\Throwable $e) {
            $this->logger?->error('Harbor 请求异常', [
                'method' => $method,
                'uri'    => $uri,
                'error'  => $e->getMessage(),
            ]);
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
