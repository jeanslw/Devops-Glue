<?php

namespace App\Service\Registry;

use App\Service\Logger;
use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;

/**
 * 标准 OCI Distribution（registry:2）提供方。
 *
 * 只使用 /v2/ 标准接口：/v2/{name}/tags/list 列 tag、/v2/_catalog 列仓库；
 * registry:2 没有 project 概念，项目由 _catalog 返回的仓库名首段聚合。
 * 翻页（n/last + Link: rel="next"）只在 _catalog 上生效：实测 registry:2.8.2 的
 * tags 处理器不读 n/last、也不返回 Link 头（上游 registry/handlers/tags.go 里同样没有分页），
 * 故 tags/list 一次返回全量 tag；这里仍保留翻页逻辑，以兼容确实实现了分页的其它 OCI 仓库。
 * 认证先按 Basic 发送；收到 401 且带 Bearer challenge 时换 token 后重试一次。
 * 该类型不提供漏洞扫描接口。
 */
class DockerRegistryProvider implements RegistryProviderInterface
{
    private const PAGE_SIZE = 100;
    private const MAX_PAGES = 20;
    /** 标准 OCI Distribution 仓库目录（列出全部仓库名） */
    private const CATALOG_PATH = 'v2/_catalog';

    private ?string $token = null;

    public function __construct(
        private Client $client,
        private string $username = '',
        private string $password = '',
        private ?Logger $logger = null
    ) {
    }

    public function type(): string
    {
        return 'registry';
    }

    public function supportsScanning(): bool
    {
        return false;
    }

    public function getTags(string $repository): array
    {
        $path = $this->tagsPath($repository);
        $all  = [];
        $last = null;
        $page = 0;

        do {
            $page++;
            $query = ['n' => self::PAGE_SIZE];
            if ($last !== null) {
                $query['last'] = $last;
            }
            $res  = $this->request('GET', $path, $query);
            $code = $res->getStatusCode();
            if ($code === 404) {
                return ['error' => '镜像仓库中不存在仓库 ' . $repository, 'http_code' => 404];
            }
            if ($code === 401 || $code === 403) {
                return ['error' => '镜像仓库认证失败', 'http_code' => $code];
            }
            if ($code < 200 || $code >= 300) {
                return ['error' => '镜像仓库返回 HTTP ' . $code, 'http_code' => $code];
            }

            $data = json_decode((string) $res->getBody(), true);
            if (!is_array($data)) {
                return ['error' => '镜像仓库返回数据格式异常', 'http_code' => 0];
            }
            $tags = $data['tags'] ?? [];
            if (is_array($tags)) {
                foreach ($tags as $item) {
                    if (is_string($item) && $item !== '') {
                        $all[] = $item;
                    }
                }
            }
            $last = $this->nextLast($res);
        } while ($last !== null && $page < self::MAX_PAGES);

        if ($page >= self::MAX_PAGES && $last !== null) {
            $this->logger?->warning('镜像仓库分页达到上限，存在未获取的 tag', [
                'repository' => $repository,
                'max_pages'  => self::MAX_PAGES,
                'total'      => count($all),
            ]);
        }

        return array_values(array_unique($all));
    }

    public function repositoryExists(string $repository): bool
    {
        $res  = $this->request('GET', $this->tagsPath($repository), ['n' => 1]);
        $code = $res->getStatusCode();
        if ($code === 404) {
            return false;
        }
        if ($code < 200 || $code >= 300) {
            throw new \RuntimeException('镜像仓库返回 HTTP ' . $code . '（仓库 ' . $repository . '）');
        }
        return true;
    }

    /**
     * 项目列表：由 /v2/_catalog 的仓库名首段聚合（registry:2 自身没有 project 概念）。
     *
     * 单段（根级）仓库不计入：平台侧 registry_repository 强制“至少两段 OCI 仓库名”
     * （见 BuildController::isValidRegistryRepo），这类仓库进不了映射表，
     * 若当成项目暴露，调用方会拿到一个查不到任何仓库的伪项目。
     *
     * @return array<int|string,mixed>
     */
    public function getProjects(): array
    {
        $repositories = $this->catalogRepositories();
        if (isset($repositories['error'])) {
            return $repositories;
        }

        $projects = [];
        foreach ($repositories as $name) {
            $parts = explode('/', (string) $name, 2);
            if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
                continue;
            }
            $projects[$parts[0]] = true;
        }




