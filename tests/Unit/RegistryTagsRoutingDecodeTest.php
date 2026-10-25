<?php

declare(strict_types=1);

namespace App\Test\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Factory\AppFactory;
use Slim\Interfaces\RouteCollectorProxyInterface;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * `/api/{harbor,registry}/{project}/repositories/{repository}/tags` 的「双重编码」链路回归测试。
 *
 * 为什么要用真实 Slim 分派而不是只读源码：短名含 `/` 的多段仓库（`mycode/a/b` 的短名 `a/b`）
 * 依赖一条四步链路，任何一步理解错都会误判成「链路断裂（404）」：
 *   1) `src/Bootstrap.php` 只把 `REQUEST_URI` 里的 `%2F` 还原成 `/`，`%252F` 原样保留；
 *   2) `Slim\Routing\RouteResolver::computeRoutingResults()` 对整条路径 `rawurldecode` 一次：
 *      `a%252Fb` → `a%2Fb`（仍是单段，能匹配默认占位符 `{repository}` = `[^/]+`）；
 *   3) FastRoute 自己不再解码（`vendor/nikic` 里没有任何 `*urldecode`），捕获值就是 `a%2Fb`；
 *   4) `RoutingMiddleware` 以默认 `urlDecode=true` 调 `RoutingResults::getRouteArguments()`，
 *      对捕获值再 `rawurldecode` 一次 → 控制器收到 `a/b`。
 * 因此控制器拿到的已是还原后的短名，`DockerRegistryProvider::tagsPath()` 再按 `/` 分段
 * `rawurlencode`，最终请求 `/v2/mycode/a/b/tags/list`。若哪天退化成只解一次，本测试立刻红。
 *
 * 运行：vendor/bin/phpunit tests/Unit/RegistryTagsRoutingDecodeTest.php
 */
class RegistryTagsRoutingDecodeTest extends TestCase
{
    /**
     * 按生产链路分派一次请求：Bootstrap 的 `%2F` 还原 → Slim 真路由（与 config/routes.php 同形）。
     *
     * @return array{status:int,body:string}
     */
    private function dispatch(string $rawUri): array
    {
        $app = AppFactory::create();
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(false, true, true);

        foreach (['/api/harbor', '/api/registry'] as $prefix) {
            $app->group($prefix, function (RouteCollectorProxyInterface $group): void {
                $group->map(
                    ['GET', 'POST'],
                    '/{project}/repositories/{repository}/tags',
                    function (ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface {
                        $response->getBody()->write((string) json_encode([
                            'project'    => $args['project'] ?? null,
                            'repository' => $args['repository'] ?? null,
                        ], JSON_UNESCAPED_SLASHES));

                        return $response->withHeader('Content-Type', 'application/json');
                    }
                );
            });
        }

        // ── 第 1 步：与 src/Bootstrap.php 完全一致 ──
        $_SERVER['REQUEST_URI'] = str_replace('%2F', '/', $rawUri);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_HOST'] = 'localhost';
        $_SERVER['SERVER_NAME'] = 'localhost';
        $_SERVER['SERVER_PORT'] = '8080';
        $_SERVER['HTTP_ACCEPT'] = 'application/json';

        $response = $app->handle(ServerRequestFactory::createFromGlobals());

        return ['status' => $response->getStatusCode(), 'body' => (string) $response->getBody()];
    }

    /**
     * 分派并取出控制器实际收到的路由参数。
     *
     * @return array{project:string,repository:string}
     */
    private function args(string $rawUri): array
    {
        $result = $this->dispatch($rawUri);
        $this->assertSame(200, $result['status'], "{$rawUri} 应能匹配路由（双重编码后仍是单段）");

        $decoded = json_decode($result['body'], true);
        $this->assertIsArray($decoded, "{$rawUri} 响应应为 JSON");
        $this->assertIsString($decoded['project'] ?? null);
        $this->assertIsString($decoded['repository'] ?? null);

        return ['project' => (string) $decoded['project'], 'repository' => (string) $decoded['repository']];
    }

    public function testDoubleEncodedMultiSegmentShortNameReachesControllerDecoded(): void
    {
        
        foreach (['/api/registry', '/api/harbor'] as $prefix) {
            $args = $this->args("{$prefix}/mycode/repositories/a%252Fb/tags");

            $this->assertSame('mycode', $args['project'], "{$prefix} 项目名应原样");
            $this->assertSame(
                'a/b',
                $args['repository'],
                "{$prefix} 控制器应拿到还原后的短名 a/b（提供方据此拼 mycode/a/b 查 tags）"
            );
        }
    }

    public function testThreeSegmentShortNameAlsoDecoded(): void
    {
        $args = $this->args('/api/registry/mycode/repositories/a%252Fb%252Fc/tags');

        $this->assertSame('a/b/c', $args['repository'], '两段以上仓库路径（本版本修复点）同样经双重编码抵达');
    }

    public function testShortNameWithoutSlashUnaffected(): void
    {
        $args = $this->args('/api/registry/mycode/repositories/diagnosis-runtime/tags');

        $this->assertSame('diagnosis-runtime', $args['repository'], '单段短名不走编码，行为不变');
    }

    public function testSingleEncodedSlashIsRestoredByBootstrapAndNotMatched(): void
    {
        
        
        $result = $this->dispatch('/api/registry/mycode/repositories/a%2Fb/tags');

        $this->assertSame(404, $result['status'], '单重编码不匹配单段占位符（Bootstrap 已还原为 /）');
    }

    public function testUnencodedMultiSegmentShortNameNotMatched(): void
    {
        $result = $this->dispatch('/api/registry/mycode/repositories/a/b/tags');

        $this->assertSame(404, $result['status'], '未编码的 `/` 视为额外路径段，必须双重编码');
    }
}
