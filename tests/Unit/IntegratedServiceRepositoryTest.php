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
        $this->assertFalse($state['token_exists'], '从未绑定凭据时不得声称「凭据还在」');
        $this->assertFalse($state['token_enabled']);
        $this->assertFalse($state['token_expired']);
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

    /**
     * 禁用（冻结）→ 再次启用：必须**恢复同一枚凭据**，而不是重新签发。
     *
     * 这是运营的硬要求：每次「禁用再启用」都换 Token，CD 就得跟着改一次 CI_API_TOKEN。
     * revoke 只把 enabled 置 0、sha256 摘要一字未动，所以 restore 同 id 后原明文立即重新生效；
     * 契约行刻意保留 api_token_id，就是这个引用的作用。
     */
    public function testDisableThenEnableReusesTheSameCredential(): void
    {
        $first = $this->issueCdToken();
        $this->pdo->beginTransaction();
        $this->repo->enable(AppConfig::SERVICE_CD, AppConfig::SERVICE_PROVISION_AUTO, $first['id'], 'root');
        $this->pdo->commit();
        $this->assertNotNull($this->tokens->resolve(hash('sha256', $first['token'])), '启用后原明文应可用');

        // 禁用（模拟控制器：仓储冻结 + 服务停用 token）
        $this->pdo->beginTransaction();
        $oldTokenId = $this->repo->disable(AppConfig::SERVICE_CD, 'root');
        $this->assertSame($first['id'], $oldTokenId);
        $this->tokens->revoke($first['id']);
        $this->pdo->commit();

        $frozen = $this->repo->getState(AppConfig::SERVICE_CD);
        $this->assertFalse($frozen['enabled'], '禁用后应冻结');
        $this->assertSame($first['id'], $frozen['token_id'], '冻结必须留住外键引用，否则无从恢复同一枚');
        $this->assertTrue($frozen['token_exists'], '冻结不是删除：凭据行还在');
        $this->assertFalse($frozen['token_enabled'], '冻结期间凭据处于停用态');
        $this->assertFalse($frozen['token_expired']);
        $this->assertFalse($frozen['token_active']);
        $this->assertNotNull($this->repo->find(AppConfig::SERVICE_CD), '禁用不得删除契约行');
        $this->assertNull($this->tokens->resolve(hash('sha256', $first['token'])), '冻结期间该凭据必须对调用方失效');

        // 再次启用 = 恢复同一枚（按控制器顺序：restore + 同事务回写契约行，不签发新凭据）
        $this->pdo->beginTransaction();
        $this->tokens->restore($first['id']);
        $this->repo->enable(AppConfig::SERVICE_CD, AppConfig::SERVICE_PROVISION_MANUAL, $first['id'], 'root');
        $this->pdo->commit();

        $restored = $this->repo->getState(AppConfig::SERVICE_CD);
        $this->assertTrue($restored['enabled']);
        $this->assertSame($first['id'], $restored['token_id'], '恢复不得换凭据');
        $this->assertTrue($restored['token_active']);
        $this->assertSame(AppConfig::SERVICE_PROVISION_MANUAL, $restored['provision'], '恢复时仍可改写建表方式');
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM ' . AppConfig::TABLE_INTEGRATED_SERVICES)->fetchColumn(),
            '重复启用必须 upsert 到同一行');
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM ' . AppConfig::TABLE_API_TOKENS)->fetchColumn(),
            '恢复不得新签发凭据（Token 列表里不应多出一枚 cd-service）');
        $this->assertNotNull($this->tokens->resolve(hash('sha256', $first['token'])),
            '恢复后原明文必须重新生效 —— CD 无需更换 CI_API_TOKEN，这正是本次改动的目的');
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

    /**
     * 「已启用但凭据已作废」不是终局（页面自助恢复的存储层保证）：
     * 契约行仍是 enabled=1，配套 token 被**撤销**（= 显式作废）后再启用必须能补发 ——
     * 同事务签发新凭据 + upsert 重绑同一行，旧凭据保持作废（不得复活）。
     *
     * 与「禁用（冻结）」的区别就在状态里：只有契约行**未**启用 + 凭据只是被停用且未过期时，
     * 控制器才走「恢复同一枚」（见 IntegratedServiceReissueWiringTest）；已作废的一律补发新凭据。
     */
    public function testReEnableAfterCredentialRevokedReissuesCredential(): void
    {
        $first = $this->issueCdToken();
        $this->pdo->beginTransaction();
        $this->repo->enable(AppConfig::SERVICE_CD, AppConfig::SERVICE_PROVISION_AUTO, $first['id'], 'root');
        $this->pdo->commit();

        // 运营在「API 管理」Token 列表里撤销了 cd-service：契约行不动，服务进入「已启用但无凭据」故障态
        $this->tokens->revoke($first['id']);
        $broken = $this->repo->getState(AppConfig::SERVICE_CD);
        $this->assertTrue($broken['enabled'], '单独撤销凭据不改契约行');
        $this->assertFalse($broken['token_active'], '撤销后凭据活性为 false —— 页面据此把勾选框退回未勾选');
        $this->assertNotNull($broken['token_id'], '撤销是停用行、不是删除：外键引用仍在');
        $this->assertTrue($broken['token_exists']);
        $this->assertFalse($broken['token_enabled']);
        // 契约行仍是「已启用」→ 控制器不会把这种凭据当冻结现场来恢复（恢复只认「行未启用 + 凭据只是被停用」）

        // 再勾一次 = 补发：按控制器顺序（作废旧凭据 + 签发新凭据 + 同事务重绑）
        $second = $this->issueCdToken();
        $this->pdo->beginTransaction();
        $this->tokens->revoke($first['id']);
        $this->repo->enable(AppConfig::SERVICE_CD, AppConfig::SERVICE_PROVISION_AUTO, $second['id'], 'root');
        $this->pdo->commit();

        $fixed = $this->repo->getState(AppConfig::SERVICE_CD);
        $this->assertTrue($fixed['enabled']);
        $this->assertSame($second['id'], $fixed['token_id'], '补发后应绑定新凭据');
        $this->assertTrue($fixed['token_active']);
        $this->assertNull($this->tokens->resolve(hash('sha256', $first['token'])), '旧凭据不得复活');
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM ' . AppConfig::TABLE_INTEGRATED_SERVICES)->fetchColumn());
    }

    /**
     * 同上，但凭据是被**硬删除**的：契约行的 api_token_id 被外键置空，
     * 补发路径同样必须可走通（控制器据此判断「无需作废旧凭据，直接签发 + 重绑」）。
     */
    public function testReEnableAfterCredentialDeletedReissuesCredential(): void
    {
        $first = $this->issueCdToken();
        $this->pdo->beginTransaction();
        $this->repo->enable(AppConfig::SERVICE_CD, AppConfig::SERVICE_PROVISION_AUTO, $first['id'], 'root');
        $this->pdo->commit();

        $this->tokens->delete($first['id']);
        $broken = $this->repo->getState(AppConfig::SERVICE_CD);
        $this->assertTrue($broken['enabled']);
        $this->assertNull($broken['token_id'], '凭据被删除后契约行只剩空引用');
        $this->assertFalse($broken['token_active']);

        $second = $this->issueCdToken();
        $this->pdo->beginTransaction();
        $this->repo->enable(AppConfig::SERVICE_CD, AppConfig::SERVICE_PROVISION_AUTO, $second['id'], 'root');
        $this->pdo->commit();

        $fixed = $this->repo->getState(AppConfig::SERVICE_CD);
        $this->assertTrue($fixed['enabled']);
        $this->assertSame($second['id'], $fixed['token_id']);
        $this->assertTrue($fixed['token_active']);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM ' . AppConfig::TABLE_INTEGRATED_SERVICES)->fetchColumn());
    }

    /**
     * 绑定凭据已过期：不能靠 restore 复活（把一枚过期凭据重新点亮仍是死凭据），控制器据此走补发。
     * 因此 getState 必须把「已过期」单独暴露出来，而不是混在 token_active=false 里。
     */
    public function testExpiredBoundCredentialIsReportedSeparatelyFromFrozen(): void
    {
        $stale = $this->tokens->createToken([
            'name'       => AppConfig::SERVICE_CD . '-service',
            'scopes'     => AppConfig::CD_SERVICE_TOKEN_SCOPES,
            'created_by' => 'root',
            'expires_at' => time() - 60,
        ]);
        $this->pdo->beginTransaction();
        $this->repo->enable(AppConfig::SERVICE_CD, AppConfig::SERVICE_PROVISION_AUTO, $stale['id'], 'root');
        $this->pdo->commit();
        $this->repo->disable(AppConfig::SERVICE_CD, 'root');

        $state = $this->repo->getState(AppConfig::SERVICE_CD);
        $this->assertFalse($state['enabled']);
        $this->assertSame($stale['id'], $state['token_id']);
        $this->assertTrue($state['token_exists'], '过期的凭据行仍在');
        $this->assertTrue($state['token_enabled'], '过期 ≠ 停用（enabled 位仍是 1）');
        $this->assertTrue($state['token_expired'], '必须单独暴露「已过期」，否则控制器会误判为可恢复');
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