        $out = array_map('strval', array_keys($projects));

        sort($out, SORT_STRING);

        return $out;
    }

    /**
     * 指定项目下的仓库名列表：_catalog 里以 “项目/” 开头的仓库去掉前缀。
     *
     * @return array<int|string,mixed>
     */
    public function getRepositories(string $project): array
    {

        if ($project === '' || str_contains($project, '/')) {
            return ['error' => "项目 '{$project}' 不存在", 'http_code' => 404];
        }

        $repositories = $this->catalogRepositories();
        if (isset($repositories['error'])) {
            return $repositories;
        }

        $prefix = $project . '/';
        $out    = [];
        foreach ($repositories as $name) {
            $name = (string) $name;
            if (str_starts_with($name, $prefix) && strlen($name) > strlen($prefix)) {
                $out[] = substr($name, strlen($prefix));
            }
        }

        if ($out === []) {
            return ['error' => "项目 '{$project}' 不存在", 'http_code' => 404];
        }

        return $out;
    }

    public function getScanReport(string $repository, string $tag): array
    {
        return ['error' => '当前镜像仓库类型（registry）不提供漏洞扫描接口'];
    }

    private function tagsPath(string $repository): string
    {
        $encoded = implode('/', array_map('rawurlencode', explode('/', $repository)));

        return 'v2/' . $encoded . '/tags/list';
    }

    /**
     * @param array<string,int|string> $query
     */
    private function request(string $method, string $uri, array $query = []): ResponseInterface
    {
        try {
            $res = $this->client->request($method, $uri, [
                'query'       => $query,
                'headers'     => $this->authHeaders(),
                'http_errors' => false,
            ]);
        } catch (\Throwable $e) {
            $this->logger?->warning('镜像仓库请求失败', ['uri' => $uri, 'error' => $e->getMessage()]);
            throw new \RuntimeException('镜像仓库不可用：' . $e->getMessage(), 0, $e);
        }

        if ($res->getStatusCode() === 401) {
            $challenge = $res->getHeaderLine('WWW-Authenticate');
            if (stripos($challenge, 'bearer') !== false && $this->fetchToken($challenge)) {
                try {
                    $res = $this->client->request($method, $uri, [
                        'query'       => $query,
                        'headers'     => $this->authHeaders(),
                        'http_errors' => false,
                    ]);
                } catch (\Throwable $e) {
                    $this->logger?->warning('镜像仓库鉴权后重试失败', ['uri' => $uri, 'error' => $e->getMessage()]);
                    throw new \RuntimeException('镜像仓库不可用：' . $e->getMessage(), 0, $e);
                }
            }
        }

        if ($res->getStatusCode() >= 500) {
            $this->logger?->warning('镜像仓库服务端异常', ['uri' => $uri, 'http_code' => $res->getStatusCode()]);
            throw new \RuntimeException('镜像仓库服务端异常（HTTP ' . $res->getStatusCode() . '）');
        }

        return $res;
    }

    private function fetchToken(string $challenge): bool
    {
        $params = [];
        if (preg_match_all('/(\w+)="([^"]*)"/', $challenge, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $pair) {
                $params[strtolower($pair[1])] = $pair[2];
            }
        }
        $realm = $params['realm'] ?? '';
        if ($realm === '') {
            return false;
        }




        $realmScheme = strtolower((string) parse_url($realm, PHP_URL_SCHEME));
        if ($this->registryScheme() === 'https' && $realmScheme !== 'https') {
            $this->logger?->warning('镜像仓库 token realm 协议校验失败，拒绝转发凭据', ['realm' => $realm]);
            return false;
        }

        $query = [];
        if (($params['service'] ?? '') !== '') {
            $query['service'] = $params['service'];
        }
        if (($params['scope'] ?? '') !== '') {
            $query['scope'] = $params['scope'];
        }

        $options = ['query' => $query, 'http_errors' => false, 'timeout' => 10];
        if ($this->username !== '' && $this->password !== '') {
            $options['auth'] = [$this->username, $this->password];
        }

        try {
            $res = $this->client->request('GET', $realm, $options);
        } catch (\Throwable $e) {
            $this->logger?->warning('镜像仓库 token 获取失败', ['realm' => $realm, 'error' => $e->getMessage()]);
            return false;
        }
        if ($res->getStatusCode() !== 200) {
            $this->logger?->warning('镜像仓库 token 端点返回非 200', ['realm' => $realm, 'http_code' => $res->getStatusCode()]);
            return false;
        }

        $data  = json_decode((string) $res->getBody(), true);
        $token = is_array($data) ? ($data['token'] ?? $data['access_token'] ?? '') : '';
        if (!is_string($token) || $token === '') {
            return false;
        }

        $this->token = $token;
        return true;
    }

    /**
     * @return array<string,string>
     */
    private function authHeaders(): array
    {
        if ($this->token !== null) {
            return ['Authorization' => 'Bearer ' . $this->token];
        }


        if ($this->username !== '' && $this->password !== '') {
            return ['Authorization' => 'Basic ' . base64_encode($this->username . ':' . $this->password)];
        }
        return [];
    }

    /**
     * 仓库本身的访问协议（取自注入 client 的 base_uri）：https 仓库必须配证书，
     * 未配证书的内网仓库为 http。取不到时返回空串（按 http 语义放行 realm）。
     */
    private function registryScheme(): string
    {
        $base = $this->client->getConfig('base_uri');
        return $base instanceof UriInterface ? strtolower($base->getScheme()) : '';
    }

    private function nextLast(ResponseInterface $res): ?string
    {
        $link = $res->getHeaderLine('Link');
        if ($link === '' || !preg_match('/<([^>]+)>;\s*rel="next"/', $link, $matches)) {
            return null;
        }
        $query = parse_url($matches[1], PHP_URL_QUERY);
        if (!is_string($query) || $query === '') {
            return null;
        }
        parse_str($query, $params);
        $last = $params['last'] ?? null;
        return is_string($last) && $last !== '' ? $last : null;
    }

    /**
     * 全量仓库名列表：GET /v2/_catalog，用 n / last + Link: rel="next" 翻页。
     *
     * 与 tags/list 不同，_catalog 确实分页：n 生效、last 为“上一页最后一条”（排除式，
     * 下一页从其后开始），并在还有数据时返回 Link 头（distribution v2.8.2
     * registry/handlers/catalog.go；均已对 registry:2.8.2 实测）。
     *
     * 部分部署会关掉仓库目录（返回 404/405），此时返回 error 数组由上层明确提示，
     * 而不是伪装成“没有仓库”。
     *
     * @return array<int,string>|array{error:string,http_code:int}
     */
    private function catalogRepositories(): array
    {
        $all   = [];
        $last  = null;
        $page  = 0;
        $omitN = false;

        while (true) {
            $query = [];
            if (!$omitN) {
                $query['n'] = self::PAGE_SIZE;
            }
            if ($last !== null) {
                $query['last'] = $last;
            }

            $res = $this->request('GET', self::CATALOG_PATH, $query);




            if (
                !$omitN && $res->getStatusCode() === 400
                && str_contains((string) $res->getBody(), 'PAGINATION_NUMBER_INVALID')
            ) {
                $omitN = true;
                continue;
            }

            $page++;
            $code = $res->getStatusCode();
            if ($code === 404 || $code === 405) {
                return ['error' => '镜像仓库未开启 /v2/_catalog 接口（无法列出仓库）', 'http_code' => $code];
            }
            if ($code === 401 || $code === 403) {
                return ['error' => '镜像仓库认证失败', 'http_code' => $code];
            }
            if ($code < 200 || $code >= 300) {
                return ['error' => '镜像仓库返回 HTTP ' . $code, 'http_code' => $code];
            }

            $data = json_decode((string) $res->getBody(), true);
            if (!is_array($data) || !is_array($data['repositories'] ?? null)) {
                return ['error' => '镜像仓库返回数据格式异常', 'http_code' => 0];
            }
            foreach ($data['repositories'] as $name) {
                if (is_string($name) && $name !== '') {
                    $all[] = $name;
                }
            }

            $last = $this->nextLast($res);
            if ($last === null || $page >= self::MAX_PAGES) {
                break;
            }
        }

        if ($last !== null) {
            $this->logger?->warning('镜像仓库仓库目录分页达到上限，存在未获取的仓库', [
                'max_pages' => self::MAX_PAGES,
                'total'     => count($all),
            ]);
        }

        return array_values(array_unique($all));
    }
}
