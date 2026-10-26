<?php
declare(strict_types=1);

namespace App\Test\Unit;

use App\Config\AppConfig;
use App\Service\Database;
use App\Service\Encryptor;
use App\Service\PlatformConnectionRepository;
use PHPUnit\Framework\TestCase;

/**
 * PlatformConnectionRepository 单元测试（内存 SQLite）。
 *
 * 覆盖 Git/Harbor/Jenkins 连接配置的「DB 唯一权威、无 env 兜底」语义：
 *   - 空表 resolveConnections 回填代码级默认（github/gitee base_url、harbor username、jenkins user）；
 *   - save 后 resolveConnections 返回 DB 值（覆盖默认）；
 *   - save 更新保留 created_at（不用 REPLACE INTO）；
 *   - delete 后回退代码默认（无 env 兜底，不会复活）。
 */
class PlatformConnectionRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private PlatformConnectionRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        Database::init(['driver' => 'sqlite']);

        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

        
        $this->pdo->exec('CREATE TABLE ' . AppConfig::TABLE_PLATFORM_CONNECTIONS . ' (
            platform    TEXT PRIMARY KEY,
            url         TEXT,
            token       TEXT,
            username    TEXT,
            password    TEXT,
            description TEXT DEFAULT \'\',
            created_at  TEXT DEFAULT (datetime(\'now\',\'localtime\')),
            updated_at  TEXT DEFAULT (datetime(\'now\',\'localtime\'))
        )');

        $this->repo = new PlatformConnectionRepository($this->pdo, new Encryptor(base64_encode(random_bytes(32))));
    }

    protected function tearDown(): void
    {
        Database::reset();
        parent::tearDown();
    }

    public function testResolveConnectionsEmptyTableReturnsCodeDefaults(): void
    {
        $conns = $this->repo->resolveConnections();

        $this->assertSame('', $conns['gitlab']['base_url'], '自建 gitlab 无行应回填空 base_url');
        $this->assertSame('', $conns['gitea']['base_url'], '自建 gitea 无行应回填空 base_url');
        $this->assertSame('https://api.github.com', $conns['github']['base_url'], 'SaaS github 应回填默认 base_url');
        $this->assertSame('https://gitee.com/api/v5', $conns['gitee']['base_url'], 'SaaS gitee 应回填默认 base_url');
        $this->assertSame('admin', $conns['harbor']['username'], 'harbor username 空值回填 admin');
        $this->assertSame('admin', $conns['jenkins']['user'], 'jenkins user 空值回填 admin');
        $this->assertSame('', $conns['jenkins']['url'], 'jenkins url 无行应回填空串（未配置）');
    }

    public function testSaveThenResolveReturnsDbValues(): void
    {
        $this->repo->save('gitlab', [
            'url' => 'https://gitlab.example.com', 'token' => 'glpat-abc',
            'username' => '', 'password' => '', 'description' => '',
        ]);

        $conns = $this->repo->resolveConnections();
        $this->assertSame('https://gitlab.example.com', $conns['gitlab']['base_url'], 'DB 值必须覆盖代码默认');
        $this->assertSame('glpat-abc', $conns['gitlab']['token']);
    }

    public function testSaveEncryptsSensitiveColumnsAtRest(): void
    {
        $this->repo->save('harbor', [
            'url' => 'https://harbor.example.com', 'token' => '',
            'username' => 'admin', 'password' => 's3cret', 'description' => '',
        ]);

        $row = $this->pdo->query(
            'SELECT url, token, username, password FROM ' . AppConfig::TABLE_PLATFORM_CONNECTIONS . " WHERE platform = 'harbor'"
        )->fetch();

        $this->assertSame('https://harbor.example.com', $row['url'], 'url 不加密');
        $this->assertSame('', (string) $row['token'], '空 token 保持空（= 未配置）');
        $this->assertStringStartsWith('enc:v1:', (string) $row['username'], 'username 必须密文落库');
        $this->assertStringStartsWith('enc:v1:', (string) $row['password'], 'password 必须密文落库');

        $conns = $this->repo->resolveConnections();
        $this->assertSame('admin', $conns['harbor']['username'], '解密读回仍为明文');
        $this->assertSame('s3cret', $conns['harbor']['password']);
    }

    public function testSaveUpdatePreservesCreatedAt(): void
    {
        
        $this->pdo->prepare(
            'INSERT INTO ' . AppConfig::TABLE_PLATFORM_CONNECTIONS
            . ' (platform, url, token, username, password, description, created_at, updated_at)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute(['harbor', 'https://old', '', 'admin', 'pw', '', '2000-01-01 00:00:00', '2000-01-01 00:00:00']);

        $this->repo->save('harbor', [
            'url' => 'https://new', 'token' => '', 'username' => 'admin', 'password' => 'pw2', 'description' => '',
        ]);

        $row = $this->repo->find('harbor');
        $this->assertNotNull($row);
        $this->assertSame('2000-01-01 00:00:00', $row['created_at'], 'UPDATE 不得重置 created_at（禁用 REPLACE INTO）');
        $this->assertSame('https://new', $row['url']);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM ' . AppConfig::TABLE_PLATFORM_CONNECTIONS)->fetchColumn(),
            'save 更新必须落在同一行，不得删除重插');
    }

    public function testDeleteFallsBackToCodeDefaults(): void
    {
        $this->repo->save('gitlab', [
            'url' => 'https://gitlab.db', 'token' => 'glpat-db', 'username' => '', 'password' => '', 'description' => '',
        ]);
        $this->repo->delete('gitlab');

        $conns = $this->repo->resolveConnections();
        $this->assertSame('', $conns['gitlab']['base_url'], '删除后回退代码默认空值，无 env 兜底');
        $this->assertSame('', $conns['gitlab']['token']);
    }
}
