<?php
declare(strict_types=1);

namespace App\Test\Unit;

use App\Config\AppConfig;
use App\Service\Database;
use App\Service\Encryptor;
use App\Service\OAuthClientRepository;
use PHPUnit\Framework\TestCase;

/**
 * OAuthClientRepository 单元测试（内存 SQLite）。
 *
 * 覆盖 OIDC/OAuth 客户端白名单的 DB-权威 + env-兜底合并语义：
 *   - 无 DB 行 → env 透传；
 *   - enabled DB 行整行覆盖同名 env 客户端；
 *   - disabled DB 行显式移除（即使 env 有同名客户端）；
 *   - DB 新增、env 没有的 client_id 也进入结果；
 *   - save 更新保留 created_at（不用 REPLACE INTO）；
 *   - delete 回退 env 兜底。
 */
class OAuthClientRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private OAuthClientRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        Database::init(['driver' => 'sqlite']);

        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

        
        $this->pdo->exec('CREATE TABLE ' . AppConfig::TABLE_OAUTH_CLIENTS . ' (
            client_id    TEXT PRIMARY KEY,
            secret       TEXT,
            redirect_uri TEXT,
            system       TEXT NOT NULL DEFAULT \'ci\',
            enabled      INTEGER NOT NULL DEFAULT 1,
            description  TEXT DEFAULT \'\',
            created_at   TEXT DEFAULT (datetime(\'now\',\'localtime\')),
            updated_at   TEXT DEFAULT (datetime(\'now\',\'localtime\'))
        )');

        $this->repo = new OAuthClientRepository($this->pdo, new Encryptor(base64_encode(random_bytes(32))));
    }

    protected function tearDown(): void
    {
        Database::reset();
        parent::tearDown();
    }

    public function testNoDbRowsPassesEnvThroughUntouched(): void
    {
        $env = [
            'cd'      => ['secret' => 's1', 'redirect_uri' => 'https://cd/cb', 'system' => 'cd'],
            'jenkins' => ['secret' => 's2', 'redirect_uri' => 'https://jenkins/cb', 'system' => 'ci'],
        ];
        $this->assertSame($env, $this->repo->resolveClients($env));
    }

    public function testEnabledDbRowOverridesSameEnvClient(): void
    {
        $this->repo->save('cd', [
            'secret' => 'db-secret', 'redirect_uri' => 'https://db/cb', 'system' => 'cd', 'enabled' => true, 'description' => '',
        ]);
        $merged = $this->repo->resolveClients([
            'cd' => ['secret' => 'env-secret', 'redirect_uri' => 'https://env/cb', 'system' => 'cd'],
        ]);
        $this->assertSame('db-secret', $merged['cd']['secret']);
        $this->assertSame('https://db/cb', $merged['cd']['redirect_uri']);
    }

    public function testDisabledDbRowRemovesClientEvenIfEnvHasIt(): void
    {
        $this->repo->save('cd', [
            'secret' => 'db-secret', 'redirect_uri' => 'https://db/cb', 'system' => 'cd', 'enabled' => false, 'description' => '',
        ]);
        $merged = $this->repo->resolveClients([
            'cd' => ['secret' => 'env-secret', 'redirect_uri' => 'https://env/cb', 'system' => 'cd'],
            'jenkins' => ['secret' => 's2', 'redirect_uri' => 'https://jenkins/cb', 'system' => 'ci'],
        ]);
        $this->assertArrayNotHasKey('cd', $merged, 'disabled 客户端必须显式移除（DB 权威）');
        $this->assertArrayHasKey('jenkins', $merged, '无关 env 客户端不受影响');
    }

    public function testDbNewClientAppearsEvenWithoutEnv(): void
    {
        $this->repo->save('harbor', [
            'secret' => 'hs', 'redirect_uri' => 'https://harbor/cb', 'system' => 'ci', 'enabled' => true, 'description' => '',
        ]);
        $merged = $this->repo->resolveClients([]);
        $this->assertSame('hs', $merged['harbor']['secret']);
    }

    public function testSaveEncryptsSecretAtRest(): void
    {
        $this->repo->save('cd', [
            'secret' => 'db-secret', 'redirect_uri' => 'https://db/cb', 'system' => 'cd', 'enabled' => true, 'description' => '',
        ]);

        $row = $this->pdo->query(
            'SELECT secret, redirect_uri FROM ' . AppConfig::TABLE_OAUTH_CLIENTS . " WHERE client_id = 'cd'"
        )->fetch();

        $this->assertStringStartsWith('enc:v1:', (string) $row['secret'], 'secret 必须密文落库');
        $this->assertSame('https://db/cb', $row['redirect_uri'], 'redirect_uri 不加密');

        $merged = $this->repo->resolveClients([]);
        $this->assertSame('db-secret', $merged['cd']['secret'], '解密读回仍为明文');
    }

    public function testSaveUpdatePreservesCreatedAt(): void
    {
        
        $this->pdo->prepare(
            'INSERT INTO ' . AppConfig::TABLE_OAUTH_CLIENTS
            . ' (client_id, secret, redirect_uri, system, enabled, description, created_at, updated_at)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute(['cd', 'old', 'https://old/cb', 'cd', 1, '', '2000-01-01 00:00:00', '2000-01-01 00:00:00']);

        $this->repo->save('cd', [
            'secret' => 'new', 'redirect_uri' => 'https://new/cb', 'system' => 'cd', 'enabled' => true, 'description' => '',
        ]);

        $row = $this->repo->find('cd');
        $this->assertNotNull($row);
        $this->assertSame('2000-01-01 00:00:00', $row['created_at'], 'UPDATE 不得重置 created_at（禁用 REPLACE INTO）');
        $this->assertSame('new', $row['secret']);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM ' . AppConfig::TABLE_OAUTH_CLIENTS)->fetchColumn(),
            'save 更新必须落在同一行，不得删除重插');
    }

    public function testDeleteFallsBackToEnv(): void
    {
        $this->repo->save('cd', [
            'secret' => 'db-secret', 'redirect_uri' => 'https://db/cb', 'system' => 'cd', 'enabled' => true, 'description' => '',
        ]);
        $this->repo->delete('cd');
        $merged = $this->repo->resolveClients([
            'cd' => ['secret' => 'env-secret', 'redirect_uri' => 'https://env/cb', 'system' => 'cd'],
        ]);
        $this->assertSame('env-secret', $merged['cd']['secret'], '删除后应回退 env 兜底');
    }
}
