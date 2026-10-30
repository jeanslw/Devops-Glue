<?php

declare(strict_types=1);

namespace App\Test\Unit;

use App\Config\AppConfig;
use App\Service\AppSettingRepository;
use App\Service\Database;
use App\Service\OAuthClientRepository;
use App\Service\OidcConfigImporter;
use PHPUnit\Framework\TestCase;

/**
 * OIDC 配置「env → DB」导入器。
 *
 * 回归背景：v2.10.0 起 ci_oauth_clients 是客户端白名单的唯一权威，运行期不再读 env。
 * 但全新库「只建表、不播种」：联合部署（CD 调 Glue 登录）时忘了跑 cli/migrate-oidc.php，
 * CD 发起 /oauth/authorize 直接 400「无效的 OAuth 请求」，现象是「整套部署完就是登不上」。
 *
 * 本文件锁死两条不变量：
 *   1. 全新库（空表）引导时按 env 自动播种，CD 客户端 system 必须落成 'cd'
 *      （落成 'ci' 会让 CD 账号在登录页被 auth.no_ci_access 拒绝）；
 *   2. 表里已有任意一行就绝不介入——后台人工配置永不被 env 覆盖。
 */
class OidcConfigImporterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_ENV['ADMIN_USER']     = 'admin';
        $_ENV['ADMIN_PASSWORD'] = 'secret';
        $_ENV['LOG_PATH']       = sys_get_temp_dir() . DIRECTORY_SEPARATOR;
        $_ENV['DATA_ENC_KEY']   = base64_encode(random_bytes(32));
        Database::reset();
    }

    protected function tearDown(): void
    {
        unset(
            $_ENV['ADMIN_USER'],
            $_ENV['ADMIN_PASSWORD'],
            $_ENV['LOG_PATH'],
            $_ENV['DATA_ENC_KEY'],
            $_ENV['OIDC_ISSUER'],
            $_ENV['OIDC_ID_TOKEN_TTL'],
            $_ENV['CD_OIDC_SECRET'],
            $_ENV['CD_OIDC_REDIRECT_URI'],
            $_ENV['CD_OIDC_SYSTEM'],
            $_ENV['JENKINS_OIDC_SECRET'],
            $_ENV['JENKINS_OIDC_REDIRECT_URI']
        );
        Database::reset();
        parent::tearDown();
    }

    private function memoryPdo(): \PDO
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        return $pdo;
    }

    /** 建表并完成引导（不设 OIDC env 时不会播种任何客户端行）。 */
    private function bootstrappedPdo(): \PDO
    {
        Database::init(['driver' => 'sqlite', 'auto_migrate' => true]);
        $pdo = $this->memoryPdo();
        Database::bootstrap($pdo);
        return $pdo;
    }

    public function testFreshDatabaseAutoSeedsCdClientFromEnv(): void
    {
        $_ENV['CD_OIDC_SECRET']        = 'cd-secret';
        $_ENV['CD_OIDC_REDIRECT_URI']  = 'http://cd.example.com/api/auth/oidc/callback';
        $_ENV['OIDC_ISSUER']           = 'https://glue.example.com';
        $_ENV['OIDC_ID_TOKEN_TTL']     = '3600';

        $pdo = $this->bootstrappedPdo();

        $row = (new OAuthClientRepository($pdo))->find('cd');
        $this->assertNotNull($row, '全新库引导时应自动播种 cd 客户端');
        $this->assertSame('cd-secret', $row['secret'], 'secret 必须加密落库、读出时透明解密');
        $this->assertSame('http://cd.example.com/api/auth/oidc/callback', $row['redirect_uri']);
        $this->assertSame(AppConfig::SYSTEM_CD, $row['system'], 'cd 客户端默认归属 CD 系统，否则 CD 账号会被 auth.no_ci_access 拒绝');
        $this->assertSame(1, (int)$row['enabled']);

        $this->assertSame('https://glue.example.com', (new AppSettingRepository($pdo))->getOidcIssuer());
        $this->assertSame(3600, (new AppSettingRepository($pdo))->getOidcIdTokenTtl());
    }

    public function testExistingClientRowIsNeverOverwrittenByEnv(): void
    {
        $pdo = $this->bootstrappedPdo();
        $clients = new OAuthClientRepository($pdo);
        $clients->save('jenkins', [
            'secret'       => 'manual-secret',
            'redirect_uri' => 'https://jenkins.example.com/securityRealm/finishLogin',
            'system'       => AppConfig::SYSTEM_CI,
            'enabled'      => true,
            'description'  => '后台手工配置',
        ]);

        $_ENV['CD_OIDC_SECRET']       = 'cd-secret';
        $_ENV['CD_OIDC_REDIRECT_URI'] = 'http://cd.example.com/api/auth/oidc/callback';
        Database::reset();
        Database::init(['driver' => 'sqlite', 'auto_migrate' => true]);
        Database::bootstrap($pdo);

        $this->assertNull($clients->find('cd'), '表里已有行 → 视为「已配置过」，不得再自动播种');
        $this->assertSame('manual-secret', (string)$clients->find('jenkins')['secret'], '人工配置的 secret 不得被覆盖');
    }

    public function testImporterReportsIncompleteClientAsSkippedWithoutRow(): void
    {
        $pdo = $this->bootstrappedPdo();
        $_ENV['CD_OIDC_SECRET'] = 'cd-secret';

        $result = (new OidcConfigImporter($pdo))->importFromEnv();

        $this->assertSame([], $result['migrated']);
        $this->assertNull((new OAuthClientRepository($pdo))->find('cd'));
        $this->assertStringContainsString('client:cd', implode('；', $result['skipped']), '缺 redirect_uri 应被记为「不齐、未写入」而不是静默丢弃');
    }

    public function testImporterIsIdempotentAndKeepsExistingValues(): void
    {
        $pdo = $this->bootstrappedPdo();
        $_ENV['CD_OIDC_SECRET']       = 'cd-secret';
        $_ENV['CD_OIDC_REDIRECT_URI'] = 'http://cd.example.com/api/auth/oidc/callback';

        $importer = new OidcConfigImporter($pdo);
        $first    = $importer->importFromEnv();
        $second   = $importer->importFromEnv();

        $this->assertContains('client:cd', $first['migrated']);
        $this->assertSame([], $second['migrated'], '第二次执行不应重复写入');
        $this->assertStringContainsString('client:cd（DB 已有行，不覆盖）', implode('；', $second['skipped']));
    }
}
