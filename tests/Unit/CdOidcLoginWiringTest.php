<?php

declare(strict_types=1);

namespace App\Test\Unit;

use App\Config\AppConfig;
use App\Controller\RbacController;
use App\Service\AdminUserRepository;
use App\Service\AppSettingRepository;
use App\Service\Database;
use App\Service\I18nService;
use App\Service\OAuthClientRepository;
use App\Service\OAuthService;
use App\Service\OidcService;
use App\Service\Settings;
use App\Service\TokenService;
use App\Support\ApiScopeResolver;
use DI\ContainerBuilder;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * CD 经 Glue OIDC 登录的四项 P0 接线。
 *
 *   1. P0-1：cd 客户端由 DB（ci_oauth_clients）提供（彻底移除 env 兜底），
 *      secret / redirect_uri 空值会被 `OAuthService::__construct` 的检查剔除（fail-closed，
 *      否则空 secret 与空输入在 `hash_equals` 下相等，等于公开默认密钥）。
 *   2. P0-0：授权时按 client 声明的归属系统校验账号：写死 CI 会让所有 CD 账号（systems='cd'）
 *      在登录页被拒（auth.no_ci_access）；同时必须保留 `?: SYSTEM_CI` 回退，否则既有
 *      Jenkins / Harbor 等未声明 system 的客户端会全部登录失败。
 *   3. P0-2：access token 落库前先做 sha256：cache 表存明文意味着库表或备份泄露时在途令牌一并泄露。
 *   4. P0-4：`GET /api/rbac/roles/{name}/permissions` 上线且 CD 自己的服务 token 能调通
 *      （scope 复用 rbac.user.write；若以后给它单开 scope，CD 会在权限映射上一路 403）。
 *
 * 需要单独测试的原因：以上四点都不会让业务单测失败，只会在浏览器或生产上表现为
 * CD 登不进 Glue、既有 SSO 全部失败、令牌随备份泄露、CD 把角色权限读成空。
 */
class CdOidcLoginWiringTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    protected function setUp(): void
    {
        parent::setUp();
        $_ENV['ADMIN_USER']     = 'admin';
        $_ENV['ADMIN_PASSWORD'] = 'secret';
        
        $_ENV['LOG_PATH'] = sys_get_temp_dir() . DIRECTORY_SEPARATOR;
        $_ENV['DATA_ENC_KEY'] = base64_encode(random_bytes(32));
        Database::reset();
    }

    protected function tearDown(): void
    {
        unset($_ENV['ADMIN_USER'], $_ENV['ADMIN_PASSWORD'], $_ENV['LOG_PATH'], $_ENV['OIDC_ISSUER'], $_ENV['OIDC_ID_TOKEN_TTL'], $_ENV['DATA_ENC_KEY']);
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
        
        
        $pdo->exec('CREATE TABLE IF NOT EXISTS ' . AppConfig::TABLE_PLATFORM_CONNECTIONS . ' (
            platform    TEXT PRIMARY KEY,
            url         TEXT,
            token       TEXT,
            username    TEXT,
            password    TEXT,
            description TEXT DEFAULT \'\',
            created_at  TEXT DEFAULT (datetime(\'now\',\'localtime\')),
            updated_at  TEXT DEFAULT (datetime(\'now\',\'localtime\'))
        )');
        return $pdo;
    }

    /** P0-1：cd 客户端不再从 env 读（迁入 DB），fail-closed 仍由 OAuthService 构造兜底。 */
    public function testCdClientNoLongerReadsEnvAndStaysFailClosed(): void
    {
        $settings = $this->read('config/settings.php');

        
        $this->assertStringNotContainsString("env('CD_OIDC_SECRET'", $settings);
        $this->assertStringNotContainsString("env('CD_OIDC_REDIRECT_URI'", $settings);
        $this->assertStringNotContainsString("env('CD_OIDC_SYSTEM'", $settings);
        $this->assertStringNotContainsString("env('JENKINS_OIDC_SECRET'", $settings);

        
        $oauth = $this->read('src/Service/OAuthService.php');
        $this->assertStringContainsString("trim((string)(\$c['secret'] ?? '')) !== ''", $oauth);
        $this->assertStringContainsString("trim((string)(\$c['redirect_uri'] ?? '')) !== ''", $oauth);
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
     * cache 过期时间的常量一旦不一致，返回给 OAuth2 客户端的 access token 有效期就是错的。
     * CD v1 不消费该字段，但 CD 侧已把它列为整改项，这里用于回归验证。
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
     * P0-4 的 DI 接线：容器必须能构造出 RbacController。
     * （漏注入 TokenService 时 PHP-DI 会在运行时抛错，该端点返回 500，而现有单测仍会全部通过。）
     */
    public function testContainerResolvesRbacController(): void
    {
        $builder = new ContainerBuilder();
        $builder->addDefinitions(self::ROOT . '/config/container.php');
        
        $builder->addDefinitions([\PDO::class => fn() => $this->memoryPdo()]);
        $container = $builder->build();

        $this->assertInstanceOf(RbacController::class, $container->get(RbacController::class));
    }

    /**
     * P0-4 的真实行为：跑真 SQL（种子角色 + role_permissions），覆盖 403 / 200 / 超管通配 / 404
     * 四条分支；其中角色不存在时不能返回空数组，否则 CD 会把查错角色名当成该角色零权限而清空权限。
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

        
        $resp = $controller->rolePermissions(
            $apiReq,
            $responseFactory->createResponse(),
            ['name' => AppConfig::ROLE_SUPER_ADMIN]
        );
        $this->assertSame(['*'], ((array) json_decode((string) $resp->getBody(), true))['permissions']);

        
        $resp = $controller->rolePermissions(
            $apiReq,
            $responseFactory->createResponse(),
            ['name' => 'ghost-role']
        );
        $this->assertSame(404, $resp->getStatusCode());
    }

    /**
     * DB 唯一权威（无 env 兜底）：ci_oauth_clients 提供 cd 客户端，token 端点按 DB secret 校验；
     * OidcService 闭包从 ci_app_settings 读 issuer。env 已彻底移除，不参与合并。
     *
     * 单独测试的原因：容器/仓储层若不覆盖，只会在生产上表现为「后台改了 DB 配置却不生效」，
     * 业务单测全部照过。
     */
    public function testDbOidcConfigIsSoleAuthority(): void
    {
        Database::init(['driver' => 'sqlite', 'auto_migrate' => true]);
        $pdo = $this->memoryPdo();
        Database::bootstrap($pdo);

        
        $repo = new OAuthClientRepository($pdo);
        $repo->save('cd', [
            'secret' => 'db-secret', 'redirect_uri' => 'https://db/cb', 'system' => 'cd', 'enabled' => true, 'description' => '',
        ]);
        $oauth = new OAuthService($pdo, $repo->resolveClients([]));
        $this->assertTrue($oauth->validateClientSecret('cd', 'db-secret'), 'DB secret 必须生效（唯一权威）');

        
        $appSettings = new AppSettingRepository($pdo);
        $appSettings->setOidcIssuer('https://db-issuer');
        $_ENV['OIDC_ISSUER'] = 'https://env-issuer';

        $builder = new ContainerBuilder();
        $builder->addDefinitions(self::ROOT . '/config/container.php');
        $builder->addDefinitions([\PDO::class => fn() => $pdo]);
        $container = $builder->build();

        $oidc = $container->get(OidcService::class);
        $this->assertSame('https://db-issuer', $oidc->issuer(), 'issuer 必须从 DB 读，不读 env');
    }
}
