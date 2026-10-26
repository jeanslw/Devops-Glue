<?php

namespace App\Service\Git;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\ServerException;
use App\Service\Logger;

/**
 * Git 平台适配器基类（模板方法）。
 *
 * 新增平台只需继承本类并声明四件事，即可复用统一的「翻页 + 不可达上抛 / 其余失败部分返回」
 * 列表能力与 commit status 回写骨架：
 *   1. 三个平台标识方法 getName() / matchUrl() / getApiVersion()；
 *   2. 构造时调用 initClient($baseUrl, $headers, $logger) 初始化 Guzzle client；
 *   3. getBranches() / getTags() 里拼端点路径，调用 paginatedList()（默认裸数组响应；特殊响应
 *      形状传入提取闭包）；
 *   4. setCommitStatus() 里拼 URL 与 body，调用 postStatus()（state 映射 / 字段名等平台差异在子类做）。
 */
abstract class AbstractGitProvider implements GitProviderInterface
{
    protected Client $client;
    protected string $baseUrl;
    protected ?Logger $logger;

    protected const PAGE_SIZE = 100;
    protected const MAX_PAGES = 20;

    abstract public function getName(): string;
    abstract public function matchUrl(string $url): bool;
    abstract public function getApiVersion(): string;
    abstract public function getBranches(string $repository): array;
    abstract public function getTags(string $repository): array;
    abstract public function setCommitStatus(string $repository, string $sha, string $state, string $context, string $description, string $targetUrl = ''): array;

    /**
     * 初始化 Guzzle client（统一 timeout / connect_timeout / 认证头）。
     * @param array<string,string> $headers
     */
    protected function initClient(string $baseUrl, array $headers, ?Logger $logger): void
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->logger  = $logger;
        $this->client  = new Client([
            'headers'         => $headers,
            'timeout'         => 15,
            'connect_timeout' => 10,
        ]);
    }

    /**
     * 把 "owner/repo" 拆成两段；段缺失时返回空串，由调用方判断并给出平台专属提示。
     * @return array{0:string, 1:string}
     */
    protected function splitOwnerRepo(string $repository): array
    {
        $parts = explode('/', $repository, 2);
        return [$parts[0], $parts[1] ?? ''];
    }

    /**
     * 通用分页列表获取：自动翻页（上限 MAX_PAGES × PAGE_SIZE）。
     *
     * 连接级失败（拒绝/DNS/超时）与 5xx 一律上抛，交由 GitService 统一包装为平台不可达（502），
     * 不得吞成空数组（否则与「仓库无分支/tag」无法区分）；其余请求失败按部分结果返回。
     *
     * @param string   $path         相对 baseUrl 的端点路径（不含查询串）
     * @param string   $pageSizeParam 每页条数的查询参数名（per_page / limit / pagelen）
     * @param null|callable(array<mixed>): list<mixed> $extractItems 从解码后的响应取本页 items；缺省为裸数组响应直接取 name 列
     * @return list<string>
     */
    protected function paginatedList(string $path, string $pageSizeParam, ?callable $extractItems = null): array
    {
        $extractItems ??= static fn(array $data) => array_column($data, 'name');

        $all   = [];
        $page  = 1;
        $items = [];
        do {
            $url = "{$this->baseUrl}{$path}?{$pageSizeParam}=" . self::PAGE_SIZE . "&page={$page}";
            try {
                $response = $this->client->get($url);
                $data = json_decode((string) $response->getBody(), true);
                if (!is_array($data) || empty($data)) {
                    break;
                }
                $items = $extractItems($data);
                if ($items === []) {
                    break;
                }
                $all = array_merge($all, $items);
                $page++;
            } catch (ConnectException | ServerException $e) {
                $this->logger?->warning($this->getName() . ' 平台不可达', [
                    'path' => $path, 'page' => $page, 'error' => $e->getMessage(),
                ]);
                throw $e;
            } catch (GuzzleException $e) {
                $this->logger?->warning($this->getName() . ' API 请求失败', [
                    'path' => $path, 'page' => $page, 'error' => $e->getMessage(),
                ]);
                return $page === 1 ? [] : $all;
            }
        } while (count($items) === self::PAGE_SIZE && $page <= self::MAX_PAGES);

        if ($page > self::MAX_PAGES) {
            $this->logger?->warning($this->getName() . ' 列表达到最大分页上限', [
                'path' => $path, 'max_pages' => self::MAX_PAGES, 'total' => count($all),
            ]);
        }
        return $all;
    }

    /**
     * 通用 commit status 回写：POST JSON，状态码 <400 判成功；异常统一降级为失败（不向上抛）。
     * 端点路径 / body / state 映射等平台差异在子类拼好后传入。
     *
     * @param array<string,mixed> $body
     * @return array{success:bool, message:string}
     */
    protected function postStatus(string $url, array $body): array
    {
        try {
            $response = $this->client->post($url, ['json' => $body]);
            $ok = $response->getStatusCode() < 400;
            return ['success' => $ok, 'message' => $ok ? 'status 已回写' : '回写失败'];
        } catch (GuzzleException $e) {
            $this->logger?->warning($this->getName() . ' commit status 回写失败', [
                'url' => $url, 'error' => $e->getMessage(),
            ]);


            $code = $e instanceof BadResponseException ? $e->getResponse()->getStatusCode() : 0;
            return ['success' => false, 'message' => '回写失败: ' . $this->getName()
                . ' 服务不可达或拒绝请求' . ($code > 0 ? '（HTTP ' . $code . '）' : '')];
        }
    }
}
