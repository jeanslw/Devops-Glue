<?php

declare(strict_types=1);

namespace App\Test\Unit;

use App\Config\AppConfig;
use App\Controller\OAuthController;
use App\Controller\RbacController;
use App\Exceptions\ApiException;
use App\Service\AdminAuthService;
use App\Service\AdminUserRepository;
use App\Service\Build\BuildStatus;
use App\Service\I18nService;
use App\Service\JenkinsService;
use App\Service\OAuthService;
use App\Service\OidcService;
use App\Service\OperationLogRepository;
use App\Service\Settings;
use App\Service\TokenService;
use App\Support\ApiScopeResolver;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * 安全加固回归测试：OAuth 强制改密 / 令牌吊销、resolve-tag 写 scope、
 * Jenkins 路径穿越、RBAC 读接口隔离。
 */
class SecurityHardeningTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    private function memoryPdo(): \PDO
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $pdo->exec('CREATE TABLE ' . AppConfig::TABLE_CACHE . ' (
            cache_key TEXT PRIMARY KEY,
            value TEXT NOT NULL,
            expires_at INTEGER NOT NULL
        )');
        return $pdo;
    }

    private function makeAdminUsersTable(\PDO $pdo, bool $withMustChangeColumn = true): void
    {
        $cols = 'username TEXT PRIMARY KEY, status INTEGER NOT NULL DEFAULT 1, role TEXT NOT NULL DEFAULT ""';
        if ($withMustChangeColumn) {
            $cols .= ', must_change_password INTEGER NOT NULL DEFAULT 0';
        }
        $pdo->exec('CREATE TABLE ' . AppConfig::TABLE_ADMIN_USERS . " ({$cols})");
    }

    private function i18n(): I18nService
    {
        return new I18nService(self::ROOT . '/lang');
    }

    // ── 1. OAuth 强制改密 ──

    /** must_change_password=1 走 /oauth/authorize：403 且绝不签发授权码，错误留在登录页。 */
    public function testAuthorizeSubmitRejectsMustChangePasswordWithoutCode(): void
    {
        $pdo = $this->memoryPdo();
        $this->makeAdminUsersTable($pdo);

        $oauth = $this->createMock(OAuthService::class);
        $oauth->method('validateClient')->willReturn(true);
        $oauth->method('clientSystem')->willReturn(AppConfig::SYSTEM_CI);
        $oauth->expects($this->never())->method('issueCode');

        $auth = $this->createMock(AdminAuthService::class);
        $auth->method('isLoginLocked')->willReturn(false);
        $auth->method('authenticate')->willReturn([
            'success' => true, 'user' => 'alice', 'role' => 'admin',
            'must_change_password' => true,
        ]);

        $controller = new OAuthController(
            $this->i18n(),
            $this->createMock(OperationLogRepository::class),
            $oauth,
            $auth,
            new AdminUserRepository($pdo),
            new OidcService([]),
            new Settings([])
        );

        $request = (new ServerRequestFactory())->createServerRequest(
            'POST', '/oauth/authorize', ['REMOTE_ADDR' => '127.0.0.1']
        )->withParsedBody([
            'client_id' => 'cd', 'redirect_uri' => 'https://cd.example.com/callback',
            'state' => 's', 'scope' => 'openid', 'nonce' => '',
            'username' => 'alice', 'password' => 'secret',
        ]);
        $response = $controller->authorizeSubmit($request, (new ResponseFactory())->createResponse());

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('', $response->getHeaderLine('Location'), '不得 302 回 redirect_uri');
        $this->assertStringContainsString(
            '修改初始密码',
            (string) $response->getBody(),
            '错误提示应留在登录页上'
        );
    }

    /** 已签发的 access token，在 must_change_password=1 后 userinfo 应判为 invalid_token。 */
    public function testAccessTokenInvalidWhenMustChangePassword(): void
    {
        $pdo = $this->memoryPdo();
        $this->makeAdminUsersTable($pdo);
        $pdo->exec('INSERT INTO ' . AppConfig::TABLE_ADMIN_USERS . " VALUES ('alice', 1, 'admin', 0)");

        $svc = new OAuthService($pdo, []);
        $token = $svc->issueAccessToken('alice', 'admin', 'cd');
        $this->assertNotNull($svc->validateAccessToken($token));

        $pdo->exec('UPDATE ' . AppConfig::TABLE_ADMIN_USERS . " SET must_change_password = 1 WHERE username = 'alice'");
        $this->assertNull($svc->validateAccessToken($token), '强制改密后旧令牌必须立即失效');
    }

    /** 旧库缺 must_change_password 列（迁移窗口）不得锁死 SSO。 */
    public function testSchemaCompatKeepsSsoAliveWhenColumnMissing(): void
    {
        $pdo = $this->memoryPdo();
        $this->makeAdminUsersTable($pdo, false);
        $pdo->exec('INSERT INTO ' . AppConfig::TABLE_ADMIN_USERS . " VALUES ('alice', 1, 'admin')");

        $svc = new OAuthService($pdo, []);
        $token = $svc->issueAccessToken('alice', 'admin', 'cd');
        $this->assertNotNull($svc->validateAccessToken($token));
    }

    /** 按用户名精确吊销：alice2 / malice 等同名前缀账号的凭据不得被误删。 */
    public function testRevokeUserTokensMatchesExactUsernameOnly(): void
    {
        $pdo = $this->memoryPdo();
        $rows = [
            'oauth_at_hash1'   => ['user' => 'alice', 'role' => 'admin'],
            'oauth_code_hash1' => ['user' => 'alice', 'role' => 'admin'],
            'oauth_at_hash2'   => ['user' => 'alice2', 'role' => 'admin'],
            'oauth_at_hash3'   => ['user' => 'malice', 'role' => 'admin'],
            'admin_token_x'    => ['user' => 'alice'],
        ];
        foreach ($rows as $key => $value) {
            $stmt = $pdo->prepare('INSERT INTO ' . AppConfig::TABLE_CACHE . ' VALUES (?, ?, ?)');
            $stmt->execute([$key, json_encode($value), time() + 3600]);
        }

        $this->assertSame(2, OAuthService::revokeUserTokens($pdo, 'alice'));
        $remaining = $pdo->query('SELECT cache_key FROM ' . AppConfig::TABLE_CACHE . ' ORDER BY cache_key')
            ->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(['admin_token_x', 'oauth_at_hash2', 'oauth_at_hash3'], $remaining);
    }

    /** 改密统一收口：revokeAllForUser 同时吊销后台会话与 OAuth 凭据。 */
    public function testRevokeAllForUserAlsoRevokesOAuthTokens(): void
    {
        $pdo = $this->memoryPdo();
        $insert = $pdo->prepare('INSERT INTO ' . AppConfig::TABLE_CACHE . ' VALUES (?, ?, ?)');
        $insert->execute(['admin_token_x', 'alice|admin', time() + 3600]);
        $insert->execute(['oauth_at_hash1', json_encode(['user' => 'alice', 'role' => 'admin']), time() + 3600]);
        $insert->execute(['oauth_code_hash1', json_encode(['user' => 'alice', 'role' => 'admin']), time() + 60]);
        $insert->execute(['oauth_at_hash2', json_encode(['user' => 'bob', 'role' => 'admin']), time() + 3600]);

        $tokens = new TokenService($pdo, new Settings([]));
        $this->assertSame(3, $tokens->revokeAllForUser('alice'));
        $remaining = $pdo->query('SELECT cache_key FROM ' . AppConfig::TABLE_CACHE)->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(['oauth_at_hash2'], $remaining, '只保留其他用户的凭据');
    }

    // ── 2. resolve-tag 写 scope ──

    /** resolve-tag 会写库，只持 build.read 的 token 不得调用。 */
    public function testResolveTagRequiresBuildWriteScope(): void
    {
        $required = ApiScopeResolver::resolve('POST', '/api/build/grp/proj/pipelines/123/resolve-tag');
        $this->assertSame(AppConfig::API_SCOPE_BUILD_WRITE, $required);
        $this->assertNotSame(AppConfig::API_SCOPE_BUILD_READ, $required);
        $this->assertNotContains(
            $required,
            [AppConfig::API_SCOPE_BUILD_READ],
            'AuthMiddleware 会据此对只持 build.read 的 token 返回 403'
        );
        $this->assertContains('resolve-tag', AppConfig::API_SCOPE_CAPABILITIES[AppConfig::API_SCOPE_BUILD_WRITE]);
    }

    /** 未知状态归一为 unknown 而非空串：回填条件 status = '' 会被当成成功。 */
    public function testUnknownBuildStatusIsNotTreatedAsSuccess(): void
    {
        $this->assertSame('unknown', BuildStatus::normalize(''));
        $this->assertSame('unknown', BuildStatus::normalize('unknown'));
        $this->assertNotSame('', BuildStatus::normalize(''), '空串会被 PipelineTagService 回填条件放行');
        $this->assertSame('failed', BuildStatus::normalize('failure'));
    }

    // ── 3. Jenkins 路径 ──

    /** '..' 及编码变体必须在拼 URL 前被拒绝，且映射为 400 而非 500/502。 */
    public function testJenkinsJobUrlRejectsTraversal(): void
    {
        $svc = new JenkinsService(['url' => 'http://jenkins.example.com', 'user' => 'u', 'token' => 't']);
        foreach (['../credentials/store', 'a/../b', 'a/%2e%2e/b', 'a/%252e%252e/b', 'a\\..\\b', ''] as $path) {
            try {
                $svc->getJobUrl($path);
                $this->fail("路径应被拒绝: {$path}");
            } catch (ApiException $e) {
                $this->assertSame(400, $e->getStatusCode(), "路径 {$path} 应映射为 400");
            }
        }
    }

    /** 合法路径按段编码，且容忍历史 job_name 的尾斜杠/双斜杠。 */
    public function testJenkinsJobUrlEncodesSegments(): void
    {
        $svc = new JenkinsService(['url' => 'http://jenkins.example.com/', 'user' => 'u', 'token' => 't']);
        $this->assertSame(
            'http://jenkins.example.com/job/folder/job/my%20job',
            $svc->getJobUrl('folder/my job')
        );
        $this->assertSame(
            'http://jenkins.example.com/job/folder/job/sub',
            $svc->getJobUrl('folder//sub/')
        );
    }

    // ── 4. RBAC 读接口只返回 CD 账号 ──

    /** userList 过滤 CI-only / root / 超管；userGet 对非 CD 账号返回 404（不使用 403）。 */
    public function testRbacReadEndpointsExposeOnlyCdAccounts(): void
    {
        $pdo = $this->memoryPdo();
        $pdo->exec('CREATE TABLE ' . AppConfig::TABLE_ADMIN_USERS . ' (
            username TEXT PRIMARY KEY, role TEXT NOT NULL DEFAULT "", systems TEXT NOT NULL DEFAULT "",
            status INTEGER NOT NULL DEFAULT 1
        )');
        $insert = $pdo->prepare(
            'INSERT INTO ' . AppConfig::TABLE_ADMIN_USERS . ' (username, role, systems) VALUES (?, ?, ?)'
        );
        $insert->execute(['alice', 'admin', 'ci,cd']);
        $insert->execute(['bob', 'admin', 'ci']);
        $insert->execute(['root', 'super_admin', 'ci']);

        $controller = new RbacController(
            $this->i18n(),
            new Settings([]),
            new AdminUserRepository($pdo),
            new TokenService($pdo, new Settings([]))
        );
        $requestFactory = new ServerRequestFactory();
        $responseFactory = new ResponseFactory();
        $apiTokenRequest = static fn(string $path) => $requestFactory
            ->createServerRequest('GET', $path)
            ->withAttribute('currentRole', AppConfig::ROLE_API_TOKEN);

        $listResp = $controller->userList($apiTokenRequest('/api/rbac/users'), $responseFactory->createResponse());
        $this->assertSame(200, $listResp->getStatusCode());
        $users = json_decode((string) $listResp->getBody(), true)['users'] ?? [];
        $this->assertSame(['alice'], array_column($users, 'username'), '只应暴露 CD 账号');

        $getResp = $controller->userGet(
            $apiTokenRequest('/api/rbac/users/bob'),
            $responseFactory->createResponse(),
            ['username' => 'bob']
        );
        $this->assertSame(404, $getResp->getStatusCode(), '非 CD 账号用 404，避免 403 泄露存在性');
    }
}
