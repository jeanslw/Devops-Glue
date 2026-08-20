<?php
namespace App\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;

class HarborService
{
    private Client $client;
    private ?string $apiVersion = null;
    private ?string $specificVersion = null;
    private ?Logger $logger = null;
    private \PDO $pdo;

    public function __construct(Client $harborClient, \PDO $pdo, ?Logger $logger = null)
    {
        $this->client = $harborClient;
        $this->pdo    = $pdo;
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
     * 获取 Harbor 具体版本号（如 '2.0.6'），探测失败返回 null。
     * 惰性求值，首次探测后缓存（1h TTL）。
     */
    public function getHarborVersion(): ?string
    {
        try {
            return $this->detectHarborVersion();
        } catch (\Throwable $e) {
            $this->logger?->warning('Harbor 具体版本探测异常', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * 判断当前 Harbor 是否支持机器人账户调用 REST API。
     *
     * Harbor 演进历史（已据官方文档/发布说明核实，非猜测）：
     *  - v1.x ~ v2.1.x：机器人账户令牌为 JWT（legacy），仅可用于 Docker/Helm CLI，不能调用 REST API。
     *  - v2.2.0 起：机器人账户改为 secret，可用 Basic Auth 调用 REST API。
     * 版本边界为 v2.2.0。
     *
     * @return string 'supported'（>= 2.2.0）| 'unsupported'（< 2.2.0）| 'unknown'（版本探测失败）
     */
    public function getRobotAccountSupport(): string
    {
        $version = $this->getHarborVersion();
        if ($version === null) {
            return 'unknown';
        }
        return version_compare($version, '2.2.0', '>=') ? 'supported' : 'unsupported';
    }

    /**
     * 惰性探测 Harbor 具体版本号（缓存 1h）。
     */
    private function detectHarborVersion(): ?string
    {
        if ($this->specificVersion !== null) {
            return $this->specificVersion;
        }

        $cacheKey = \App\Config\AppConfig::CACHE_KEY_HARBOR_SPECIFIC_VERSION;
        try {
            $pdo = $this->pdo;
            $row = $pdo->prepare("SELECT value FROM " . \App\Config\AppConfig::TABLE_CACHE . " WHERE cache_key = ? AND expires_at > ?");
            $row->execute([$cacheKey, time()]);
            $cached = $row->fetch();
            if ($cached && (string) $cached['value'] !== '') {
                $this->specificVersion = $cached['value'];
                return $this->specificVersion;
            }
        } catch (\Exception $e) {}

        $version = $this->probeHarborVersion();

        if ($version !== null) {
            try {
                $pdo = $this->pdo;
                $sql = \App\Service\Database::sqlUpsert(\App\Config\AppConfig::TABLE_CACHE, 'cache_key, value, expires_at', '?, ?, ?');
                $pdo->prepare($sql)->execute([$cacheKey, $version, time() + \App\Config\AppConfig::TTL_CACHE]);
            } catch (\Exception $e) {}
        }

        return $this->specificVersion = $version;
    }

    /**
     * 通过 systeminfo 端点探测具体版本号：
     *  1. 依次尝试 /api/v2.0/systeminfo（v2.x）与 /api/systeminfo（v1.x）。
     *  2. 每个端点先匿名探测（2.0.6 等低版本 systeminfo 无需登录），再带认证重试（高版本可能需要登录）。
     */
    private function probeHarborVersion(): ?string
    {
        $paths = [
            '/api/v2.0/systeminfo', // Harbor v2.x
            '/api/systeminfo',      // Harbor v1.x
        ];

        foreach ($paths as $path) {
            $clients = [
                'anonymous'     => $this->makeAnonymousClient(),
                'authenticated' => $this->client,
            ];
            foreach ($clients as $kind => $client) {
                try {
                    $res = $client->get($path, ['http_errors' => false]);
                    $code = $res->getStatusCode();
                    if ($code < 200 || $code >= 300) {
                        continue; // 404/401/5xx → 下一个客户端或端点
                    }
                    $data = json_decode((string) $res->getBody(), true);
                    $version = $this->normalizeHarborVersion((string) ($data['harbor_version'] ?? ''));
                    if ($version !== null) {
                        $this->logger?->info('Harbor 具体版本探测成功', [
                            'path' => $path, 'kind' => $kind, 'version' => $version,
                        ]);
                        return $version;
                    }
                } catch (\Throwable $e) {
                    $this->logger?->debug('Harbor systeminfo 探测失败', [
                        'path' => $path, 'kind' => $kind, 'error' => $e->getMessage(),
                    ]);
                }
            }
        }
        return null;
    }

    /**
     * 构造不带认证的匿名客户端（systeminfo 探测优先匿名，避免凭证错误导致探测失败）。
     */
    private function makeAnonymousClient(): Client
    {
        return new Client([
            'base_uri'        => $this->client->getConfig('base_uri'),
            'headers'         => ['Accept' => 'application/json'],
            'connect_timeout' => 5,
            'timeout'         => 10,
            'http_errors'     => false,
        ]);
    }

    /**
     * 规范化 Harbor 版本号：'v2.0.6-f5884625' → '2.0.6'；'v1.10.1' → '1.10.1'。
     * 无法识别的格式返回 null。
     */
    private function normalizeHarborVersion(string $raw): ?string
    {
        $v = trim($raw);
        if ($v === '') {
            return null;
        }
        $v = ltrim($v, 'vV');
        if (($pos = strpos($v, '-')) !== false) {
            $v = substr($v, 0, $pos); // 去掉 commit 后缀
        }
        return preg_match('/^\d+(\.\d+){1,2}$/', $v) ? $v : null;
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
            $pdo = $this->pdo;
            $row = $pdo->prepare("SELECT value FROM " . \App\Config\AppConfig::TABLE_CACHE . " WHERE cache_key = '" . \App\Config\AppConfig::CACHE_KEY_HARBOR_VERSION . "' AND expires_at > ?");
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
            $pdo = $this->pdo;
            $sql = \App\Service\Database::sqlUpsert(\App\Config\AppConfig::TABLE_CACHE, 'cache_key, value, expires_at', '?, ?, ?');
            $pdo->prepare($sql)->execute([\App\Config\AppConfig::CACHE_KEY_HARBOR_VERSION, $this->apiVersion, time() + \App\Config\AppConfig::TTL_CACHE]);
        } catch (\Exception $e) {}
        return $this->apiVersion;
    }

    /**
     * 统一请求，带重试：4xx 不重试，5xx/网络错误最多重试 2 次
     */
    private function request(string $method, string $uri, array $options = []): array
    {
        $maxRetries = 2;
        $lastException = null;

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            try {
                $res = $this->client->request($method, $uri, array_merge($options, ['http_errors' => true]));
                $body = $res->getBody()->getContents();
                if (empty($body)) {
                    return ['status' => 'ok'];
                }
                $data = json_decode($body, true);
                if (!is_array($data)) {
                    return ['error' => 'Harbor返回数据格式异常'];
                }
                // 成功返回：记录重试日志（如有）
                if ($attempt > 0) {
                    $this->logger?->info('Harbor 请求重试成功', [
                        'method' => $method,
                        'uri'    => $uri,
                        'attempt' => $attempt + 1,
                    ]);
                }
                return $data;
            } catch (ClientException $e) {
                $code = $e->getResponse()?->getStatusCode();
                // 4xx 不重试（权限/不存在等问题重试无意义）
                if ($code && $code < 500) {
                    $msg = $code === 404 ? "资源不存在(404)" : "Harbor服务响应异常(HTTP {$code})";
                    $this->logger?->warning('Harbor 请求失败', [
                        'method'    => $method,
                        'uri'       => $uri,
                        'http_code' => $code,
                        'message'   => $msg,
                    ]);
                    return ['error' => $msg, 'http_code' => $code];
                }
                // 5xx: 记录并继续重试
                $lastException = $e;
                $this->logger?->warning('Harbor 服务端错误，准备重试', [
                    'method'    => $method,
                    'uri'       => $uri,
                    'http_code' => $code,
                    'attempt'   => $attempt + 1,
                ]);
            } catch (\Throwable $e) {
                // 网络错误（连接超时、DNS 解析失败等），重试
                $lastException = $e;
                $this->logger?->warning('Harbor 网络异常，准备重试', [
                    'method'  => $method,
                    'uri'     => $uri,
                    'error'   => $e->getMessage(),
                    'attempt' => $attempt + 1,
                ]);
            }

            if ($attempt < $maxRetries) {
                // 指数退避: 200ms → 400ms
                usleep(($attempt + 1) * 200000);
            }
        }

        // 所有重试耗尽
        $this->logger?->error('Harbor 请求重试耗尽', [
            'method' => $method,
            'uri'    => $uri,
            'error'  => $lastException?->getMessage() ?? 'unknown',
        ]);
        return ['error' => "Harbor请求失败(已重试{$maxRetries}次): " . ($lastException?->getMessage() ?? 'unknown')];
    }

    /**
     * 通用分页获取：自动翻页直到数据不足 pageSize 或达到最大页数限制
     *
     * @param string   $path        请求路径
     * @param int      $pageSize    每页数量
     * @param int      $maxPages    最大页数（安全阀）
     * @param callable $extract     提取回调: fn(array $page): array 返回该页的业务数据
     * @param array    $extraQuery  额外的 query 参数（如 with_tag, project_id）
     * @return array
     */
    private function paginatedGet(string $path, int $pageSize, int $maxPages, callable $extract, array $extraQuery = []): array
    {
        $all = [];
        $page = 1;

        do {
            $query = array_merge(['page_size' => $pageSize, 'page' => $page], $extraQuery);
            $data = $this->request('GET', $path, ['query' => $query]);

            if (isset($data['error'])) {
                return $page === 1 ? $data : $all; // 首页失败返回错误，后续页失败返回已收集数据
            }

            if (!is_array($data) || empty($data)) {
                break;
            }

            $items = $extract($data);
            $all = array_merge($all, $items);
            $page++;

            // 返回数量不足 pageSize 说明已是最后一页
        } while (count($data) === $pageSize && $page <= $maxPages);

        // 达到 maxPages 且最后一页满数据 → 再请求一次确认是否真的超限
        if ($page > $maxPages && count($data) === $pageSize) {
            $checkQuery = array_merge(['page_size' => $pageSize, 'page' => $maxPages + 1], $extraQuery);
            $checkData = $this->request('GET', $path, ['query' => $checkQuery]);
            if (is_array($checkData) && !empty($checkData) && !isset($checkData['error'])) {
                $this->logger?->warning('Harbor 分页达到上限，存在未获取的数据', [
                    'path'      => $path,
                    'max_pages' => $maxPages,
                    'total'     => count($all),
                ]);
            }
        }

        return array_values(array_unique($all));
    }

    /**
     * 获取项目名称列表（自动翻页，上限 1000 个项目）
     */
    public function getProjects(): array
    {
        $version = $this->detectApiVersion();
        $path = ($version === 'v2') ? '/api/v2.0/projects' : '/api/projects';
        $pageSize = 100;
        $maxPages = 10; // 最多 1000 个项目
        return $this->paginatedGet($path, $pageSize, $maxPages, function ($page) {
            return array_column($page, 'name');
        });
    }

    /**
     * 获取指定项目下的仓库名称列表（已去掉项目前缀，自动翻页上限 1000 个）
     */
    public function getRepositories(string $project): array
    {
        $version = $this->detectApiVersion();
        $pageSize = 100;
        $maxPages = 10;

        if ($version === 'v2') {
            $encodedProject = rawurlencode($project);
            $path = "/api/v2.0/projects/{$encodedProject}/repositories";
            return $this->paginatedGet($path, $pageSize, $maxPages, function ($page) use ($project) {
                $prefix = $project . '/';
                return array_map(function ($repo) use ($prefix) {
                    $name = $repo['name'] ?? '';
                    return str_starts_with($name, $prefix) ? substr($name, strlen($prefix)) : $name;
                }, $page);
            });
        }

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
        return $this->paginatedGet('/api/repositories', $pageSize, $maxPages, function ($page) {
            return array_column($page, 'name');
        }, ['project_id' => $projectId]);
    }

    /**
     * 获取指定仓库的 tag 列表（自动翻页，上限 2000 个 tag）
     */
    public function getTags(string $project, string $repository): array
    {
        $version = $this->detectApiVersion();
        $pageSize = 100;
        $maxPages = 20; // 最多 2000 个 tag

        if ($version === 'v2') {
            $encodedProject = rawurlencode($project);
            $encodedRepo = rawurlencode(rawurlencode($repository));
            $path = "/api/v2.0/projects/{$encodedProject}/repositories/{$encodedRepo}/artifacts";
            $extraQuery = ['with_tag' => 'true'];
            return $this->paginatedGet($path, $pageSize, $maxPages, function ($page) {
                $tags = [];
                foreach ($page as $artifact) {
                    foreach ($artifact['tags'] ?? [] as $tag) {
                        if (!empty($tag['name'])) {
                            $tags[] = $tag['name'];
                        }
                    }
                }
                return array_unique($tags);
            }, $extraQuery);
        }

        // v1
        $fullRepoName = $project . '/' . $repository;
        $encodedRepoName = rawurlencode($fullRepoName);
        $path = "/api/repositories/{$encodedRepoName}/tags";
        return $this->paginatedGet($path, $pageSize, $maxPages, function ($page) {
            return array_column($page, 'name');
        });
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
