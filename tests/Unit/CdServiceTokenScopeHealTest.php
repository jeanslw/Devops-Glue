<?php

declare(strict_types=1);

namespace App\Test\Unit;

use App\Config\AppConfig;
use App\Service\ApiTokenService;
use App\Service\Database;
use App\Service\IntegratedServiceRepository;
use PHPUnit\Framework\TestCase;

/**
 * 升级自愈：存量 cd-service 凭据的 scope 补齐
 * （v2.9.0 起 CD 构建页改走 /api/git/{job}/refs 取 ref 候选值，而 /api/git/* 判 git scope）。
 *
 * 只改 AppConfig::CD_SERVICE_TOKEN_SCOPES 常量是不够的：2.8.x 已签发的凭据里没有 git，
 * 旧部署升级后 CD 会一路 403，而管理员并不知道要去「集成服务」页禁用再启用一次。
 *
 * 本文件锁三件事：
 *   1) 自愈真的挂在 bootstrap 路径上（漏调用即失败，且这是最容易被后续重构删掉的一行）；
 *   2) 只补不删、不动摘要 —— 原明文继续可用，CD 无需更换 CI_API_TOKEN；
 *   3) 从未启用 CD 的库不被凭空签发凭据（自愈不得变成隐式种子）。
 */
class CdServiceTokenScopeHealTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_ENV['ADMIN_USER'] = 'admin';
        $_ENV['ADMIN_PASSWORD'] = 'secret';
        // 避免 ensureTables() 里 ALTER 失败触发的日志写到 /data/logs 污染环境
        $_ENV['LOG_PATH'] = sys_get_temp_dir() . DIRECTORY_SEPARATOR;
        Database::reset();
    }

    protected function tearDown(): void
    {
        unset($_ENV['ADMIN_USER'], $_ENV['ADMIN_PASSWORD'], $_ENV['LOG_PATH']);
        Database::reset();
        parent::tearDown();
    }

    public function testUpgradeHealsLegacyCdCredentialAndKeepsPlaintextUsable(): void
    {
        $pdo    = $this->bootFreshDatabase();
        $tokens = new ApiTokenService($pdo);

        // 2.8.x 现场：cd-service 凭据只有三件套，没有 git
        $legacy = $tokens->createToken(['name' => 'cd-service', 'scopes' => $this->legacyScopes()]);
        $this->bindCdContract($pdo, $legacy['id']);

        $this->upgradeFromPreviousVersion($pdo);

        $resolved = $tokens->resolve(hash('sha256', $legacy['token']));
        $this->assertNotNull($resolved, '自愈不得改动摘要/启用态：原明文必须继续可用（CD 无需换 Token）');
        $this->assertSame(
            array_merge($this->legacyScopes(), [AppConfig::API_SCOPE_GIT]),
            $resolved['scopes'],
            '只补不删：原有顺序不变，新增的 git 追加在尾部'
        );
    }

    public function testHealNeverRevokesManuallyGrantedScopes(): void
    {
        $pdo    = $this->bootFreshDatabase();
        $tokens = new ApiTokenService($pdo);

        // 管理员手工扩过权（main）的凭据：自愈只做并集，不得悄悄降权
        $token = $tokens->createToken(['name' => 'cd-service', 'scopes' => ['main']]);
        $this->bindCdContract($pdo, $token['id']);

        $this->upgradeFromPreviousVersion($pdo);

        $scopes = $tokens->resolve(hash('sha256', $token['token']))['scopes'];
        $this->assertContains('main', $scopes, '手工扩权的 scope 不得被自愈收回');
        foreach (AppConfig::CD_SERVICE_TOKEN_SCOPES as $scope) {
            $this->assertContains($scope, $scopes, "升级后必须持有 {$scope}");
        }
    }

    public function testHealSkipsLibrariesThatNeverEnabledCd(): void
    {
        $pdo = $this->bootFreshDatabase();

        $this->upgradeFromPreviousVersion($pdo);

        $this->assertSame(
            0,
            (int) $pdo->query('SELECT COUNT(*) FROM ' . AppConfig::TABLE_API_TOKENS)->fetchColumn(),
            '从未启用 CD 时不得凭空签发凭据'
        );
        $this->assertSame(
            0,
            (int) $pdo->query('SELECT COUNT(*) FROM ' . AppConfig::TABLE_INTEGRATED_SERVICES)->fetchColumn(),
            '自愈不得顺手创建集成服务契约行（启用动作只由后台触发）'
        );
    }

    private function bootFreshDatabase(): \PDO
    {
        Database::init(['driver' => 'sqlite', 'auto_migrate' => true]);
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        Database::bootstrap($pdo);
        return $pdo;
    }

    /** @return list<string> 2.8.x 的 CD 凭据契约（无 git） */
    private function legacyScopes(): array
    {
        return [
            AppConfig::API_SCOPE_BUILD_READ,
            AppConfig::API_SCOPE_BUILD_WRITE,
            AppConfig::API_SCOPE_RBAC_USER_WRITE,
        ];
    }

    private function bindCdContract(\PDO $pdo, int $tokenId): void
    {
        $pdo->beginTransaction();
        (new IntegratedServiceRepository($pdo))->enable(
            AppConfig::SERVICE_CD,
            AppConfig::SERVICE_PROVISION_AUTO,
            $tokenId,
            'root'
        );
        $pdo->commit();
    }

    /**
     * 模拟「库标记还停在上一版本」的升级现场：下次引导必然重跑 ensureTables()，
     * 而自愈正挂在它末尾 —— 这样写才验证到接线，而不只是验证 addScopes() 本身。
     */
    private function upgradeFromPreviousVersion(\PDO $pdo): void
    {
        $pdo->prepare(
            'INSERT OR REPLACE INTO ' . AppConfig::TABLE_APP_SETTINGS . ' (setting_key, value, updated_at)'
            . ' VALUES (?, ?, 0)'
        )->execute(['schema_version', '2.8.9']);

        Database::reset();
        Database::init(['driver' => 'sqlite', 'auto_migrate' => true]);
        Database::bootstrap($pdo);
    }
}
