<?php
declare(strict_types=1);

namespace App\Test\Unit;

use App\Service\OAuthService;
use App\Config\AppConfig;
use PHPUnit\Framework\TestCase;

/**
 * OAuthService 客户端白名单 fail-closed 回归测试
 *
 * 锁定 secret 安全修复：secret 为空/纯空白的客户端视为未配置，必须被剔除，
 * 否则空 secret 与空输入 hash_equals 相等，token 端点可被空 secret 绕过。
 */
class OAuthServiceTest extends TestCase
{
    private function makeService(array $clients): OAuthService
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        return new OAuthService($pdo, $clients);
    }

    public function testEmptySecretClientIsDropped(): void
    {
        $svc = $this->makeService([
            'gitlab' => ['secret' => '', 'redirect_uri' => 'https://gitlab.example.com/users/auth/openid_connect/callback'],
        ]);

        // 空 secret 客户端被剔除：authorize 与 token 两端都拒绝
        $this->assertFalse($svc->validateClient('gitlab', 'https://gitlab.example.com/users/auth/openid_connect/callback'));
        $this->assertFalse($svc->validateClientSecret('gitlab', ''));
    }

    public function testWhitespaceOnlySecretIsDropped(): void
    {
        $svc = $this->makeService([
            'gitlab' => ['secret' => "   \n", 'redirect_uri' => 'https://gitlab.example.com/users/auth/openid_connect/callback'],
        ]);

        $this->assertFalse($svc->validateClient('gitlab', 'https://gitlab.example.com/users/auth/openid_connect/callback'));
    }

    public function testValidSecretClientAuthenticates(): void
    {
        $svc = $this->makeService([
            'gitlab' => ['secret' => 's3cret', 'redirect_uri' => 'https://gitlab.example.com/users/auth/openid_connect/callback'],
        ]);

        $this->assertTrue($svc->validateClient('gitlab', 'https://gitlab.example.com/users/auth/openid_connect/callback'));
        $this->assertFalse($svc->validateClient('gitlab', 'http://evil/redirect')); // redirect 不匹配
        $this->assertTrue($svc->validateClientSecret('gitlab', 's3cret'));
        $this->assertFalse($svc->validateClientSecret('gitlab', 'wrong'));
    }

    public function testEmptyRedirectUriClientIsDropped(): void
    {
        $svc = $this->makeService([
            'jenkins' => ['secret' => 's3cret', 'redirect_uri' => ''],
        ]);

        $this->assertFalse($svc->validateClient('jenkins', ''));
        $this->assertFalse($svc->validateClient('jenkins', 'http://localhost/securityRealm/finishLogin'));
        $this->assertFalse($svc->validateClientSecret('jenkins', 's3cret'));
    }

    public function testUnknownClientRejected(): void
    {
        $svc = $this->makeService([]);

        $this->assertFalse($svc->validateClient('ghost', 'https://gitlab.example.com/users/auth/openid_connect/callback'));
        $this->assertFalse($svc->validateClientSecret('ghost', 'anything'));
    }

    /** 建带 cache 表的 PDO（issueCode/consumeCode 需要），返回 OAuthService */
    private function makeServiceWithCache(array $clients): OAuthService
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE ' . AppConfig::TABLE_CACHE . ' (
            cache_key TEXT PRIMARY KEY,
            value TEXT NOT NULL,
            expires_at INTEGER NOT NULL
        )');
        return new OAuthService($pdo, $clients);
    }

    public function testIssueCodeCarriesScopeAndNonce(): void
    {
        $svc = $this->makeServiceWithCache([
            'gitlab' => ['secret' => 's3cret', 'redirect_uri' => 'https://gitlab.example.com/users/auth/openid_connect/callback'],
        ]);

        $code = $svc->issueCode('gitlab', 'https://gitlab.example.com/users/auth/openid_connect/callback', 'alice', 'ci_admin', 'openid profile email', 'nonce-123');
        $data = $svc->consumeCode($code, 'gitlab', 'https://gitlab.example.com/users/auth/openid_connect/callback');

        $this->assertNotNull($data);
        $this->assertSame('openid profile email', $data['scope']);
        $this->assertSame('nonce-123', $data['nonce']);
        $this->assertSame('alice', $data['user']);
        $this->assertSame('ci_admin', $data['role']);
    }

    public function testIssueCodeDefaultsEmptyScopeAndNullNonce(): void
    {
        $svc = $this->makeServiceWithCache([
            'gitlab' => ['secret' => 's3cret', 'redirect_uri' => 'https://gitlab.example.com/users/auth/openid_connect/callback'],
        ]);

        $code = $svc->issueCode('gitlab', 'https://gitlab.example.com/users/auth/openid_connect/callback', 'alice', 'ci_admin');
        $data = $svc->consumeCode($code, 'gitlab', 'https://gitlab.example.com/users/auth/openid_connect/callback');

        $this->assertNotNull($data);
        $this->assertSame('', $data['scope']);
        $this->assertNull($data['nonce']);
    }

    /**
     * clientSystem：声明了 system 的客户端返回该值（CD = 'cd'），未声明 / 空白 / 未注册返回
     * null（由 OAuthController 回退 CI）。回退语义是既有客户端零回归的前提。
     */
    public function testClientSystemReturnsDeclaredSystemAndNullOtherwise(): void
    {
        $svc = $this->makeService([
            'cd'         => ['secret' => 's3cret', 'redirect_uri' => 'https://cd.example.com/callback', 'system' => 'cd'],
            'gitlab'    => ['secret' => 's3cret', 'redirect_uri' => 'https://gitlab.example.com/users/auth/openid_connect/callback'],
            'jenkins'    => ['secret' => 's3cret', 'redirect_uri' => 'http://j/finishLogin', 'system' => '   '],
            'harbor'     => ['secret' => 's3cret', 'redirect_uri' => 'http://h/callback', 'system' => 123],
        ]);

        $this->assertSame('cd', $svc->clientSystem('cd'));
        $this->assertNull($svc->clientSystem('gitlab'), '未声明 system → null → 调用方回退 CI');
        $this->assertNull($svc->clientSystem('jenkins'), '纯空白等同于未声明');
        $this->assertNull($svc->clientSystem('harbor'), '非字符串值不可信，回退 CI');
        $this->assertNull($svc->clientSystem('ghost'), '未注册客户端没有归属系统');
    }

    /**
     * access token 只以 sha256 摘要落库（与 TokenService::hashAdminToken 同口径）：
     * 明文不得出现在 cache 表，但明文仍能正常校验通过。
     */
    public function testAccessTokenIsStoredHashedAndStillValidates(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE ' . AppConfig::TABLE_CACHE . ' (
            cache_key TEXT PRIMARY KEY,
            value TEXT NOT NULL,
            expires_at INTEGER NOT NULL
        )');
        // validateAccessToken 会回查账号状态与角色：给出在用账号以走通正常路径
        $pdo->exec('CREATE TABLE ' . AppConfig::TABLE_ADMIN_USERS . ' (
            username TEXT PRIMARY KEY,
            status INTEGER NOT NULL DEFAULT 1,
            role TEXT NOT NULL DEFAULT ""
        )');
        $pdo->exec('INSERT INTO ' . AppConfig::TABLE_ADMIN_USERS . " VALUES ('alice', 1, 'ci_admin')");

        $svc   = new OAuthService($pdo, []);
        $token = $svc->issueAccessToken('alice', 'ci_admin', 'cd');

        $key = (string) $pdo->query('SELECT cache_key FROM ' . AppConfig::TABLE_CACHE)->fetchColumn();
        $this->assertStringContainsString(hash('sha256', $token), $key, 'cache_key 必须含摘要');
        $this->assertStringNotContainsString($token, $key, 'cache_key 不得含明文 token');

        $data = $svc->validateAccessToken($token);
        $this->assertNotNull($data, '摘要存储后明文仍应校验通过');
        $this->assertSame('alice', $data['user']);
        $this->assertSame('ci_admin', $data['role']);

        $this->assertNull($svc->validateAccessToken($token . '0'), '篡改后的令牌必须失效');
    }
}
