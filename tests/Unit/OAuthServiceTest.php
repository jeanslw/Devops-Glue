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
 * 避免「忘了设 GRAFANA_OAUTH_SECRET、用空默认」时空 secret 与空输入 hash_equals 相等，
 * token 端点被空 secret 绕过。
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
            'grafana' => ['secret' => '', 'redirect_uri' => 'http://g:3000/login'],
        ]);

        // 空 secret 客户端被剔除：authorize 与 token 两端都拒绝
        $this->assertFalse($svc->validateClient('grafana', 'http://g:3000/login'));
        $this->assertFalse($svc->validateClientSecret('grafana', ''));
    }

    public function testWhitespaceOnlySecretIsDropped(): void
    {
        $svc = $this->makeService([
            'grafana' => ['secret' => "   \n", 'redirect_uri' => 'http://g:3000/login'],
        ]);

        $this->assertFalse($svc->validateClient('grafana', 'http://g:3000/login'));
    }

    public function testValidSecretClientAuthenticates(): void
    {
        $svc = $this->makeService([
            'grafana' => ['secret' => 's3cret', 'redirect_uri' => 'http://g:3000/login'],
        ]);

        $this->assertTrue($svc->validateClient('grafana', 'http://g:3000/login'));
        $this->assertFalse($svc->validateClient('grafana', 'http://evil/redirect')); // redirect 不匹配
        $this->assertTrue($svc->validateClientSecret('grafana', 's3cret'));
        $this->assertFalse($svc->validateClientSecret('grafana', 'wrong'));
    }

    public function testUnknownClientRejected(): void
    {
        $svc = $this->makeService([]);

        $this->assertFalse($svc->validateClient('ghost', 'http://g:3000/login'));
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
            'grafana' => ['secret' => 's3cret', 'redirect_uri' => 'http://g:3000/login'],
        ]);

        $code = $svc->issueCode('grafana', 'http://g:3000/login', 'alice', 'ci_admin', 'openid profile email', 'nonce-123');
        $data = $svc->consumeCode($code, 'grafana', 'http://g:3000/login');

        $this->assertNotNull($data);
        $this->assertSame('openid profile email', $data['scope']);
        $this->assertSame('nonce-123', $data['nonce']);
        $this->assertSame('alice', $data['user']);
        $this->assertSame('ci_admin', $data['role']);
    }

    public function testIssueCodeDefaultsEmptyScopeAndNullNonce(): void
    {
        $svc = $this->makeServiceWithCache([
            'grafana' => ['secret' => 's3cret', 'redirect_uri' => 'http://g:3000/login'],
        ]);

        $code = $svc->issueCode('grafana', 'http://g:3000/login', 'alice', 'ci_admin');
        $data = $svc->consumeCode($code, 'grafana', 'http://g:3000/login');

        $this->assertNotNull($data);
        $this->assertSame('', $data['scope']);
        $this->assertNull($data['nonce']);
    }
}
