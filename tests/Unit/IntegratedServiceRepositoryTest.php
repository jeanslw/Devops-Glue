<?php
declare(strict_types=1);

namespace App\Test\Unit;

use App\Config\AppConfig;
use App\Service\ApiTokenService;
use App\Service\Database;
use App\Service\IntegratedServiceRepository;
use PHPUnit\Framework\TestCase;

/**
 * IntegratedServiceRepository 单元测试（内存 SQLite）
 *
 * 验证集成服务契约（integrated_services）语义：
 *   - 行不存在 = 默认未启用（不种子、不回退）；
 *   - enable 写 provision + 绑定 token；重复启用走 UPDATE 换新凭据；
 *   - disable 冻结（保留行与 token 引用），返回旧 token id 供调用方撤销；
 *   - token 删除后软引用置空（ON DELETE SET NULL），契约行仍在。
 */
class IntegratedServiceRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private ApiTokenService $tokens;
    private IntegratedServiceRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        Database::init(['driver' => 'sqlite']);

        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $this->pdo->exec('PRAGMA foreign_keys = ON');

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
        $this->pdo->exec('CREATE TABLE ' . AppConfig::TABLE_INTEGRATED_SERVICES . ' (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            service_name TEXT NOT NULL UNIQUE,
            enabled INTEGER NOT NULL DEFAULT 0,
            provision TEXT NOT NULL DEFAULT \'auto\',
            api_token_id INTEGER DEFAULT NULL,
            updated_by TEXT DEFAULT \'\',
            created_at TEXT DEFAULT (datetime(\'now\',\'localtime\')),
            updated_at TEXT DEFAULT (datetime(\'now\',\'localtime\')),
            FOREIGN KEY (api_token_id) REFERENCES ' . AppConfig::TABLE_API_TOKENS . '(id) ON DELETE SET NULL
        )');

        $this->tokens = new ApiTokenService($this->pdo);
        $this->repo = new IntegratedServiceRepository($this->pdo);
    }

    protected function tearDown(): void
    {
        Database::reset();
        parent::tearDown();
    }

    public function testMissingRowReadsAsDisabledWithoutSeeding(): void
    {
        $this->assertNull($this->repo->find(AppConfig::SERVICE_CD), '从未启用时不应有行（无种子）');
        $state = $this->repo->getState(AppConfig::SERVICE_CD);
        $this->assertFalse($state['enabled']);
        $this->assertSame(AppConfig::SERVICE_PROVISION_AUTO, $state['provision']);
        $this->assertNull($state['token_id']);
        $this->assertFalse($state['token_active']);
        // 读取不得偷偷建行
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM ' . AppConfig::TABLE_INTEGRATED_SERVICES)->fetchColumn());
    }

    public function testEnableInsertsRowBindsTokenAndProvision(): void
    {
        $created = $this->issueCdToken();

        $this->pdo->beginTransaction();
        $this->repo->enable(AppConfig::SERVICE_CD, AppConfig::SERVICE_PROVISION_MANUAL, $created['id'], 'root');
        $this->pdo->commit();

        $state = $this->repo->getState(AppConfig::SERVICE_CD);
        $this->assertTrue($state['enabled']);
        $this->assertSame(AppConfig::SERVICE_PROVISION_MANUAL, $state['provision']);
        $this->assertSame($created['id'], $state['token_id']);
        $this->assertSame('cd-service', $state['token_name']);
        $this->assertTrue($state['token_active'], '新签发 token 应处于有效态');
        $this->assertSame('root', $state['updated_by']);

        // 配套 token 的 scope 必须恰好是 CD 三件套
        $resolved = $this->tokens->resolve(hash('sha256', $created['token']));
        $this->assertNotNull($resolved);
        $this->assertSame(AppConfig::CD_SERVICE_TOKEN_SCOPES, $resolved['scopes']);
    }

    public function testEnableTwiceRotatesBoundTokenOnSameRow(): void
    {
        $first = $this->issueCdToken();
        $this->pdo->beginTransaction();
        $this->repo->enable(AppConfig::SERVICE_CD, AppConfig::SERVICE_PROVISION_AUTO, $first['id'], 'root');
        $this->pdo->commit();

        // 禁用（模拟控制器：仓储冻结 + 服务撤销 token）
        $oldTokenId = $this->repo->disable(AppConfig::SERVICE_CD, 'root');
        $this->assertSame($first['id'], $oldTokenId);
        $this->tokens->revoke($first['id']);

        $frozen = $this->repo->getState(AppConfig::SERVICE_CD);
        $this->assertFalse($frozen['enabled'], '禁用后应冻结');
        $this->assertFalse($frozen['token_active'], '撤销后 token 活性应为 false');
        $this->assertNotNull($this->repo->find(AppConfig::SERVICE_CD), '禁用不得删除契约行');

        // 重新启用：新 token、行仍只有一条、provision 可更新
        $second = $this->issueCdToken();
        $this->pdo->beginTransaction();
        $this->repo->enable(AppConfig::SERVICE_CD, AppConfig::SERVICE_PROVISION_MANUAL, $second['id'], 'root');
        $this->pdo->commit();

        $state = $this->repo->getState(AppConfig::SERVICE_CD);
        $this->assertTrue($state['enabled']);
        $this->assertSame($second['id'], $state['token_id'], '应绑定新 token');
        $this->assertSame(AppConfig::SERVICE_PROVISION_MANUAL, $state['provision']);
        $this->assertTrue($state['token_active']);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM ' . AppConfig::TABLE_INTEGRATED_SERVICES)->fetchColumn(),
            '重复启用必须 upsert 到同一行');
        // 旧 token 不会因重启用而复活
        $this->assertNull($this->tokens->resolve(hash('sha256', $first['token'])));
    }

    public function testDisableOnMissingRowReturnsNull(): void
    {
        $this->assertNull($this->repo->disable(AppConfig::SERVICE_CD, 'root'));
    }

    public function testDeletingTokenNullsSoftReferenceButKeepsContractRow(): void
    {
        $created = $this->issueCdToken();
        $this->pdo->beginTransaction();
        $this->repo->enable(AppConfig::SERVICE_CD, AppConfig::SERVICE_PROVISION_AUTO, $created['id'], 'root');
        $this->pdo->commit();

        $this->tokens->delete($created['id']);

        $row = $this->repo->find(AppConfig::SERVICE_CD);
        $this->assertNotNull($row, 'token 被删除不应级联删除契约行');
        $this->assertNull($row['api_token_id'], '外键应 ON DELETE SET NULL');
        $state = $this->repo->getState(AppConfig::SERVICE_CD);
        $this->assertTrue($state['enabled'], 'token 删除不影响 enabled 标志');
        $this->assertFalse($state['token_active']);
    }

    public function testRollbackWipesTokenAndRowTogether(): void
    {
        // 按控制器真实顺序：createToken 与 enable 在同一个事务里；回滚后两者都不得残留
        $this->pdo->beginTransaction();
        $created = $this->issueCdToken();
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM ' . AppConfig::TABLE_API_TOKENS)->fetchColumn());
        $this->repo->enable(AppConfig::SERVICE_CD, AppConfig::SERVICE_PROVISION_AUTO, $created['id'], 'root');
        $this->pdo->rollBack();

        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM ' . AppConfig::TABLE_API_TOKENS)->fetchColumn(),
            '事务回滚后签发的 token 必须一并消失');
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM ' . AppConfig::TABLE_INTEGRATED_SERVICES)->fetchColumn());
    }

    /**
     * 按控制器启用逻辑签发 CD 服务 token（name 固定、scope 用 AppConfig 白名单）。
     *
     * @return array{id:int, token:string}
     */
    private function issueCdToken(): array
    {
        return $this->tokens->createToken([
            'name'       => AppConfig::SERVICE_CD . '-service',
            'scopes'     => AppConfig::CD_SERVICE_TOKEN_SCOPES,
            'created_by' => 'root',
            'note'       => 'Auto-issued when enabling integrated service: cd',
        ]);
    }
}
