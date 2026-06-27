<?php
namespace App\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;

class HarborService
{
    private Client $client;

    public function __construct(Client $harborClient)
    {
        $this->client = $harborClient;
    }

    /**
     * 统一请求封装，自动处理错误并返回数组格式
     */
    private function request(string $method, string $uri, array $options = []): array
    {
        try {
            $res = $this->client->request($method, $uri, array_merge($options, [
                'http_errors' => true,
            ]));
            $data = json_decode($res->getBody()->getContents(), true);
            return is_array($data) ? $data : ['error' => 'Harbor返回了无效数据'];
        } catch (ClientException $e) {
            $code = $e->getResponse()?->getStatusCode();
            if ($code === 404) {
                return ['error' => "请求路径 '{$uri}' 不存在"];
            }
            return ['error' => "Harbor服务响应异常(HTTP {$code})"];
        } catch (\Throwable $e) {
            return ['error' => "Harbor请求失败：" . $e->getMessage()];
        }
    }

    /**
     * 获取项目列表
     * GET /api/harbor/{projects}/list → 实际只取项目名列表
     */
    public function getProjects(): array
    {
        $data = $this->request('GET', '/api/v2.0/projects', [
            'query' => ['page_size' => 100],
        ]);

        if (isset($data['error'])) {
            return $data;
        }

        return array_column($data, 'name');
    }

    /**
     * 获取指定项目下的仓库列表
     * 注意：Harbor v1.10.1 使用 /api/v2.0/projects/{project}/repositories
     * 仓库名可能包含 '/'，但列表接口不需要编码仓库名
     */
    public function getRepositories(string $project): array
    {
        $encodedProject = rawurlencode($project);
        $data = $this->request('GET', "/api/v2.0/projects/{$encodedProject}/repositories", [
            'query' => ['page_size' => 100],
        ]);

        if (isset($data['error'])) {
            return $data;
        }

        // Harbor返回的仓库名格式为 "project/repo"，提取纯仓库名部分
        return array_map(function ($repo) use ($project) {
            $name = $repo['name'] ?? '';
            // 去掉 "project/" 前缀，保留可能含 '/' 的子路径
            return preg_replace('/^' . preg_quote($project, '/') . '\//', '', $name);
        }, $data);
    }

    /**
     * 获取Tags列表
     * 仓库名可能含 '/'，必须双重URL编码
     */
    public function getTags(string $project, string $repository): array
    {
        $encodedProject = rawurlencode($project);
        // Harbor API要求仓库名中的 '/' 也要被编码，所以需要双重编码
        $encodedRepo = rawurlencode(rawurlencode($repository));

        $data = $this->request('GET', "/api/v2.0/projects/{$encodedProject}/repositories/{$encodedRepo}/artifacts", [
            'query' => [
                'page_size' => 100,
                'with_tag' => 'true',
            ],
        ]);

        if (isset($data['error'])) {
            return $data;
        }

        // 从 artifacts 中提取所有 tag name
        $tags = [];
        foreach ($data as $artifact) {
            foreach ($artifact['tags'] ?? [] as $tag) {
                if (!empty($tag['name'])) {
                    $tags[] = $tag['name'];
                }
            }
        }

        return $tags;
    }
}