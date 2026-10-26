<?php
declare(strict_types=1);

namespace App\Test\Unit;

use App\Service\Registry\DockerRegistryProvider;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * DockerRegistryProvider 单元测试
 *
 * 覆盖标准 OCI Distribution（registry:2）访问行为：
 *   - /v2/{name}/tags/list 正常返回 tag 列表；
 *   - 多段仓库短名（`mycode/a/b`）按 `/` 分段编码为 /v2/mycode/a/b/tags/list（不整段编码）；
 *   - Link: rel="next" 分页时携带 last 参数继续翻页；
 *   - 仓库不存在（404）返回错误数组而不是抛异常；
 *   - 401 Bearer challenge 换 token 后带上 Bearer 重试一次；
 *   - 服务端 5xx 抛 RuntimeException，交由上层映射；
 *   - /v2/_catalog 列仓库：项目按仓库名首段聚合（单段根级仓库不计入）；
 *   - 项目名恒为字符串：纯数字首段（"123/app"）不被 PHP 数组键强转成 JSON 数字；
 *   - _catalog 的 n/last + Link 翻页（last 为排除式，Link 里的 last 是 URL 编码值）；
 *   - _catalog 被关闭（404/405）返回明确错误，不伪装成「没有项目」；
 *   - catalog.maxentries 配得比请求的 n 小时（PAGINATION_NUMBER_INVALID）退化为不传 n 重试。
 *
 * 使用 Guzzle MockHandler，不触网。
 *
 * 运行：vendor/bin/phpunit tests/Unit/DockerRegistryProviderTest.php
 */
class DockerRegistryProviderTest extends TestCase
{
    /** @var array<int,array{request:\Psr\Http\Message\RequestInterface}> */
    private array $history = [];

    private function provider(MockHandler $mock): DockerRegistryProvider
    {
        return $this->providerWith($mock, '', '');
    }

