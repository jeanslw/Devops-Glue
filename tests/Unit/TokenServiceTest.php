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
 * 锁定 #8 修复：cache value 以 "user|role" 存储，用户名若含 '|'，
 * 旧实现取 parts[1] 当角色会把用户名里 '|' 后的片段误当 role，造成提权。
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
        
        $stmt->execute([AppConfig::CACHE_KEY_ADMIN_TOKEN_PREFIX . TokenService::hashAdminToken($token), $value, time() + $expiresAt]);
    }

    private function createAdminUsersTable(): void
    {
        $this->pdo->exec('CREATE TABLE ' . AppConfig::TABLE_ADMIN_USERS . ' (
            username TEXT PRIMARY KEY,
            status INTEGER NOT NULL DEFAULT 1,
            role TEXT NOT NULL DEFAULT "admin",
            must_change_password INTEGER NOT NULL DEFAULT 0
        )');
    }

    private function seedUser(string $username, int $status, string $role = 'admin'): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . AppConfig::TABLE_ADMIN_USERS . ' (username, status, role) VALUES (?, ?, ?)'
        );
        $stmt->execute([$username, $status, $role]);
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
        
        $this->seedToken('tok_pipe', 'admin|super_admin|viewer');
        $result = $this->service->validate('tok_pipe');
        $this->assertNotNull($result);
        $this->assertSame('admin|super_admin', $result['user']);
        $this->assertSame('viewer', $result['role']);
    }

    public function testMalformedCacheReturnsNull(): void
    {
        
        $this->seedToken('tok_bad', 'onlyuser');
        $this->assertNull($this->service->validate('tok_bad'));
    }

    public function testUnknownTokenReturnsNull(): void
    {
        $this->assertNull($this->service->validate('no_such_token'));
    }

    public function testPlaintextTokenKeyDoesNotMatch(): void
    {
        
        
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
        
        $this->createAdminUsersTable();
        $this->seedToken('tok_deleted', 'ghost|admin');
        $this->assertNull($this->service->validate('tok_deleted'));
    }

    public function testRoleDowngradeTakesEffectImmediately(): void
    {
        
        
        $this->createAdminUsersTable();
        $this->seedUser('alice', 1, 'viewer');
        $this->seedToken('tok_demoted', 'alice|super_admin');
        $result = $this->service->validate('tok_demoted');
        $this->assertNotNull($result);
        $this->assertSame('alice', $result['user']);
        $this->assertSame('viewer', $result['role']);
    }

    public function testMissingRoleColumnFallsBackToCachedRole(): void
    {
        
        $this->pdo->exec('CREATE TABLE ' . AppConfig::TABLE_ADMIN_USERS . ' (
            username TEXT PRIMARY KEY,
            status INTEGER NOT NULL DEFAULT 1
        )');
        $this->pdo->prepare(
            'INSERT INTO ' . AppConfig::TABLE_ADMIN_USERS . ' (username, status) VALUES (?, ?)'
        )->execute(['alice', 1]);
        $this->seedToken('tok_norole', 'alice|admin');
        $result = $this->service->validate('tok_norole');
        $this->assertNotNull($result);
        $this->assertSame('admin', $result['role']);
    }

    public function testMissingStatusColumnDoesNotLockOut(): void
    {
        
        $this->seedToken('tok_legacy', 'alice|admin');
        $result = $this->service->validate('tok_legacy');
        $this->assertNotNull($result);
        $this->assertSame('alice', $result['user']);
    }

    public function testLegacyTokenDisabledByDefault(): void
    {
        
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

    /**
     * 锁定 #3.1 修复：revokeAllForUser 在 MySQL 默认反斜杠转义下，ESCAPE '\' 会被当成“转义后的引号”
     * 导致 SQL 语法错误、异常被吞、旧会话未吊销。改用 '!' 作转义符后，按值前缀匹配可正确删除目标用户
     * 的全部 token。注：MySQL 8.4 下的语法错误需集成测试复现，此处锁定转义后的匹配语义在 SQLite 正确。
     */
    public function testRevokeAllForUserRemovesOnlyTargetUser(): void
    {
        $this->seedToken('tok_alice_1', 'alice|admin');
        $this->seedToken('tok_alice_2', 'alice|admin');
        $this->seedToken('tok_bob', 'bob|viewer');

        $deleted = $this->service->revokeAllForUser('alice');

        $this->assertSame(2, $deleted);
        $aliceLeft = $this->pdo->query(
            "SELECT COUNT(*) AS c FROM " . AppConfig::TABLE_CACHE . " WHERE value = 'alice|admin'"
        )->fetch()['c'];
        $this->assertSame(0, (int)$aliceLeft, 'alice 的全部 token 必须被吊销');
        $bobLeft = $this->pdo->query(
            "SELECT COUNT(*) AS c FROM " . AppConfig::TABLE_CACHE . " WHERE value = 'bob|viewer'"
        )->fetch()['c'];
        $this->assertSame(1, (int)$bobLeft, '其他用户的 token 不受影响');
    }

    public function testRevokeAllForUserEscapesLikeWildcardsInUsername(): void
    {
        // 用户名含 LIKE 通配符 %/_ 时，转义后仍仅删除目标用户，不被通配误导（回归 MySQL ESCAPE 语法）
        $this->seedToken('tok_special', 'a%b_c|admin');
        $this->seedToken('tok_other', 'axbyc|admin');

        $this->service->revokeAllForUser('a%b_c');

        $special = $this->pdo->query(
            "SELECT COUNT(*) AS c FROM " . AppConfig::TABLE_CACHE . " WHERE value = 'a%b_c|admin'"
        )->fetch()['c'];
        $this->assertSame(0, (int)$special);
        $other = $this->pdo->query(
            "SELECT COUNT(*) AS c FROM " . AppConfig::TABLE_CACHE . " WHERE value = 'axbyc|admin'"
        )->fetch()['c'];
        $this->assertSame(1, (int)$other);
    }
}
