<?php
declare(strict_types=1);

namespace App\Test\Unit;

use App\Config\AppConfig;
use App\Service\Settings;
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

        $this->service = new TokenService($this->pdo, new Settings([]));
    }

    private function seedToken(string $token, string $value, int $expiresAt = 3600): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . AppConfig::TABLE_CACHE . ' (cache_key, value, expires_at) VALUES (?, ?, ?)'
        );
        // 入库 key 与生产一致：admin_token_ + sha256(token)，明文 token 绝不出现在 cache 表
        $stmt->execute([AppConfig::CACHE_KEY_ADMIN_TOKEN_PREFIX . TokenService::hashAdminToken($token), $value, time() + $expiresAt]);
    }

    private function createAdminUsersTable(): void
    {
        $this->pdo->exec('CREATE TABLE ' . AppConfig::TABLE_ADMIN_USERS . ' (
            username TEXT PRIMARY KEY,
            status INTEGER NOT NULL DEFAULT 1
        )');
    }

    private function seedUser(string $username, int $status): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . AppConfig::TABLE_ADMIN_USERS . ' (username, status) VALUES (?, ?)'
        );
        $stmt->execute([$username, $status]);
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

    public function testPlaintextTokenKeyDoesNotMatch(): void
    {
        // 锁死哈希化改造：若 cache 表里还留着旧实现的明文 token 当 key（或库被泄露），
        // 校验端已改为按 sha256 查找，明文 key 命中不了 → 会话作废（fail-closed）。
        $this->pdo->prepare(
            'INSERT INTO ' . AppConfig::TABLE_CACHE . ' (cache_key, value, expires_at) VALUES (?, ?, ?)'
        )->execute([AppConfig::CACHE_KEY_ADMIN_TOKEN_PREFIX . 'tok_plain', 'alice|admin', time() + 3600]);
        $this->assertNull($this->service->validate('tok_plain'));
    }

    public function testExpiredTokenReturnsNull(): void
    {
        $this->seedToken('tok_expired', 'alice|admin', -1);
        $this->assertNull($this->service->validate('tok_expired'));
    }

    public function testDisabledUserTokenIsInvalidated(): void
    {
        // 停用账号（status=0）：token 命中缓存但账号已停用 → 立即失效（踢下线）
        $this->createAdminUsersTable();
        $this->seedUser('alice', 0);
        $this->seedToken('tok_disabled', 'alice|admin');
        $this->assertNull($this->service->validate('tok_disabled'));
    }

    public function testEnabledUserTokenRemainsValid(): void
    {
        $this->createAdminUsersTable();
        $this->seedUser('alice', 1);
        $this->seedToken('tok_enabled', 'alice|admin');
        $result = $this->service->validate('tok_enabled');
        $this->assertNotNull($result);
        $this->assertSame('alice', $result['user']);
        $this->assertSame('admin', $result['role']);
    }

    public function testDeletedUserTokenIsInvalidated(): void
    {
        // 账号已删除：admin_users 无此行 → token 同样失效
        $this->createAdminUsersTable();
        $this->seedToken('tok_deleted', 'ghost|admin');
        $this->assertNull($this->service->validate('tok_deleted'));
    }

    public function testMissingStatusColumnDoesNotLockOut(): void
    {
        // 旧库无 status 列（或 DB 不可达）：状态检查抛异常 → 放行（fail-open 保可用）
        $this->seedToken('tok_legacy', 'alice|admin');
        $result = $this->service->validate('tok_legacy');
        $this->assertNotNull($result);
        $this->assertSame('alice', $result['user']);
    }

    public function testLegacyTokenDisabledByDefault(): void
    {
        // 安全开关默认关：即使给出「正确的」base64(user:password)，也不放行（fail-closed）
        $this->assertFalse($this->service->validateLegacy(base64_encode('admin:secret')));
    }

    public function testLegacyTokenEnabledWhenConfigured(): void
    {
        $legacy = new TokenService($this->pdo, new Settings([
            'admin' => [
                'user'         => 'admin',
                'password'     => 'secret',
                'legacy_token' => true,
            ],
        ]));
        $this->assertTrue($legacy->validateLegacy(base64_encode('admin:secret')));
        $this->assertFalse($legacy->validateLegacy(base64_encode('admin:wrong')));
    }
}
