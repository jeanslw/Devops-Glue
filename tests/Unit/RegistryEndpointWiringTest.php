<?php

declare(strict_types=1);

namespace App\Test\Unit;

use App\Config\AppConfig;
use App\Controller\RegistryController;
use App\Service\HarborService;
use App\Service\I18nService;
use App\Service\Registry\HarborProvider;
use App\Service\Registry\RegistryProviderInterface;
use App\Support\ApiScopeResolver;
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * `/api/registry/projects` + `/api/registry/{project}/repositories` +
 * `/api/registry/{project}/repositories/{repository}/tags` 的跨层接线测试。
 *
 * 端点存在的意义：`registry:2`（标准 OCI Distribution）部署下没有任何接口能列出项目/仓库
 * ——`/api/harbor/*` 只认 Harbor REST API。这条链路横跨 6 层，任一层漏做都不会被别的单测发现：
 *   1) 路由      —— 有控制器没路由 = 404；
 *   2) 容器      —— 漏注册 = 运行时 500（路由存在，其它单测全绿也发现不了）；
 *   3) scope     —— 未知路径 fail-closed，漏映射则 API token（含 CD 服务凭据）一律 403；
 *   4) 权限注入  —— 控制器内 requirePermission(ci.build-records.pull)，靠 API_SCOPE_PERMS 注入；
 *   5) 提供方    —— 必须按 registry_type 分派（Harbor / registry:2 都能列）；
 *   6) OpenAPI   —— 漏记则对接方不知道端点存在（中英双份必须同步）。
 */
class RegistryEndpointWiringTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    private function read(string $relative): string
    {
        $content = file_get_contents(self::ROOT . '/' . $relative);
        $this->assertIsString($content, "{$relative} 必须存在");

        return $content;
    }

    private function controller(?RegistryProviderInterface $provider): RegistryController
    {
        return new RegistryController(new I18nService(self::ROOT . '/lang', 'zh_CN'), $provider);
    }

    /**
     * 固定结果的假提供方（不触网）。
     *
     * @param array<int|string,mixed> $projects
     * @param array<int|string,mixed> $repositories
     */
    private function fakeProvider(array $projects, array $repositories): RegistryProviderInterface
    {
        return new class ($projects, $repositories) implements RegistryProviderInterface {
            
            public function __construct(private array $projects, private array $repositories)
            {
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
                return [];
            }

            public function getTagDetails(string $repository): array
            {
                return [];
            }

            public function repositoryExists(string $repository): bool
            {
                return false;
            }

            public function getProjects(): array
            {
                return $this->projects;
            }

            public function getRepositories(string $project): array
            {
                return $this->repositories;
            }

            public function getScanReport(string $repository, string $tag): array
            {
                return ['error' => '当前镜像仓库类型（registry）不提供漏洞扫描接口'];
            }
        };
    }

    /**
     * @param list<string>|null $perms 省略即视为持有 ci.build-records.pull（AuthMiddleware 按 scope 注入）
     * @return array{status:int,body:mixed}
     */
    private function callProjects(?array $perms, ?RegistryProviderInterface $provider): array
    {
        return $this->call(false, '', $perms, $provider);
    }

    /**
     * @param list<string>|null $perms
     * @return array{status:int,body:mixed}
     */
    private function callRepositories(string $project, ?array $perms, ?RegistryProviderInterface $provider): array
    {
        return $this->call(true, $project, $perms, $provider);
    }

    /**
     * @param list<string>|null $perms
     * @return array{status:int,body:mixed}
     */
    private function callTags(
        string $project,
        string $repository,
        ?array $perms,
        ?RegistryProviderInterface $provider,
        array $query = []
    ): array {
        $perms ??= [AppConfig::PERM_CI_BUILD_RECORDS_PULL];

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/registry/mycode/repositories/runner-ci/tags')
            ->withAttribute('currentRole', AppConfig::ROLE_API_TOKEN)
            ->withAttribute('userPermissions', $perms)
            ->withQueryParams($query);
        $response = (new ResponseFactory())->createResponse();

        $result = $this->controller($provider)->getTagsList($request, $response, [
            'project'    => $project,
            'repository' => $repository,
        ]);

        $decoded = json_decode((string) $result->getBody(), true);

        return ['status' => $result->getStatusCode(), 'body' => $decoded];
    }

    /**
     * @param list<string>|null $perms
     * @return array{status:int,body:mixed}
     */
    private function call(bool $repositories, string $project, ?array $perms, ?RegistryProviderInterface $provider): array
    {
        $perms ??= [AppConfig::PERM_CI_BUILD_RECORDS_PULL];

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', $repositories ? '/api/registry/mycode/repositories' : '/api/registry/projects')
            ->withAttribute('currentRole', AppConfig::ROLE_API_TOKEN)
            ->withAttribute('userPermissions', $perms);
        $response = (new ResponseFactory())->createResponse();

        $controller = $this->controller($provider);
        $result = $repositories
            ? $controller->getRepositoriesList($request, $response, ['project' => $project])
            : $controller->getProjectsList($request, $response, []);

        $decoded = json_decode((string) $result->getBody(), true);

        return ['status' => $result->getStatusCode(), 'body' => $decoded];
    }

    // ---------- 接线 ----------

    public function testRoutesRegisterProjectsAndRepositories(): void
    {
        $routes = $this->read('config/routes.php');

        $this->assertStringContainsString(
            "'/projects', [RegistryController::class, 'getProjectsList']",
            $routes,
            '路由漏注册 = 端点 404，对接方会以为是旧版 Glue'
        );
        $this->assertStringContainsString(
            "'/{project}/repositories', [RegistryController::class, 'getRepositoriesList']",
            $routes
        );
        $this->assertStringContainsString(
            "'/{project}/repositories/{repository}/tags', [RegistryController::class, 'getTagsList']",
            $routes,
            'tags 路由必须同样注册 GET/POST（对接方沿用 Harbor 的调用姿势）'
        );
    }

    public function testContainerWiresControllerWithRegistryProvider(): void
    {
        $container = $this->read('config/container.php');

        $this->assertStringContainsString(
            'RegistryController::class => function',
            $container,
            '容器漏定义 = 运行时 500'
        );
        $this->assertStringContainsString(
            "\$c->get('registryProvider')",
            $container,
            '必须注入按 registry_type 选出的提供方（harbor | registry）'
        );
    }

    public function testDockerProviderListsViaCatalogNotTags(): void
    {
        $src = $this->read('src/Service/Registry/DockerRegistryProvider.php');

        $this->assertStringContainsString("private const CATALOG_PATH = 'v2/_catalog';", $src);
        $this->assertMatchesRegularExpression('/public function getProjects\(/', $src);
        $this->assertMatchesRegularExpression('/public function getRepositories\(string \$project\)/', $src);
    }

    public function testHarborProviderDelegatesToHarborService(): void
    {
        $harbor = new class (new Client(), new \PDO('sqlite::memory:')) extends HarborService {
            public function getProjects(): array
            {
                return ['mycode'];
            }

            public function getRepositories(string $project): array
            {
                return ['runner-ci:' . $project];
            }
        };

        $provider = new HarborProvider($harbor);

        $this->assertSame('harbor', $provider->type());
        $this->assertSame(['mycode'], $provider->getProjects());
        $this->assertSame(['runner-ci:mycode'], $provider->getRepositories('mycode'));
    }

    public function testHarborProviderKeepsMultiSegmentShortName(): void
    {
        
        
        
        $harbor = new class (new Client(), new \PDO('sqlite::memory:')) extends HarborService {
            
            public array $received = ['', ''];

            public function getTags(string $project, string $repository): array
            {
                $this->received = [$project, $repository];
                return ['v1'];
            }
        };

        $provider = new HarborProvider($harbor);

        $this->assertSame(['v1'], $provider->getTags('mycode/a/b'));
        $this->assertSame(['mycode', 'a/b'], $harbor->received, '两段以上短名不得被截断');
    }

    // ---------- scope 与权限 ----------

    public function testScopeReusesHarborReadForRegistryPaths(): void
    {
        $this->assertSame(
            AppConfig::API_SCOPE_REGISTRY_READ,
            ApiScopeResolver::resolve('GET', '/api/registry/projects'),
            '复用 registry.read：已签发的 token（含 CD 服务凭据）无需重签'
        );
        $this->assertSame(
            AppConfig::API_SCOPE_REGISTRY_READ,
            ApiScopeResolver::resolve('GET', '/api/registry/mycode/repositories')
        );
        $this->assertSame(
            AppConfig::API_SCOPE_REGISTRY_READ,
            ApiScopeResolver::resolve('GET', '/api/registry/mycode/repositories/runner-ci/tags'),
            'tags 路径同样走 registry.read 前缀匹配，否则已签发凭据直接 403'
        );
        $this->assertNull(
            ApiScopeResolver::resolve('GET', '/api/registryfoo'),
            '前缀相近的未知路径必须保持 fail-closed'
        );
        $this->assertContains(
            AppConfig::PERM_CI_BUILD_RECORDS_PULL,
            AppConfig::API_SCOPE_PERMS[AppConfig::API_SCOPE_REGISTRY_READ],
            '控制器内二次校验用 ci.build-records.pull：不注入则 API token 放行后仍吃 403'
        );
    }

    // ---------- 控制器行为 ----------

    public function testProjectsEndpointReturnsProviderList(): void
    {
        $res = $this->callProjects(null, $this->fakeProvider(['team', 'zeta'], []));

        $this->assertSame(200, $res['status']);
        $this->assertSame(['team', 'zeta'], $res['body']);
    }

    public function testRepositoriesEndpointPassesProjectNameToProvider(): void
    {
        $provider = new class implements RegistryProviderInterface {
            public string $received = '';

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
                return [];
            }

            public function getTagDetails(string $repository): array
            {
                return [];
            }

            public function repositoryExists(string $repository): bool
            {
                return false;
            }

            public function getProjects(): array
            {
                return [];
            }

            public function getRepositories(string $project): array
            {
                $this->received = $project;
                return ['runner-ci'];
            }

            public function getScanReport(string $repository, string $tag): array
            {
                return ['error' => 'n/a'];
            }
        };

        $res = $this->callRepositories('mycode', null, $provider);

        $this->assertSame(200, $res['status']);
        $this->assertSame(['runner-ci'], $res['body']);
        $this->assertSame('mycode', $provider->received, '路径参数必须原样传给提供方');
    }

    public function testTagsEndpointPassesFullyQualifiedRepositoryToProvider(): void
    {
        $provider = new class implements RegistryProviderInterface {
            public string $received = '';

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
                $this->received = $repository;
                return ['2.8.4-132', 'main-136'];
            }

            public function getTagDetails(string $repository): array
            {
                $this->received = $repository;
                return ['2.8.4-132', 'main-136'];
            }

            public function repositoryExists(string $repository): bool
            {
                return false;
            }

            public function getProjects(): array
            {
                return [];
            }

            public function getRepositories(string $project): array
            {
                return [];
            }

            public function getScanReport(string $repository, string $tag): array
            {
                return ['error' => 'n/a'];
            }
        };

        $res = $this->callTags('mycode', 'devops-glue', null, $provider);

        $this->assertSame(200, $res['status']);
        $this->assertSame(['2.8.4-132', 'main-136'], $res['body']);
        $this->assertSame(
            'mycode/devops-glue',
            $provider->received,
            'tag 查询必须传「项目/短名」拼出的完整仓库名（HarborProvider 内部按两段拆分、registry:2 直接查 /v2/{name}/tags/list）'
        );
    }

    public function testTagsEndpointWithoutRegistryReturns409Not500(): void
    {
        $res = $this->callTags('mycode', 'devops-glue', null, null);

        $this->assertSame(409, $res['status'], '与 projects/repositories 同口径：未配置 url 是配置问题');
    }

    public function testTagsEndpointPaginatesWhenPageParamsPresent(): void
    {
        $tags = [];
        for ($i = 1; $i <= 5; $i++) {
            $tags[] = ['name' => "v1.0.{$i}", 'digest' => "sha256:{$i}", 'size_bytes' => $i];
        }
        $provider = new class ($tags) implements RegistryProviderInterface {
            public function __construct(private array $tags)
            {
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
                return array_column($this->tags, 'name');
            }

            public function getTagDetails(string $repository): array
            {
                return $this->tags;
            }

            public function repositoryExists(string $repository): bool
            {
                return true;
            }

            public function getProjects(): array
            {
                return [];
            }

            public function getRepositories(string $project): array
            {
                return [];
            }

            public function getScanReport(string $repository, string $tag): array
            {
                return ['error' => '当前镜像仓库类型（registry）不提供漏洞扫描接口'];
            }
        };

        // 不带分页参数：保持既有「裸数组」契约（CD 同步等一次性拉全量的调用方不受影响）
        $res = $this->callTags('mycode', 'devops-glue', null, $provider);
        $this->assertSame(200, $res['status']);
        $this->assertSame($tags, $res['body'], '不带 page / page_size 必须原样返回全量详情数组');

        // 带分页参数：返回 {items, total, page, page_size, total_pages} 信封
        $res = $this->callTags('mycode', 'devops-glue', null, $provider, ['page' => '2', 'page_size' => '2']);
        $this->assertSame(200, $res['status']);
        $this->assertSame(
            [
                'items'       => [$tags[2], $tags[3]],
                'total'       => 5,
                'page'        => 2,
                'page_size'   => 2,
                'total_pages' => 3,
            ],
            $res['body'],
            '带 page / page_size 时必须按 /api/build/{path}/artifacts 的分页口径切片'
        );

        // page 越界钳到最后一页
        $res = $this->callTags('mycode', 'devops-glue', null, $provider, ['page' => '99', 'page_size' => '2']);
        $this->assertSame(3, $res['body']['page'], 'page 越界必须钳到 total_pages');
        $this->assertSame([$tags[4]], $res['body']['items']);

        // 只带 page_size（page 缺省 1）；page_size 超范围（>200）回落 50
        $res = $this->callTags('mycode', 'devops-glue', null, $provider, ['page_size' => '999']);
        $this->assertSame(1, $res['body']['page']);
        $this->assertSame(50, $res['body']['page_size'], 'page_size 超范围必须回落默认 50');
        $this->assertSame($tags, $res['body']['items']);

        // 空列表：total_pages 至少为 1
        $empty = new class implements RegistryProviderInterface {
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
                return [];
            }

            public function getTagDetails(string $repository): array
            {
                return [];
            }

            public function repositoryExists(string $repository): bool
            {
                return false;
            }

            public function getProjects(): array
            {
                return [];
            }

            public function getRepositories(string $project): array
            {
                return [];
            }

            public function getScanReport(string $repository, string $tag): array
            {
                return ['error' => '当前镜像仓库类型（registry）不提供漏洞扫描接口'];
            }
        };
        $res = $this->callTags('mycode', 'devops-glue', null, $empty, ['page' => '1']);
        $this->assertSame(
            ['items' => [], 'total' => 0, 'page' => 1, 'page_size' => 50, 'total_pages' => 1],
            $res['body'],
            '空仓库的分页信封 total_pages 必须为 1（与 get_artifacts 的 max(1, ...) 口径一致）'
        );
    }

    public function testMissingCiReadPermissionIsRejected(): void
    {
        $res = $this->callProjects([], $this->fakeProvider(['team'], []));

        $this->assertSame(403, $res['status']);
    }

    public function testUnconfiguredRegistryReturns409Not500(): void
    {
        $res = $this->callProjects(null, null);

        $this->assertSame(409, $res['status'], '镜像仓库未配置 url 属配置问题，不能报成服务异常');
        $this->assertIsArray($res['body']);
        $this->assertNotSame(
            'build.registry_not_configured',
            $res['body']['message'] ?? '',
            '必须输出翻译后的文案，而不是 i18n 键本身'
        );
    }

    public function testProviderBusinessErrorBecomes500WithItsMessage(): void
    {
        $provider = $this->fakeProvider(
            ['error' => '镜像仓库未开启 /v2/_catalog 接口（无法列出仓库）', 'http_code' => 404],
            []
        );

        $res = $this->callProjects(null, $provider);

        
        
        $this->assertSame(500, $res['status']);
        $this->assertSame('镜像仓库未开启 /v2/_catalog 接口（无法列出仓库）', $res['body']['message'] ?? '');
    }

    public function testConnectionFailurePropagatesForMiddlewareToMap502(): void
    {
        $provider = new class implements RegistryProviderInterface {
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
                return [];
            }

            public function getTagDetails(string $repository): array
            {
                return [];
            }

            public function repositoryExists(string $repository): bool
            {
                return false;
            }

            public function getProjects(): array
            {
                throw new \RuntimeException('镜像仓库不可用：连接超时');
            }

            public function getRepositories(string $project): array
            {
                return [];
            }

            public function getScanReport(string $repository, string $tag): array
            {
                return ['error' => 'n/a'];
            }
        };

        // 控制器不吞 RuntimeException：由 AuthMiddleware 统一映射为 502（服务不可达）
        $this->expectException(\RuntimeException::class);

        $this->callProjects(null, $provider);
    }

    public function testMiddlewareStillMapsRuntimeExceptionTo502(): void
    {
        $middleware = $this->read('src/Middleware/AuthMiddleware.php');

        $this->assertStringContainsString("elseif (\$e instanceof \\RuntimeException)", $middleware);
        $this->assertStringContainsString('$statusCode = 502;', $middleware);
    }

    // ---------- 入参校验（路径段不得被拼成上层路径） ----------

    public function testInvalidProjectNameIsRejectedWith400(): void
    {
        foreach (['..', '.', '', 'a/b', 'a b', 'mycode@evil'] as $project) {
            $res = $this->callRepositories($project, null, $this->fakeProvider(['team'], ['app']));

            $this->assertSame(400, $res['status'], "project={$project} 必须被拒（不得拼进提供方 URL）");
        }
    }

    public function testInvalidRepositoryNameIsRejectedWith400(): void
    {
        foreach (['..', 'a/..', '../..', 'a//b', ''] as $repository) {
            $res = $this->callTags('mycode', $repository, null, $this->fakeProvider([], []));

            $this->assertSame(400, $res['status'], "repository={$repository} 必须被拒");
        }
    }

    public function testMultiSegmentShortNameStillAcceptedAfterValidation(): void
    {
        
        foreach (['a/b', 'a/b/c', 'diagnosis-runtime'] as $repository) {
            $res = $this->callTags('mycode', $repository, null, $this->fakeProvider([], []));

            $this->assertSame(200, $res['status'], "repository={$repository} 是合法短名，不得被拦");
        }
    }

    public function testInvalidNameMessageExistsInBothLocales(): void
    {
        
        foreach (['lang/zh_CN/messages.php', 'lang/en/messages.php'] as $file) {
            $this->assertStringContainsString(
                "'build.registry_name_invalid'",
                $this->read($file),
                "{$file} 缺少 build.registry_name_invalid"
            );
        }
    }

    public function testProjectionOnlyTrustsSuccessWritebackStatus(): void
    {
        
        
        $buildController = $this->read('src/Controller/BuildController.php');
        $this->assertStringContainsString(
            "writeback_status = 'success'",
            $buildController,
            '投影必须只认 writeback_status=success'
        );
        $this->assertStringContainsString(
            '$ciStatus === \'success\' ? \'success\' : \'skipped\'',
            $this->read('src/Service/RegistrySyncService.php'),
            '非 success 的 ci_status 必须以 skipped 落库（与推送事实区分）'
        );
    }

    // ---------- OpenAPI ----------

    public function testOpenApiDocumentsAllPathsInBothLanguages(): void
    {
        foreach (['templates/openapi.json', 'templates/openapi.en.json'] as $file) {
            $doc = json_decode($this->read($file), true);
            $this->assertIsArray($doc, "{$file} 必须是合法 JSON");

            foreach (
                [
                    '/api/registry/projects',
                    '/api/registry/{project}/repositories',
                ] as $path
            ) {
                $this->assertArrayHasKey($path, $doc['paths'], "{$file} 缺少 {$path}");
                $get = $doc['paths'][$path]['get'];
                $this->assertSame(['Registry'], $get['tags'], "{$file} {$path} 应归入 Registry 标签");
                $this->assertArrayHasKey('bearerAuth', $get['security'][0], "{$file} {$path} 必须声明 bearer 鉴权");
                $this->assertSame(
                    'array',
                    $get['responses']['200']['content']['application/json']['schema']['type'],
                    "{$file} {$path} 的 200 响应是名称数组"
                );
            }

            $tagsPath = '/api/registry/{project}/repositories/{repository}/tags';
            $this->assertArrayHasKey($tagsPath, $doc['paths'], "{$file} 缺少 {$tagsPath}");
            $tagsGet = $doc['paths'][$tagsPath]['get'];
            $this->assertSame(['Registry'], $tagsGet['tags'], "{$file} {$tagsPath} 应归入 Registry 标签");
            $this->assertArrayHasKey('bearerAuth', $tagsGet['security'][0], "{$file} {$tagsPath} 必须声明 bearer 鉴权");
            $tagsSchema = $tagsGet['responses']['200']['content']['application/json']['schema'];
            $this->assertIsArray($tagsSchema['oneOf'] ?? null, "{$file} {$tagsPath} 的 200 响应必须覆盖「全量数组 / 分页信封」两种形态");
            $this->assertSame(
                'array',
                $tagsSchema['oneOf'][0]['type'] ?? null,
                "{$file} {$tagsPath} 默认形态必须是全量数组"
            );
            $this->assertSame(
                '#/components/schemas/TagDetails',
                $tagsSchema['oneOf'][0]['items']['oneOf'][1]['$ref'] ?? null,
                "{$file} {$tagsPath} 数组元素须引用 TagDetails（详情对象）"
            );
            $this->assertSame(
                '#/components/schemas/PagedTags',
                $tagsSchema['oneOf'][1]['$ref'] ?? null,
                "{$file} {$tagsPath} 分页形态须引用 PagedTags（分页信封）"
            );

            $params = $doc['paths']['/api/registry/{project}/repositories']['get']['parameters'];
            $this->assertSame('project', $params[0]['name'] ?? '', "{$file} 仓库列表必须声明 project 路径参数");

            $tagParams = $doc['paths'][$tagsPath]['get']['parameters'];
            $this->assertSame(
                ['project', 'repository'],
                array_column($tagParams, 'name'),
                "{$file} tag 列表必须声明 project + repository 两个路径参数"
            );
            $this->assertTrue(
                $tagParams[1]['allowReserved'] ?? false,
                "{$file} repository 短名可含 `/`（须双重编码），不声明 allowReserved 时 Swagger UI 会二次转义"
            );
            $paramRefs = array_column($tagParams, '$ref');
            $this->assertContains('#/components/parameters/page', $paramRefs, "{$file} {$tagsPath} 必须声明 page 分页参数");
            $this->assertContains('#/components/parameters/page_size', $paramRefs, "{$file} {$tagsPath} 必须声明 page_size 分页参数");
        }
    }
}
