<?php

declare(strict_types=1);

namespace App\Test\Unit;

use App\Config\AppConfig;
use App\Controller\RbacController;
use App\Service\AdminUserRepository;
use App\Service\Database;
use App\Service\I18nService;
use App\Service\Settings;
use App\Service\TokenService;
use App\Support\ApiScopeResolver;
use DI\ContainerBuilder;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * 守卫不变量：CD 经 Glue OIDC 登录的四项 P0 接线（定稿见 docs/CD接入Glue-OIDC设计文档.md）。
 *
 *   1. P0-1：`oauth_clients` 注册 `cd` 客户端，secret / redirect_uri **都**从 env 注入且默认空串
 *      → 空即被 `OAuthService::__construct` 的 fail-closed 过滤剔除（否则空 secret 与空输入
 *      `hash_equals` 相等 = 公开默认密钥）；回调地址没有「合理默认」，给 localhost 反倒是隐患。
 *   2. P0-0：授权时按 client 声明的归属系统校验账号 —— 写死 CI 会让所有 CD 账号（systems='cd'）
 *      在登录页被拒（auth.no_ci_access）；同时**必须**保留 `?: SYSTEM_CI` 回退，否则既有
 *      Grafana / Jenkins 等未声明 system 的客户端会全体登录失败。
 *   3. P0-2：access token 落库前 sha256 —— cache 表存明文等于「库表或备份泄露即所有在途令牌泄露」。
 *   4. P0-4：`GET /api/rbac/roles/{name}/permissions` 上线且 CD 自己的服务 token 就能调通
 *      （scope 复用 rbac.user.write；若哪天给它单开 scope，CD 会在权限映射上一路 403）。
 *
 * 为什么值得守：以上四点**全都不会让业务单测变红**，只会在浏览器 / 生产上表现为
 * 「CD 登不进 Glue」「既有 SSO 全挂」「令牌随备份泄露」「CD 把角色权限读成空」。
 */
class CdOidcLoginWiringTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    protected function setUp(): void
    {
        parent::setUp();
        $_ENV['ADMIN_USER']     = 'admin';
        $_ENV['ADMIN_PASSWORD'] = 'secret';
        // 避免 ensureTables() 里 ALTER 失败触发的日志写到 /data/logs 污染环境
        $_ENV['LOG_PATH'] = sys_get_temp_dir() . DIRECTORY_SEPARATOR;
        Database::reset();
    }

    protected function tearDown(): void
    {
        unset($_ENV['ADMIN_USER'], $_ENV['ADMIN_PASSWORD'], $_ENV['LOG_PATH']);
        Database::reset();
        parent::tearDown();
    }

    private function read(string $relativePath): string
    {
        return (string) file_get_contents(self::ROOT . '/' . $relativePath);
    }

    private function memoryPdo(): \PDO
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        return $pdo;
    }

    /** P0-1：cd 客户端注册，且 secret / redirect_uri 均为 env 注入、默认空串（fail-closed）。 */
    public function testCdClientRegisteredWithFailClosedEnvInputs(): void
    {
        $src = $this->read('config/settings.php');

        $this->assertMatchesRegularExpression(
            "/'cd'\s*=>\s*\[/",
            $src,
            'oauth_clients 必须注册 cd 客户端，否则 CD 的授权请求直接被 validateClient 拒掉'
        );
        $this->assertStringContainsString("env('CD_OIDC_SECRET', '')", $src);
        $this->assertStringContainsString("env('CD_OIDC_REDIRECT_URI', '')", $src);
        $this->assertMatchesRegularExpression(
            "/['\"]system['\"]\s*=>\s*env\('CD_OIDC_SYSTEM',\s*'cd'\)/",
            $src,
            'cd 客户端必须声明 system（默认 cd），否则 P0-0 会回退成 CI，CD 账号依旧登不进'
        );
    }

    /** P0-0：授权路径按 client 决定 systemType，并保留 CI 回退（既有客户端零回归）。 */
    public function testAuthorizeSubmitUsesClientSystemWithCiFallback(): void
    {
        $src = $this->read('src/Controller/OAuthController.php');

        $this->assertStringContainsString('clientSystem($clientId)', $src);
        $this->assertStringContainsString('?: AppConfig::SYSTEM_CI', $src);
        $this->assertStringNotContainsString(
            'authenticate($username, $password, AppConfig::SYSTEM_CI)',
            $src,
            '写死 CI 的授权路径会让所有 systems=cd 的 CD 账号被拒（auth.no_ci_access）'
        );
    }

    /** P0-2：access token 只以 sha256 摘要落库，明文不得出现在 cache 键里。 */
    public function testAccessTokenIsStoredAsDigestOnly(): void
    {
        $src = $this->read('src/Service/OAuthService.php');

        $this->assertStringContainsString("hash('sha256', \$token)", $src, '签发/校验都必须走摘要');
        $this->assertStringNotContainsString(
            'self::ACCESS_PREFIX . $token',
            $src,
            'access token 明文不得作为 cache_key 落库'
        );
    }
    /**
     * `expires_in` 必须引用 `OAuthService::ACCESS_TOKEN_TTL`：此前它是字面量 3600，与真正决定
     * cache 过期时间的常量一旦漂移，就会对客户端（Grafana 等）谎报 access token 有效期。
     * CD v1 不消费该字段，但 CD 侧评审已把它列为整改项，这里是它的回归护栏。
     */
    public function testTokenEndpointExpiresInReferencesConstant(): void
    {
        $src = $this->read('src/Controller/OAuthController.php');

        $this->assertStringContainsString('OAuthService::ACCESS_TOKEN_TTL', $src);
        $this->assertDoesNotMatchRegularExpression(
            "/'expires_in'\s*=>\s*\d+/",
            $src,
            'expires_in 不得回退为字面量：与 OAuthService::ACCESS_TOKEN_TTL 漂移即谎报有效期'
        );
    }



    /** P0-4：路由已注册，且解析出的 scope 正是 CD 服务 token 持有的那一个。 */
    public function testRolePermissionsRouteExistsAndIsReachableByCdServiceToken(): void
    {
        $routes = $this->read('config/routes.php');
        $this->assertStringContainsString("'/roles/{name}/permissions'", $routes);
        $this->assertStringContainsString("'rolePermissions'", $routes);

        $this->assertSame(
            AppConfig::API_SCOPE_RBAC_USER_WRITE,
            ApiScopeResolver::resolve('GET', '/api/rbac/roles/deployer/permissions'),
            '整段 /api/rbac/* 统一判 rbac.user.write'
        );
        $this->assertContains(
            AppConfig::API_SCOPE_RBAC_USER_WRITE,
            AppConfig::CD_SERVICE_TOKEN_SCOPES,
            'CD 服务 token 必须持有该 scope，否则新端点对 CD 反而 403'
        );
    }

    /**
     * P0-4 的 DI 接线：容器必须能真的构造出 RbacController。
     * （漏注入 TokenService 时 PHP-DI 会在运行时抛错 → 该端点 500，而所有单测依然全绿。）
     */
    public function testContainerResolvesRbacController(): void
    {
        $builder = new ContainerBuilder();
        $builder->addDefinitions(self::ROOT . '/config/container.php');
        // 容器默认的 \PDO 定义走 Database::getPdo()（会碰真实库文件），测试换内存库
        $builder->addDefinitions([\PDO::class => fn() => $this->memoryPdo()]);
        $container = $builder->build();

        $this->assertInstanceOf(RbacController::class, $container->get(RbacController::class));
    }

    /**
     * P0-4 的真实行为：跑真 SQL（种子角色 + role_permissions），覆盖 403 / 200 / 超管通配 / 404
     * 四条分支 —— 尤其「角色不存在」不能返回空数组，否则 CD 会把「查错角色名」当成
     * 「该角色零权限」而把权限收敛清空。
     */
    public function testRolePermissionsEndpointBehaviour(): void
    {
        Database::init(['driver' => 'sqlite', 'auto_migrate' => true]);
        $pdo = $this->memoryPdo();
        Database::bootstrap($pdo);

        $i18n     = new I18nService(self::ROOT . '/lang', 'zh_CN');
        $settings = new Settings([]);
        $tokens   = new TokenService($pdo, $settings);
        $controller = new RbacController(
            $i18n,
            $settings,
            new AdminUserRepository($pdo),
            $tokens
        );

        $requestFactory  = new ServerRequestFactory();
        $responseFactory = new ResponseFactory();

        // 登录态（非 API token）一律 403
        $sessionReq = $requestFactory->createServerRequest('GET', '/api/rbac/roles/viewer/permissions')
            ->withAttribute('currentRole', AppConfig::ROLE_ADMIN);
        $resp = $controller->rolePermissions(
            $sessionReq,
            $responseFactory->createResponse(),
            ['name' => AppConfig::ROLE_VIEWER]
        );
        $this->assertSame(403, $resp->getStatusCode());

        $apiReq = $requestFactory->createServerRequest('GET', '/api/rbac/roles/viewer/permissions')
            ->withAttribute('currentRole', AppConfig::ROLE_API_TOKEN);

        // 真实角色：权限与 TokenService::loadPermissions 同源
        $expected = $tokens->loadPermissions(AppConfig::ROLE_VIEWER);
        $this->assertNotEmpty($expected, 'viewer 应被种子授予权限，否则本用例失去意义');
        $resp = $controller->rolePermissions(
            $apiReq,
            $responseFactory->createResponse(),
            ['name' => AppConfig::ROLE_VIEWER]
        );
        $this->assertSame(200, $resp->getStatusCode());
        $body = (array) json_decode((string) $resp->getBody(), true);
        $this->assertSame(AppConfig::ROLE_VIEWER, $body['role']);
        $this->assertSame(array_values($expected), $body['permissions']);

        // super_admin：通配（与 /api/admin/login 的 permissions:"*" 同语义），不能照表读成 []
        $resp = $controller->rolePermissions(
            $apiReq,
            $responseFactory->createResponse(),
            ['name' => AppConfig::ROLE_SUPER_ADMIN]
        );
        $this->assertSame(['*'], ((array) json_decode((string) $resp->getBody(), true))['permissions']);

        // 角色不存在：404，与「零权限」严格区分
        $resp = $controller->rolePermissions(
            $apiReq,
            $responseFactory->createResponse(),
            ['name' => 'ghost-role']
        );
        $this->assertSame(404, $resp->getStatusCode());
    }
}
