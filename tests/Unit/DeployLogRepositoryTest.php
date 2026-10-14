<?php

declare(strict_types=1);

namespace App\Test\Unit;

use App\Service\DeployLogRepository;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * DeployLogRepository 契约测试：锁定 CI→CD 部署日志拉取的 HTTP 契约。
 *
 * v2.9.0 起 CI 不再读共享库契约视图，改经 CD 的 /api/integration/deploy-logs 只读端点拉取。
 * 本测试把三件事钉死：
 *   1. 请求带 Authorization: Bearer + 正确的筛选/分页 query（含 per_page 上限钳制）；
 *   2. HTTP 200 + 契约 JSON 正确映射成对外结构（只带审计列，不带 output 等大字段）；
 *   3. CD 未配置 / HTTP 非 200 / 响应非法 / 网络异常都降级为 available=false，绝不 500。
 */
class DeployLogRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // 降级路径会写一条 warning 日志，指向临时目录以免污染真实日志目录
        $_ENV['LOG_PATH'] = sys_get_temp_dir() . DIRECTORY_SEPARATOR;
    }

    protected function tearDown(): void
    {
        unset($_ENV['LOG_PATH']);
        parent::tearDown();
    }

    private function repoWith(array $responses, string $baseUrl = 'https://cd.example', string $token = 'cdtok'): DeployLogRepository
    {
        $mock   = new MockHandler($responses);
        $client = new Client(['handler' => HandlerStack::create($mock), 'http_errors' => false]);
        return new DeployLogRepository($baseUrl, $token, $client);
    }

    private static function json(string $body, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], $body);
    }

    /** HTTP 200 + 契约 JSON → available=true，列映射正确，不带 output 等大字段。 */
    public function testListMapsContractResponse(): void
    {
        $repo = $this->repoWith([
            self::json(json_encode([
                'total'       => 1,
                'page'        => 1,
                'per_page'    => 20,
                'total_pages' => 1,
                'items'       => [[
                    'id'           => 7,
                    'project'      => 'shop',
                    'tag'          => 'v1.2.3',
                    'image'        => 'reg/shop:v1.2.3',
                    'deploy_type'  => 'release',
                    'target'       => 'prod',
                    'status'       => 'success',
                    'triggered_by' => 'alice',
                    'deploy_note'  => 'hotfix',
                    'created_at'   => '2026-10-15 09:00:00',
                    'output'       => 'HUGE LOG...',
                ]],
            ])),
        ]);

        $result = $repo->list(['project' => 'shop'], 1, 20);

        $this->assertTrue($result['available']);
        $this->assertSame(1, $result['total']);
        $this->assertSame('v1.2.3', $result['items'][0]['tag']);
        $this->assertArrayNotHasKey('output', $result['items'][0], '审计列表不得带出 output 等大字段');
    }

    /** 请求必须带 Bearer token 与正确的筛选/分页 query（含 per_page 上限钳制、空筛选不出现）。 */
    public function testRequestCarriesBearerTokenAndQuery(): void
    {
        $mock   = new MockHandler([
            self::json(json_encode(['total' => 0, 'page' => 2, 'per_page' => 100, 'total_pages' => 1, 'items' => []])),
        ]);
        $client = new Client(['handler' => HandlerStack::create($mock), 'http_errors' => false]);
        $repo   = new DeployLogRepository('https://cd.example', 'cdtok', $client);

        $repo->list(['status' => 'success', 'deploy_type' => 'release', 'date_from' => '2026-10-01'], 2, 999);

        $req = $mock->getLastRequest();
        $this->assertSame('Bearer cdtok', $req->getHeaderLine('Authorization'));
        $this->assertSame('/api/integration/deploy-logs', $req->getUri()->getPath());

        parse_str($req->getUri()->getQuery(), $query);
        $this->assertSame('success', $query['status']);
        $this->assertSame('release', $query['deploy_type']);
        $this->assertSame('2026-10-01', $query['date_from']);
        $this->assertSame('2', $query['page']);
        $this->assertSame('100', $query['per_page'], 'per_page 上限钳制到 100');
        $this->assertArrayNotHasKey('project', $query, '空筛选不应出现在 query 里');
    }

    /** CD 地址 / token 未配置时降级，不发请求。 */
    public function testUnconfiguredDegradesGracefully(): void
    {
        $repo = new DeployLogRepository('', '');

        $result = $repo->list([], 1, 20);

        $this->assertFalse($result['available']);
        $this->assertSame(0, $result['total']);
        $this->assertSame([], $result['items']);
    }

    /** CD 端点返回非 200（如 503 db_unavailable）时降级为占位提示，绝不 500。 */
    public function testNon200DegradesGracefully(): void
    {
        $repo = $this->repoWith([
            self::json(json_encode(['error_key' => 'errors.db_unavailable']), 503),
        ]);

        $result = $repo->list([], 1, 20);

        $this->assertFalse($result['available']);
        $this->assertSame(0, $result['total']);
        $this->assertSame([], $result['items']);
    }

    /** 200 但响应体非 JSON 时降级（契约被破坏也不能抛 500）。 */
    public function testInvalidBodyDegradesGracefully(): void
    {
        $repo = $this->repoWith([
            new Response(200, ['Content-Type' => 'text/html'], '<html>proxy error</html>'),
        ]);

        $result = $repo->list([], 1, 20);

        $this->assertFalse($result['available']);
        $this->assertSame(0, $result['total']);
        $this->assertSame([], $result['items']);
    }

    /** 网络异常（连接失败/超时）同样降级，不向上抛。 */
    public function testNetworkExceptionDegradesGracefully(): void
    {
        $repo = $this->repoWith([
            new RequestException('connection refused', new Request('GET', 'https://cd.example/api/integration/deploy-logs')),
        ]);

        $result = $repo->list([], 1, 20);

        $this->assertFalse($result['available']);
        $this->assertSame(0, $result['total']);
        $this->assertSame([], $result['items']);
    }
}
