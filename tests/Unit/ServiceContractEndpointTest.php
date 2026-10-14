<?php

declare(strict_types=1);

namespace App\Test\Unit;

use App\Config\AppConfig;
use App\Controller\ServiceContractController;
use App\Service\I18nService;
use App\Service\IntegratedServiceRepository;
use App\Support\ApiScopeResolver;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * 需求 2（CD/CI 解耦）：`GET /api/integration/services/{service}` 的契约测试。
 *
 * 端点存在的全部意义是「让 CD 不必直读共享库就知道自己是否被授权」，因此判定的三种组合
 * （未启用 / auto / manual）与「404 ≠ 未启用」必须逐条钉死：一旦 404 被当成未启用，
 * 服务名拼错的 CD 会静默退化成「一直未启用」而无人察觉。
 *
 * 同时锁四层接线：路由注册、容器定义（漏了是运行时 500，单测不会失败）、scope（build.read）、
 * 控制器内二次权限（ci.build-records.pull，靠 API_SCOPE_PERMS 注入，漏了 API token 仍吃 403）。
 */
class ServiceContractEndpointTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    private \PDO $pdo;
    private ServiceContractController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $this->createTables();

        $this->controller = new ServiceContractController(
            new I18nService(self::ROOT . '/lang', 'zh_CN'),
            new IntegratedServiceRepository($this->pdo)
        );
    }

    public function testContractRowMissingMeansNotEnabledNotNotFound(): void
    {
        $res = $this->call('cd');

        $this->assertSame(200, $res['status'], '未启用必须是 200 + enabled=false，不能是 404');
        $this->assertSame('cd', $res['body']['service']);
        $this->assertFalse($res['body']['enabled']);
        $this->assertSame('auto', $res['body']['provision'], '未启用时 provision 归一化为 auto（仅措辞，不可据此建表）');
        $this->assertFalse($res['body']['token_active']);
        $this->assertNull($res['body']['updated_at']);
    }

    public function testEnabledAutoAuthorizesAutomaticDdl(): void
    {
        $this->insertContract('cd', 1, 'auto', null);

        $res = $this->call('cd');

        $this->assertSame(200, $res['status']);
        $this->assertTrue($res['body']['enabled']);
        $this->assertSame('auto', $res['body']['provision'], 'auto = 授权自动建表 / 补列');
    }

    public function testEnabledManualForbidsDdl(): void
    {
        $this->insertContract('cd', 1, 'manual', '2026-10-14 10:00:00');

        $res = $this->call('cd');

        $this->assertTrue($res['body']['enabled']);
        $this->assertSame('manual', $res['body']['provision'], 'manual = 禁止建表，只认运维预建表');
        $this->assertSame('2026-10-14 10:00:00', $res['body']['updated_at']);
    }

    public function testDisabledRowIsStillOkWithEnabledFalse(): void
    {
        $this->insertContract('cd', 0, 'manual', '2026-10-14 10:00:00');

        $res = $this->call('cd');

        $this->assertSame(200, $res['status']);
        $this->assertFalse($res['body']['enabled'], 'enabled=0 对外与「行不存在」同义');
    }

    public function testUnknownServiceIsNotFoundSoItCannotBeMistakenForNotEnabled(): void
    {
        $res = $this->call('jenkins');

        $this->assertSame(404, $res['status'], '白名单外的服务名是配置错误，必须与「未启用」可区分');
        $this->assertArrayNotHasKey('enabled', $res['body']);
    }

    public function testPayloadCarriesNoManagementSideFields(): void
    {
        $this->insertContract('cd', 1, 'auto', null);

        $body = $this->call('cd')['body'];

        $this->assertSame(
            ['service', 'enabled', 'provision', 'token_active', 'updated_at'],
            array_keys($body),
            '字段集合即契约：新增字段须同时改 OpenAPI 与文档'
        );
        foreach (['token_id', 'token_name', 'token_scopes', 'updated_by', 'default_provision'] as $managementField) {
            $this->assertArrayNotHasKey($managementField, $body, "{$managementField} 属管理面字段，不该暴露给集成服务");
        }
    }

    public function testMissingCiReadPermissionIsRejected(): void
    {
        $res = $this->call('cd', []);

        $this->assertSame(403, $res['status'], '无 ci.build-records.pull 时不返回契约内容');
    }

    public function testRouteAndContainerWiringExists(): void
    {
        $routes = (string) file_get_contents(self::ROOT . '/config/routes.php');
        $this->assertStringContainsString(
            "'/services/{service}', [ServiceContractController::class, 'show']",
            $routes,
            '路由漏注册 = 端点 404（CD 会以为是旧版 CI 而回退，问题被掩盖）'
        );

        $container = (string) file_get_contents(self::ROOT . '/config/container.php');
        $this->assertStringContainsString(
            'ServiceContractController::class => function',
            $container,
            '容器漏定义 = 运行时 500，而既有单测全绿'
        );
    }

    public function testScopeReusesBuildReadAndInjectsTheCiReadPermission(): void
    {
        $this->assertSame(
            AppConfig::API_SCOPE_BUILD_READ,
            ApiScopeResolver::resolve('GET', '/api/integration/services/cd'),
            '整个 /api/integration 段判 build.read：CD 凭据已持有该 scope，无需为只读端点扩权'
        );
        $this->assertContains(
            AppConfig::PERM_CI_BUILD_RECORDS_PULL,
            AppConfig::API_SCOPE_PERMS[AppConfig::API_SCOPE_BUILD_READ],
            '控制器内二次校验用 ci.build-records.pull：中间件不注入它，API token 放行后仍会吃 403'
        );
        $this->assertContains(
            AppConfig::API_SCOPE_BUILD_READ,
            AppConfig::CD_SERVICE_TOKEN_SCOPES,
            'CD 服务凭据必须持有 build.read，否则本端点对 CD 不可达'
        );
    }

    public function testOpenApiDocumentsTheContractEndpointInBothLanguages(): void
    {
        foreach (['templates/openapi.json', 'templates/openapi.en.json'] as $file) {
            $doc = json_decode((string) file_get_contents(self::ROOT . '/' . $file), true);
            $this->assertIsArray($doc, "{$file} 必须是合法 JSON");
            $this->assertArrayHasKey('/api/integration/services/{service}', $doc['paths'], "{$file} 缺少契约端点");

            $get = $doc['paths']['/api/integration/services/{service}']['get'];
            $this->assertSame(['Integration'], $get['tags'], "{$file} 应归入 Integration 标签");
            $props = $get['responses']['200']['content']['application/json']['schema']['properties'];
            $this->assertSame('boolean', $props['enabled']['type']);
            $this->assertSame(['auto', 'manual'], $props['provision']['enum'], 'provision 取值必须与契约常量一致');
        }
    }

    /**
     * @param list<string>|null $perms 省略即视为持有 build.read 注入后的读权限
     * @return array{status:int, body:array<string,mixed>}
     */
    private function call(string $service, ?array $perms = null): array
    {
        $perms ??= [AppConfig::PERM_CI_BUILD_RECORDS_PULL];

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/integration/services/' . $service)
            ->withAttribute('currentRole', AppConfig::ROLE_API_TOKEN)
            ->withAttribute('userPermissions', $perms);
        $response = (new ResponseFactory())->createResponse();

        $result = $this->controller->show($request, $response, ['service' => $service]);
        $decoded = json_decode((string) $result->getBody(), true);

        return ['status' => $result->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }

    private function createTables(): void
    {
        // 只建契约端点为真读到的两张表（不跑 ensureTables：本测试关注端点契约，不关注库结构）
        $this->pdo->exec(
            'CREATE TABLE ' . AppConfig::TABLE_INTEGRATED_SERVICES
            . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, service_name TEXT, enabled INTEGER, provision TEXT,'
            . ' api_token_id INTEGER, updated_by TEXT, created_at TEXT, updated_at TEXT)'
        );
        $this->pdo->exec(
            'CREATE TABLE ' . AppConfig::TABLE_API_TOKENS
            . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, enabled INTEGER, expires_at INTEGER, scopes TEXT)'
        );
    }

    private function insertContract(string $service, int $enabled, string $provision, ?string $updatedAt): void
    {
        $this->pdo->prepare(
            'INSERT INTO ' . AppConfig::TABLE_INTEGRATED_SERVICES
            . ' (service_name, enabled, provision, api_token_id, updated_by, created_at, updated_at)'
            . ' VALUES (?, ?, ?, NULL, ?, ?, ?)'
        )->execute([$service, $enabled, $provision, 'root', $updatedAt, $updatedAt]);
    }
}

