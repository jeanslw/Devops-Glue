<?php

declare(strict_types=1);

namespace App\Test\Unit;

use App\Service\HarborService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * HarborService::getTagDetails 单元测试
 *
 * 覆盖 tags 详情链路：
 *   - v2 artifacts 端点带 with_tag=true & with_scan_overview=true，按 tag 展开为详情对象；
 *   - tag 级 push_time/pull_time 优先，缺失时回退 artifact 级；
 *   - scan_overview 映射为 scan_status/scan_severity/各级漏洞数；未扫描时全部留空；
 *   - 多段仓库短名按双重编码拼路径；
 *   - getTags 委托 getTagDetails 后仍返回纯名称列表；
 *   - v1 tags 端点保持纯名称列表；
 *   - 首页请求出错时错误数组原样上抛。
 *
 * 使用 Guzzle MockHandler，不触网。
 *
 * 运行：vendor/bin/phpunit tests/Unit/HarborTagDetailsTest.php
 */
class HarborTagDetailsTest extends TestCase
{
    /** @var array<int,array{request:\Psr\Http\Message\RequestInterface}> */
    private array $history = [];

    private function service(MockHandler $mock): HarborService
    {
        $this->history = [];
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->history));
        $client = new Client(['base_uri' => 'http://harbor.test/', 'handler' => $stack]);

        return new HarborService($client, new \PDO('sqlite::memory:'), new \App\Service\I18nService(__DIR__ . '/../../lang'));
    }

    /** v2 探测响应：/api/v2.0/projects 200 即判定 v2 */
    private static function v2Probe(): Response
    {
        return new Response(200, [], (string) json_encode([['name' => 'mycode']]));
    }

    public function testV2ReturnsTagDetailsWithScanOverview(): void
    {
        $artifacts = [
            [
                'digest'    => 'sha256:abc',
                'size'      => 2048,
                'push_time' => '2026-07-17T07:17:04.737Z',
                'pull_time' => '2026-07-18T01:02:03Z',
                'tags'      => [
                    ['name' => 'v1.0.0', 'push_time' => '2026-07-17T08:00:00Z'],
                    ['name' => 'latest'],
                ],
                'scan_overview' => [
                    'application/vnd.security.vulnerability.report; version=1.1' => [
                        'scan_status' => 'Success',
                        'severity'    => 'High',
                        'summary'     => [
                            'total'   => 3,
                            'fixable' => 2,
                            'summary' => ['Critical' => 1, 'High' => 2, 'Medium' => 0, 'Low' => 0],
                        ],
                    ],
                ],
            ],
            ['digest' => 'sha256:untagged', 'size' => 1, 'tags' => null],
        ];
        $mock = new MockHandler([
            self::v2Probe(),
            new Response(200, [], (string) json_encode($artifacts)),
        ]);

        $details = $this->service($mock)->getTagDetails('mycode', 'runner-ci');

        $this->assertCount(2, $details);
        $first = $details[0];
        $this->assertSame('v1.0.0', $first['name']);
        $this->assertSame('sha256:abc', $first['digest']);
        $this->assertSame(2048, $first['size_bytes']);
        $this->assertSame('2026-07-17T08:00:00Z', $first['push_time'], 'tag 级 push_time 优先');
        $this->assertSame('2026-07-18T01:02:03Z', $first['pull_time'], 'tag 级 pull_time 缺失时回退 artifact 级');
        $this->assertSame('Success', $first['scan_status']);
        $this->assertSame('High', $first['scan_severity']);
        $this->assertSame(1, $first['vuln_critical']);
        $this->assertSame(2, $first['vuln_high']);
        $this->assertSame(0, $first['vuln_medium']);
        $this->assertSame(0, $first['vuln_low']);
        $this->assertSame(2, $first['vuln_fixable']);

        $second = $details[1];
        $this->assertSame('latest', $second['name']);
        $this->assertSame('2026-07-17T07:17:04.737Z', $second['push_time'], 'tag 级 push_time 缺失时回退 artifact 级');

        $artifactsReq = $this->history[1]['request'];
        $this->assertSame('/api/v2.0/projects/mycode/repositories/runner-ci/artifacts', $artifactsReq->getUri()->getPath());
        $query = $artifactsReq->getUri()->getQuery();
        $this->assertStringContainsString('with_tag=true', $query);
        $this->assertStringContainsString('with_scan_overview=true', $query);
    }

    public function testV2LeavesScanFieldsEmptyWhenNotScanned(): void
    {
        $artifacts = [
            [
                'digest' => 'sha256:def',
                'size'   => 100,
                'tags'   => [['name' => 'v2.0.0']],
            ],
        ];
        $mock = new MockHandler([
            self::v2Probe(),
            new Response(200, [], (string) json_encode($artifacts)),
        ]);

        $details = $this->service($mock)->getTagDetails('mycode', 'runner-ci');

        $this->assertCount(1, $details);
        $this->assertSame('', $details[0]['scan_status']);
        $this->assertSame('', $details[0]['scan_severity']);
        $this->assertSame(0, $details[0]['vuln_critical']);
        $this->assertSame(0, $details[0]['vuln_fixable']);
    }

    public function testV2DoubleEncodesMultiSegmentShortName(): void
    {
        $mock = new MockHandler([
            self::v2Probe(),
            new Response(200, [], (string) json_encode([
                ['digest' => 'sha256:x', 'size' => 1, 'tags' => [['name' => 'v1']]],
            ])),
        ]);

        $details = $this->service($mock)->getTagDetails('mycode', 'a/b');

        $this->assertCount(1, $details);
        $this->assertSame(
            '/api/v2.0/projects/mycode/repositories/a%252Fb/artifacts',
            $this->history[1]['request']->getUri()->getPath(),
            '多段短名必须双重编码（与既有 getTags 路径拼接一致）'
        );
    }

    public function testGetTagsStillReturnsPlainNames(): void
    {
        $artifacts = [
            [
                'digest' => 'sha256:abc',
                'size'   => 2048,
                'tags'   => [['name' => 'v1.0.0'], ['name' => 'latest']],
            ],
        ];
        $mock = new MockHandler([
            self::v2Probe(),
            new Response(200, [], (string) json_encode($artifacts)),
        ]);

        $names = $this->service($mock)->getTags('mycode', 'runner-ci');

        $this->assertSame(['v1.0.0', 'latest'], $names, '内部消费方按纯名称列表消费（in_array 校验）');
    }

    public function testV1FallsBackToPlainNames(): void
    {
        $mock = new MockHandler([
            new Response(404, [], ''),
            new Response(200, [], (string) json_encode([['name' => 'v9'], ['name' => 'v10']])),
        ]);

        $details = $this->service($mock)->getTagDetails('mycode', 'app');

        $this->assertSame(['v9', 'v10'], $details, 'v1 tags 端点不提供详情字段，保持纯名称列表');
        $this->assertSame(
            '/api/repositories/mycode%2Fapp/tags',
            $this->history[1]['request']->getUri()->getPath()
        );
    }

    public function testFirstPageErrorPropagates(): void
    {
        $mock = new MockHandler([
            self::v2Probe(),
            new Response(404, [], (string) json_encode(['errors' => [['code' => 'PROJECT_NOT_FOUND']]])),
        ]);

        $details = $this->service($mock)->getTagDetails('mycode', 'missing');

        $this->assertArrayHasKey('error', $details);
    }
}