    private function providerWith(MockHandler $mock, string $username, string $password, string $baseUri = 'http://registry.test/'): DockerRegistryProvider
    {
        $this->history = [];
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->history));
        $client = new Client(['base_uri' => $baseUri, 'handler' => $stack]);

        return new DockerRegistryProvider($client, $username, $password, null);
    }

    public function testGetTagsReturnsList(): void
    {
        $mock = new MockHandler([
            new Response(200, [], (string) json_encode(['name' => 'team/app', 'tags' => ['v1', 'v2']])),
        ]);
        $tags = $this->provider($mock)->getTags('team/app');

        $this->assertSame(['v1', 'v2'], $tags);
        $this->assertCount(1, $this->history);
        $this->assertSame('/v2/team/app/tags/list', $this->history[0]['request']->getUri()->getPath());
        $this->assertStringContainsString('n=100', $this->history[0]['request']->getUri()->getQuery());
    }

    public function testGetTagsKeepsMultiSegmentRepositoryPath(): void
    {
        
        
        
        $mock = new MockHandler([
            new Response(200, [], (string) json_encode(['name' => 'mycode/a/b', 'tags' => ['v1']])),
        ]);
        $tags = $this->provider($mock)->getTags('mycode/a/b');

        $this->assertSame(['v1'], $tags);
        $this->assertSame('/v2/mycode/a/b/tags/list', $this->history[0]['request']->getUri()->getPath());
    }

    public function testGetTagsFollowsLinkPagination(): void
    {
        $mock = new MockHandler([
            new Response(200, [
                'Link' => '</v2/team/app/tags/list?n=100&last=b>; rel="next"',
            ], (string) json_encode(['tags' => ['a', 'b']])),
            new Response(200, [], (string) json_encode(['tags' => ['c']])),
        ]);
        $tags = $this->provider($mock)->getTags('team/app');

        $this->assertSame(['a', 'b', 'c'], $tags);
        $this->assertCount(2, $this->history);
        $this->assertStringContainsString('last=b', $this->history[1]['request']->getUri()->getQuery());
    }

    public function testGetTagsReturnsErrorWhenRepositoryMissing(): void
    {
        $mock = new MockHandler([new Response(404, [], '{"errors":[{"code":"NAME_UNKNOWN"}]}')]);
        $result = $this->provider($mock)->getTags('team/missing');

        $this->assertArrayHasKey('error', $result);
        $this->assertSame(404, $result['http_code']);
    }

    public function testGetTagsRetriesWithBearerTokenOn401(): void
    {
        $mock = new MockHandler([
            new Response(401, [
                'WWW-Authenticate' => 'Bearer realm="http://auth.test/token",service="registry.test",scope="repository:team/app:pull"',
            ]),
            new Response(200, [], (string) json_encode(['token' => 'jwt-token'])),
            new Response(200, [], (string) json_encode(['tags' => ['v1']])),
        ]);
        $tags = $this->provider($mock)->getTags('team/app');

        $this->assertSame(['v1'], $tags);
        $this->assertCount(3, $this->history);
        $tokenUri = $this->history[1]['request']->getUri();
        $this->assertSame('auth.test', $tokenUri->getHost());
        $this->assertSame('/token', $tokenUri->getPath());
        $this->assertStringContainsString('service=registry.test', $tokenUri->getQuery());
        $this->assertStringContainsString('scope=repository%3Ateam%2Fapp%3Apull', $tokenUri->getQuery());
        $this->assertSame('Bearer jwt-token', $this->history[2]['request']->getHeaderLine('Authorization'));
    }

    public function testGetTagsThrowsOnServerError(): void
    {
        $mock = new MockHandler([new Response(500, [], '')]);

        $this->expectException(\RuntimeException::class);
        $this->provider($mock)->getTags('team/app');
    }

    public function testGetTagDetailsParsesDigestAndSize(): void
    {
        $manifest = [
            'schemaVersion' => 2,
            'mediaType'     => 'application/vnd.docker.distribution.manifest.v2+json',
            'config'        => ['mediaType' => 'application/vnd.docker.container.image.v1+json', 'size' => 1234, 'digest' => 'sha256:cfg'],
            'layers'        => [
                ['mediaType' => 'application/vnd.docker.image.rootfs.diff.tar.gzip', 'size' => 2000, 'digest' => 'sha256:l1'],
                ['mediaType' => 'application/vnd.docker.image.rootfs.diff.tar.gzip', 'size' => 3000, 'digest' => 'sha256:l2'],
            ],
        ];
        $mock = new MockHandler([
            new Response(200, [], (string) json_encode(['name' => 'team/app', 'tags' => ['v1']])),
            new Response(200, ['Docker-Content-Digest' => 'sha256:manifestdigest'], (string) json_encode($manifest)),
        ]);
        $details = $this->provider($mock)->getTagDetails('team/app');

        $this->assertCount(1, $details);
        $this->assertSame('v1', $details[0]['name']);
        $this->assertSame('sha256:manifestdigest', $details[0]['digest']);
        // config(1234) + layers(2000 + 3000) = 6234
        $this->assertSame(6234, $details[0]['size_bytes']);
        $this->assertNull($details[0]['push_time']);
        $this->assertNull($details[0]['scan_status']);
    }

    public function testGetTagDetailsResolvesManifestListToPlatformSize(): void
    {
        $list = [
            'schemaVersion' => 2,
            'mediaType'     => 'application/vnd.docker.distribution.manifest.list.v2+json',
            'manifests'     => [
                ['mediaType' => 'application/vnd.docker.distribution.manifest.v2+json', 'digest' => 'sha256:amd64', 'platform' => ['architecture' => 'amd64', 'os' => 'linux']],
                ['mediaType' => 'application/vnd.docker.distribution.manifest.v2+json', 'digest' => 'sha256:arm64', 'platform' => ['architecture' => 'arm64', 'os' => 'linux']],
            ],
        ];
        $amd64Manifest = [
            'schemaVersion' => 2,
            'mediaType'     => 'application/vnd.docker.distribution.manifest.v2+json',
            'config'        => ['size' => 100, 'digest' => 'sha256:c'],
            'layers'        => [['size' => 900, 'digest' => 'sha256:l']],
        ];
        $mock = new MockHandler([
            new Response(200, [], (string) json_encode(['name' => 'team/app', 'tags' => ['v1']])),
            new Response(200, ['Docker-Content-Digest' => 'sha256:listdigest'], (string) json_encode($list)),
            new Response(200, ['Docker-Content-Digest' => 'sha256:amd64resolved'], (string) json_encode($amd64Manifest)),
        ]);
        $details = $this->provider($mock)->getTagDetails('team/app');

        $this->assertSame('sha256:amd64resolved', $details[0]['digest']);
        $this->assertSame(1000, $details[0]['size_bytes']); // 100 + 900
    }

    public function testHttpsRegistryRefusesHttpRealm(): void
    {
        
        
        $mock = new MockHandler([
            new Response(401, [
                'WWW-Authenticate' => 'Bearer realm="http://auth.test/token",service="registry.test",scope="repository:team/app:pull"',
            ]),
        ]);
        $result = $this->providerWith($mock, 'user', 'pass', 'https://registry.test/')->getTags('team/app');

        $this->assertSame(['error' => '镜像仓库认证失败', 'http_code' => 401], $result);
        $this->assertCount(1, $this->history);
    }

    public function testHttpsRegistrySendsCredentialsToHttpsRealm(): void
    {
        
        $mock = new MockHandler([
            new Response(401, [
                'WWW-Authenticate' => 'Bearer realm="https://auth.test/token",service="registry.test",scope="repository:team/app:pull"',
            ]),
            new Response(200, [], (string) json_encode(['token' => 'jwt-token'])),
            new Response(200, [], (string) json_encode(['tags' => ['v1']])),
        ]);
        $tags = $this->providerWith($mock, 'user', 'pass', 'https://registry.test/')->getTags('team/app');

        $this->assertSame(['v1'], $tags);
        $this->assertCount(3, $this->history);
        $this->assertSame('https', $this->history[1]['request']->getUri()->getScheme());
        $this->assertSame('auth.test', $this->history[1]['request']->getUri()->getHost());
        $this->assertStringContainsString('Basic ', $this->history[1]['request']->getHeaderLine('Authorization'));
        $this->assertSame('Bearer jwt-token', $this->history[2]['request']->getHeaderLine('Authorization'));
    }

    public function testRepositoryExistsFalseOn404(): void
    {
        $mock = new MockHandler([new Response(404, [], '')]);

        $this->assertFalse($this->provider($mock)->repositoryExists('team/missing'));
    }

    public function testScanReportIsUnavailable(): void
    {
        $mock = new MockHandler([]);
        $result = $this->provider($mock)->getScanReport('team/app', 'v1');

        $this->assertArrayHasKey('error', $result);
        $this->assertFalse($this->provider(new MockHandler([]))->supportsScanning());
    }

    public function testAnonymousAccessSendsNoAuthorizationHeader(): void
    {
        $mock = new MockHandler([
            new Response(200, [], (string) json_encode(['tags' => ['v1']])),
        ]);
        
        $this->providerWith($mock, 'admin', '')->getTags('team/app');

        $this->assertSame('', $this->history[0]['request']->getHeaderLine('Authorization'));
    }

    public function testBasicAuthSentWhenUsernameAndPasswordSet(): void
    {
        $mock = new MockHandler([
            new Response(200, [], (string) json_encode(['tags' => ['v1']])),
        ]);
        $this->providerWith($mock, 'user', 'pass')->getTags('team/app');

        $this->assertSame('Basic ' . base64_encode('user:pass'), $this->history[0]['request']->getHeaderLine('Authorization'));
    }

    // ---------- /v2/_catalog（项目 / 仓库列表） ----------

    public function testGetProjectsAggregatesCatalogFirstSegment(): void
    {
        $mock = new MockHandler([
            new Response(200, [], (string) json_encode([
                'repositories' => ['team/app', 'team/sub/img', 'zeta/x', 'rootonly'],
            ])),
        ]);
        $projects = $this->provider($mock)->getProjects();

        $this->assertSame(['team', 'zeta'], $projects, '单段根级仓库不计入项目（平台侧仓库名强制至少两段）');
        $this->assertCount(1, $this->history);
        $this->assertSame('/v2/_catalog', $this->history[0]['request']->getUri()->getPath());
        $this->assertStringContainsString('n=100', $this->history[0]['request']->getUri()->getQuery());
    }

    public function testGetProjectsKeepsNumericFirstSegmentAsString(): void
    {
        $mock = new MockHandler([
            new Response(200, [], (string) json_encode([
                'repositories' => ['123/app', 'team/x'],
            ])),
        ]);
        $projects = $this->provider($mock)->getProjects();

        
        
        $this->assertSame(['123', 'team'], $projects);
        foreach ($projects as $project) {
            $this->assertIsString($project);
        }
    }

    public function testGetProjectsFollowsCatalogLinkPagination(): void
    {
        $mock = new MockHandler([
            
            new Response(200, [
                'Link' => '</v2/_catalog?last=team%2Fapp&n=100>; rel="next"',
            ], (string) json_encode(['repositories' => ['team/app']])),
            new Response(200, [], (string) json_encode(['repositories' => ['team/db', 'other/x']])),
        ]);
        $projects = $this->provider($mock)->getProjects();

        $this->assertSame(['other', 'team'], $projects);
        $this->assertCount(2, $this->history);
        parse_str($this->history[1]['request']->getUri()->getQuery(), $params);
        $this->assertSame('team/app', $params['last'] ?? null, 'Link 里的 last 要解码后原值续页');
    }

    public function testGetRepositoriesStripsProjectPrefixOnly(): void
    {
        $mock = new MockHandler([
            new Response(200, [], (string) json_encode([
                'repositories' => ['team/app', 'team/sub/img', 'other/x', 'teamwork/y'],
            ])),
        ]);
        $repos = $this->provider($mock)->getRepositories('team');

        $this->assertSame(['app', 'sub/img'], $repos, '只取 “团队/” 前缀：teamwork/y 不能误匹配');
    }

    public function testGetRepositoriesReturnsErrorForUnknownProject(): void
    {
        $mock = new MockHandler([
            new Response(200, [], (string) json_encode(['repositories' => ['team/app']])),
        ]);
        $result = $this->provider($mock)->getRepositories('nope');

        $this->assertArrayHasKey('error', $result);
        $this->assertSame(404, $result['http_code']);
    }

    public function testCatalogDisabledReturnsExplicitError(): void
    {
        
        $mock = new MockHandler([new Response(404, [], '{"errors":[{"code":"UNSUPPORTED"}]}')]);
        $result = $this->provider($mock)->getProjects();

        $this->assertArrayHasKey('error', $result);
        $this->assertSame(404, $result['http_code']);
    }

    public function testCatalogPaginationNumberTooLargeFallsBackWithoutN(): void
    {
        
        $mock = new MockHandler([
            new Response(400, [], '{"errors":[{"code":"PAGINATION_NUMBER_INVALID","detail":{"n":100}}]}'),
            new Response(200, [
                'Link' => '</v2/_catalog?last=team%2Fapp&n=1>; rel="next"',
            ], (string) json_encode(['repositories' => ['team/app']])),
            new Response(200, [], (string) json_encode(['repositories' => ['team/db']])),
        ]);
        $projects = $this->provider($mock)->getProjects();

        $this->assertSame(['team'], $projects);
        $this->assertCount(3, $this->history);
        $this->assertSame('', $this->history[1]['request']->getUri()->getQuery(), '退化为不传 n 重试（由仓库用自己的页大小）');
        $this->assertSame('/v2/_catalog', $this->history[1]['request']->getUri()->getPath());
    }
}
