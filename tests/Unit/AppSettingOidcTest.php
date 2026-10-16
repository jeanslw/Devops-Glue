<?php
declare(strict_types=1);

namespace App\Test\Unit;

use App\Config\AppConfig;
use App\Service\AppSettingRepository;
use App\Service\Database;
use PHPUnit\Framework\TestCase;

/**
 * AppSettingRepository 的 OIDC provider 配置存取（ci_app_settings 的 oidc_issuer / oidc_id_token_ttl）。
 *
 * 边界约定（配合 config/container.php 的 OidcService 闭包）：
 *   - 未设置 → getOidcIssuer() 返回 null（回退 env）；
 *   - setOidcIssuer('') 清行（回退 env），不留空串；
 *   - issuer 统一 rtrim('/')，避免尾斜杠造成相等性漂移；
 *   - id_token_ttl 非法/<=0 时读返回 null、写抛 InvalidArgumentException。
 */
class AppSettingOidcTest extends TestCase
{
    private \PDO $pdo;
    private AppSettingRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        Database::init(['driver' => 'sqlite']);

        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $this->pdo->exec('CREATE TABLE ' . AppConfig::TABLE_APP_SETTINGS . ' (
            setting_key TEXT PRIMARY KEY,
            value TEXT NOT NULL,
            updated_at TEXT
        )');

        $this->repo = new AppSettingRepository($this->pdo);
    }

    protected function tearDown(): void
    {
        Database::reset();
        parent::tearDown();
    }

    public function testIssuerIsNullWhenUnset(): void
    {
        $this->assertNull($this->repo->getOidcIssuer());
    }

    public function testIssuerRtrimTrailingSlash(): void
    {
        $this->repo->setOidcIssuer('https://glue.example.com/');
        $this->assertSame('https://glue.example.com', $this->repo->getOidcIssuer());
    }

    public function testEmptyIssuerDeletesRowFallsBackToEnv(): void
    {
        $this->repo->setOidcIssuer('https://glue.example.com');
        $this->assertNotNull($this->repo->getOidcIssuer());

        $this->repo->setOidcIssuer('');
        $this->assertNull($this->repo->getOidcIssuer(), '空串必须清行回退 env，不留空值');
        $this->assertSame(0, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM ' . AppConfig::TABLE_APP_SETTINGS . " WHERE setting_key = '" . AppConfig::SETTING_OIDC_ISSUER . "'"
        )->fetchColumn());
    }

    public function testIdTokenTtlIsNullWhenUnset(): void
    {
        $this->assertNull($this->repo->getOidcIdTokenTtl());
    }

    public function testIdTokenTtlRejectsNonPositiveOnWrite(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->repo->setOidcIdTokenTtl(0);
    }

    public function testIdTokenTtlReadsNullForInvalidStoredValue(): void
    {
        $this->pdo->prepare(
            'INSERT INTO ' . AppConfig::TABLE_APP_SETTINGS . ' (setting_key, value) VALUES (?, ?)'
        )->execute([AppConfig::SETTING_OIDC_ID_TOKEN_TTL, '0']);
        $this->assertNull($this->repo->getOidcIdTokenTtl(), '存量 <=0 视为未设置，回退 env 默认');

        $this->repo->setOidcIdTokenTtl(7200);
        $this->assertSame(7200, $this->repo->getOidcIdTokenTtl());
    }
}
