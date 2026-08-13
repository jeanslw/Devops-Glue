<?php
declare(strict_types=1);

namespace App\Test\Unit;

use App\Config\AppConfig;
use App\Service\ApiTokenService;
use App\Service\Database;
use PHPUnit\Framework\TestCase;

/**
 * ApiTokenService 单元测试（内存 SQLite）
 *
 * 验证：创建只存 sha256、scope 规范化、列表不含明文、撤销/删除/过期校验。
 */
class ApiTokenServiceTest extends TestCase
{
    private \PDO $pdo;
    private ApiTokenService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Database::init(['driver' => 'sqlite']);

        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $this->pdo->exec('CREATE TABLE ' . AppConfig::TABLE_API_TOKENS . ' (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            token_hash TEXT NOT NULL UNIQUE,
            scopes TEXT,
            enabled INTEGER NOT NULL DEFAULT 1,
            expires_at INTEGER,
            created_by TEXT,
            note TEXT,
            created_at TEXT DEFAULT (datetime(\'now\',\'localtime\'))
        )');

        $this->service = new ApiTokenService($this->pdo, new AppConfig([]));
    }

    protected function tearDown(): void
    {
        Database::reset();
        parent::tearDown();
    }

    public function testCreateReturnsPlainTokenOnce(): void
    {
        $created = $this->service->createToken(['name' => 'ci-bot', 'scopes' => ['main', 'build.write']]);
        $this->assertArrayHasKey('id', $created);
        $this->assertArrayHasKey('token', $created);
        $this->assertMatchesRegularExpression('/^dg_[0-9a-f]{64}$/', $created['token'], '明文 token 应为 dg_ 前缀 + 64 位十六进制');
    }

    public function testTokenStoredHashedNotPlaintext(): void
    {
        $created = $this->service->createToken(['name' => 'ci-bot', 'scopes' => ['main']]);
        $plain = $created['token'];

        $row = $this->pdo->query('SELECT token_hash FROM ' . AppConfig::TABLE_API_TOKENS)->fetch();
        $this->assertNotSame($plain, $row['token_hash'], '库中不应存明文');
        $this->assertSame(hash('sha256', $plain), $row['token_hash'], '库中应存 sha256 摘要');
    }

    public function testNormalizeScopesDropsInvalidKeys(): void
    {
        $created = $this->service->createToken(['name' => 'ci-bot', 'scopes' => ['main', 'build.write', 'not.a.scope', '']]);
        $resolved = $this->service->resolve(hash('sha256', $created['token']));
        $this->assertSame(['main', 'build.write'], $resolved['scopes'], '非法/空 scope 应被丢弃并保持顺序');
    }

    public function testCreateRejectsEmptyName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->createToken(['name' => '', 'scopes' => ['main']]);
    }

    public function testListTokensExcludesPlaintext(): void
    {
        $this->service->createToken(['name' => 'a', 'scopes' => ['main']]);
        $this->service->createToken(['name' => 'b', 'scopes' => ['git']]);

        $list = $this->service->listTokens();
        $this->assertCount(2, $list);
        foreach ($list as $item) {
            $this->assertArrayNotHasKey('token', $item, '列表项不得含明文 token');
            $this->assertArrayNotHasKey('token_hash', $item, '列表项不得含 token 摘要');
            $this->assertIsArray($item['scopes']);
        }
    }

    public function testResolveReturnsNullAfterRevoke(): void
    {
        $created = $this->service->createToken(['name' => 'ci-bot', 'scopes' => ['main']]);
        $hash = hash('sha256', $created['token']);

        $this->assertNotNull($this->service->resolve($hash));
        $this->assertTrue($this->service->revoke($created['id']));
        $this->assertNull($this->service->resolve($hash), '撤销后 resolve 应返回 null');
    }

    public function testResolveReturnsNullWhenExpired(): void
    {
        $created = $this->service->createToken([
            'name'       => 'ci-bot',
            'scopes'     => ['main'],
            'expires_at' => time() - 1, // 已过期
        ]);
        $this->assertNull($this->service->resolve(hash('sha256', $created['token'])), '过期 token 应视为无效');
    }

    public function testResolveReturnsNullWhenNotExpired(): void
    {
        $created = $this->service->createToken([
            'name'       => 'ci-bot',
            'scopes'     => ['main'],
            'expires_at' => time() + 3600,
        ]);
        $resolved = $this->service->resolve(hash('sha256', $created['token']));
        $this->assertNotNull($resolved);
        $this->assertSame('ci-bot', $resolved['name']);
    }

    public function testDeleteRemovesRecord(): void
    {
        $created = $this->service->createToken(['name' => 'ci-bot', 'scopes' => ['main']]);
        $this->assertTrue($this->service->delete($created['id']));
        $this->assertNull($this->service->resolve(hash('sha256', $created['token'])));
        $this->assertCount(0, $this->service->listTokens());
    }

    public function testResolveUnknownHashReturnsNull(): void
    {
        $this->assertNull($this->service->resolve(hash('sha256', 'nope')));
    }
}
