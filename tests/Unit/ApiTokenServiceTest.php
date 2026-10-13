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

        $this->service = new ApiTokenService($this->pdo);
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

    // ── 编辑 scope（只改 scopes 列的元数据，不重新签发）──

    public function testUpdateScopesReplacesScopeSetImmediately(): void
    {
        $created = $this->service->createToken(['name' => 'ci-bot', 'scopes' => ['main']]);

        $this->assertTrue($this->service->updateScopes($created['id'], ['git', 'build.write']));

        $list = $this->service->listTokens();
        $this->assertSame(['git', 'build.write'], $list[0]['scopes'], '列表应显示编辑后的 scope');
        $this->assertSame(['git', 'trigger', 'retry', 'cancel'], $list[0]['capabilities'], '能力清单应随 scope 重算（去重且保持目录顺序）');
    }

    public function testUpdateScopesKeepsTokenDigestAndPlaintextUsable(): void
    {
        $created = $this->service->createToken(['name' => 'ci-bot', 'scopes' => ['main']]);
        $hashBefore = $this->pdo->query('SELECT token_hash FROM ' . AppConfig::TABLE_API_TOKENS)->fetch()['token_hash'];

        $this->service->updateScopes($created['id'], ['build.read']);

        $hashAfter = $this->pdo->query('SELECT token_hash FROM ' . AppConfig::TABLE_API_TOKENS)->fetch()['token_hash'];
        $this->assertSame($hashBefore, $hashAfter, '编辑 scope 不得改动摘要：调用方无需换 token');
        $resolved = $this->service->resolve(hash('sha256', $created['token']));
        $this->assertNotNull($resolved, '原明文 token 编辑 scope 后仍应有效');
        $this->assertSame(['build.read'], $resolved['scopes']);
    }

    public function testUpdateScopesAcceptsCommaSeparatedStringAndDropsBlanks(): void
    {
        $created = $this->service->createToken(['name' => 'ci-bot', 'scopes' => ['main']]);

        $this->assertTrue($this->service->updateScopes($created['id'], ' git , git ,, build.read '));

        $resolved = $this->service->resolve(hash('sha256', $created['token']));
        $this->assertSame(['git', 'build.read'], $resolved['scopes'], '逗号分隔字符串应被接受，空项忽略、重复项去重并保持顺序');
    }

    public function testUpdateScopesSucceedsWhenScopeSetUnchanged(): void
    {
        // MySQL 对值未变化的 UPDATE 返回 0 受影响行：语义必须靠存在性判断，
        // 否则保存了但没改动的情况会被前端当成失败。
        $created = $this->service->createToken(['name' => 'ci-bot', 'scopes' => ['main', 'git']]);
        $this->assertTrue($this->service->updateScopes($created['id'], ['main', 'git']));
    }

    public function testUpdateScopesRejectsUnknownScopeWithoutWriting(): void
    {
        $created = $this->service->createToken(['name' => 'ci-bot', 'scopes' => ['main']]);

        try {
            $this->service->updateScopes($created['id'], ['main', 'admin.superuser']);
            $this->fail('目录外的 scope 必须被拒绝，否则等于开放越权入口');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('admin.superuser', $e->getMessage());
        }

        $resolved = $this->service->resolve(hash('sha256', $created['token']));
        $this->assertSame(['main'], $resolved['scopes'], '被拒绝的请求不得写入任何 scope');
    }

    public function testUpdateScopesRejectsEmptySet(): void
    {
        // 勾选界面全部取消时应当报错：直接清空会让以为已收回或已授权的判断与实际情况不符
        $created = $this->service->createToken(['name' => 'ci-bot', 'scopes' => ['main']]);
        $this->expectException(\InvalidArgumentException::class);
        $this->service->updateScopes($created['id'], ['', '  ']);
    }

    public function testUpdateScopesRejectsNonListPayload(): void
    {
        $created = $this->service->createToken(['name' => 'ci-bot', 'scopes' => ['main']]);
        $this->expectException(\InvalidArgumentException::class);
        $this->service->updateScopes($created['id'], 42);
    }

    public function testUpdateScopesReturnsFalseForUnknownToken(): void
    {
        $this->assertFalse($this->service->updateScopes(999, ['main']), 'token 不存在时应返回 false（供控制器回 404）');
    }

    public function testFindByIdReturnsRowOnlyForExistingToken(): void
    {
        $created = $this->service->createToken(['name' => 'ci-bot', 'scopes' => ['main']]);

        $row = $this->service->findById($created['id']);
        $this->assertNotNull($row);
        $this->assertSame('ci-bot', $row['name']);
        $this->assertNull($this->service->findById(999));
    }
}
