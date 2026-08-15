<?php
declare(strict_types=1);

namespace App\Test\Unit;

use App\Config\AppConfig;
use App\Service\TokenService;
use PHPUnit\Framework\TestCase;

/**
 * TokenService::validate 回归测试（内存 SQLite）
 *
 * 锁定 #8 修复：cache value 以「user|role」存储，用户名若含 '|'，
 * 旧实现取 parts[1] 当角色会把用户名里 '|' 后的片段误当 role → 提权。
 * 新实现 role 取最后一段，用户名其余部分按原样还原。
 */
class TokenServiceTest extends TestCase
{
    private \PDO $pdo;
    private TokenService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $this->pdo->exec('CREATE TABLE ' . AppConfig::TABLE_CACHE . ' (
            cache_key TEXT PRIMARY KEY,
            value TEXT NOT NULL,
            expires_at INTEGER NOT NULL
        )');

        $this->service = new TokenService($this->pdo, new AppConfig([]));
    }

    private function seedToken(string $token, string $value, int $expiresAt = 3600): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . AppConfig::TABLE_CACHE . ' (cache_key, value, expires_at) VALUES (?, ?, ?)'
        );
        $stmt->execute([AppConfig::CACHE_KEY_ADMIN_TOKEN_PREFIX . $token, $value, time() + $expiresAt]);
    }

    public function testNormalUsernameParsesRoleCorrectly(): void
    {
        $this->seedToken('tok_normal', 'alice|admin');
        $result = $this->service->validate('tok_normal');
        $this->assertNotNull($result);
        $this->assertSame('alice', $result['user']);
        $this->assertSame('admin', $result['role']);
    }

    public function testUsernameWithPipeCannotInjectRole(): void
    {
        // 用户名含 '|'：旧实现 parts[1]='super_admin' 会提权；新实现 role 取末尾 'viewer'
        $this->seedToken('tok_pipe', 'admin|super_admin|viewer');
        $result = $this->service->validate('tok_pipe');
        $this->assertNotNull($result);
        $this->assertSame('admin|super_admin', $result['user']);
        $this->assertSame('viewer', $result['role']);
    }

    public function testMalformedCacheReturnsNull(): void
    {
        // 单段（无 '|'）视为格式异常，fail-closed 拒绝
        $this->seedToken('tok_bad', 'onlyuser');
        $this->assertNull($this->service->validate('tok_bad'));
    }

    public function testUnknownTokenReturnsNull(): void
    {
        $this->assertNull($this->service->validate('no_such_token'));
    }

    public function testExpiredTokenReturnsNull(): void
    {
        $this->seedToken('tok_expired', 'alice|admin', -1);
        $this->assertNull($this->service->validate('tok_expired'));
    }
}
