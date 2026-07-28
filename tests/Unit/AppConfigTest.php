<?php
declare(strict_types=1);

namespace App\Test\Unit;

use App\Config\AppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * AppConfig 单元测试
 *
 * 验证常量一致性、默认值逻辑和配置方法正确性。
 * 不依赖数据库或外部服务。
 *
 * 运行：vendor/bin/phpunit tests/Unit/AppConfigTest.php
 */
class AppConfigTest extends TestCase
{
    // ── 常量定义测试 ──

    public function testRoleConstantsAreDistinct(): void
    {
        $roles = [AppConfig::ROLE_SUPER_ADMIN, AppConfig::ROLE_ADMIN, AppConfig::ROLE_DEPLOYER];
        $this->assertCount(3, array_unique($roles), '三个角色值必须互不相同');
    }

    public function testSystemTypeConstantsAreValid(): void
    {
        $types = [AppConfig::SYSTEM_CI, AppConfig::SYSTEM_CD, AppConfig::SYSTEM_BOTH];
        $this->assertCount(3, array_unique($types));
        $this->assertEquals('ci', AppConfig::SYSTEM_CI);
        $this->assertEquals('cd', AppConfig::SYSTEM_CD);
        $this->assertEquals('both', AppConfig::SYSTEM_BOTH);
    }

    public function testBuildModeConstantsAreValid(): void
    {
        $modes = [AppConfig::BUILD_MODE_JENKINS, AppConfig::BUILD_MODE_GITLAB_CI, AppConfig::BUILD_MODE_BOTH];
        $this->assertCount(3, array_unique($modes));
    }

    public function testProviderConstantsMatchBuildModes(): void
    {
        $this->assertEquals(AppConfig::BUILD_MODE_JENKINS, AppConfig::PROVIDER_JENKINS);
        $this->assertEquals(AppConfig::BUILD_MODE_GITLAB_CI, AppConfig::PROVIDER_GITLAB_CI);
    }

    public function testStatusConstantsAreDistinct(): void
    {
        $statuses = [AppConfig::STATUS_ACTIVE, AppConfig::STATUS_INACTIVE, AppConfig::STATUS_PENDING];
        $this->assertCount(3, array_unique($statuses));
    }

    public function testTableConstantsAreNotPlaceholder(): void
    {
        $this->assertNotEmpty(AppConfig::TABLE_JOB_GIT_MAP);
        $this->assertNotEmpty(AppConfig::TABLE_CACHE);
        $this->assertNotEmpty(AppConfig::TABLE_ADMIN_USERS);
        $this->assertNotEmpty(AppConfig::TABLE_APP_SETTINGS);
        $this->assertNotEmpty(AppConfig::TABLE_PIPELINE_TAGS);
        $this->assertNotEmpty(AppConfig::TABLE_SECURITY_CHECKS);
        $this->assertNotEmpty(AppConfig::TABLE_PLATFORM_VERSIONS);
    }

    public function testCacheKeyConstantsHavePrefixPostfix(): void
    {
        $this->assertStringEndsWith('_', AppConfig::CACHE_KEY_ADMIN_TOKEN_PREFIX);
        $this->assertStringEndsWith('_', AppConfig::CACHE_KEY_MAP_LIST_PREFIX);
    }

    public function testTtlConstantsArePositive(): void
    {
        $this->assertGreaterThan(0, AppConfig::TTL_TOKEN);
        $this->assertGreaterThan(0, AppConfig::TTL_CACHE);
        $this->assertEquals(86400, AppConfig::TTL_TOKEN);
        $this->assertEquals(3600, AppConfig::TTL_CACHE);
    }

    public function testAppVersionIsSemver(): void
    {
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', AppConfig::APP_VERSION);
    }

    // ── getSystemType 测试 ──

    public static function systemTypeProvider(): array
    {
        return [
            '默认 ci'      => [[], 'ci'],
            '显式 ci'      => [['app' => ['system_type' => 'ci']], 'ci'],
            '显式 cd'      => [['app' => ['system_type' => 'cd']], 'cd'],
            '显式 both'    => [['app' => ['system_type' => 'both']], 'both'],
            '非法值回退'  => [['app' => ['system_type' => 'hacker']], 'ci'],
            '空字符串回退' => [['app' => ['system_type' => '']], 'ci'],
        ];
    }

    #[DataProvider('systemTypeProvider')]
    public function testGetSystemType(array $config, string $expected): void
    {
        $appConfig = new AppConfig($config);
        $this->assertEquals($expected, $appConfig->getSystemType());
    }

    // ── getRootAdminUser 测试 ──

    public function testGetRootAdminUserDefault(): void
    {
        $appConfig = new AppConfig([]);
        $this->assertEquals('admin', $appConfig->getRootAdminUser());
    }

    public function testGetRootAdminUserCustom(): void
    {
        $appConfig = new AppConfig(['admin' => ['user' => 'root']]);
        $this->assertEquals('root', $appConfig->getRootAdminUser());
    }

    // ── getAdminCredentials 测试 ──

    public function testGetAdminCredentialsReturnsUserAndPassword(): void
    {
        $appConfig = new AppConfig(['admin' => ['user' => 'admin', 'password' => 'secret']]);
        $creds = $appConfig->getAdminCredentials();
        $this->assertArrayHasKey('user', $creds);
        $this->assertArrayHasKey('password', $creds);
        $this->assertEquals('admin', $creds['user']);
        $this->assertEquals('secret', $creds['password']);
    }

    public function testGetAdminCredentialsWithoutPasswordReturnsEmpty(): void
    {
        $appConfig = new AppConfig([]);
        $creds = $appConfig->getAdminCredentials();
        $this->assertEquals('', $creds['password']);
    }

    // ── getAppEnv 测试 ──

    public function testGetAppEnvDefault(): void
    {
        $appConfig = new AppConfig([]);
        $this->assertEquals('production', $appConfig->getAppEnv());
    }

    public function testGetAppEnvCustom(): void
    {
        $appConfig = new AppConfig(['app' => ['env' => 'development']]);
        $this->assertEquals('development', $appConfig->getAppEnv());
    }

    // ── getApiBaseUrl 测试 ──

    public function testGetApiBaseUrlDefaultEmpty(): void
    {
        $appConfig = new AppConfig([]);
        $this->assertEquals('', $appConfig->getApiBaseUrl());
    }

    public function testGetApiBaseUrlCustom(): void
    {
        $appConfig = new AppConfig(['app' => ['api_base_url' => 'https://example.com']]);
        $this->assertEquals('https://example.com', $appConfig->getApiBaseUrl());
    }

    // ── Git 平台判读 ──

    public function testIsPlatformConfiguredNotConfigured(): void
    {
        $appConfig = new AppConfig([]);
        $this->assertFalse($appConfig->isPlatformConfigured('gitlab'));
        $this->assertFalse($appConfig->isPlatformConfigured('gitee'));
    }

    public function testIsPlatformConfiguredWithBaseUrl(): void
    {
        $appConfig = new AppConfig(['git' => ['gitlab' => ['base_url' => 'https://gitlab.example.com']]]);
        $this->assertTrue($appConfig->isPlatformConfigured('gitlab'));
    }

    public function testIsPlatformConfiguredWithApiBaseUrl(): void
    {
        $appConfig = new AppConfig(['git' => ['gitee' => ['api_base_url' => 'https://gitee.com/api/v5']]]);
        $this->assertTrue($appConfig->isPlatformConfigured('gitee'));
    }

    public function testGetDefaultGitPlatform(): void
    {
        $appConfig = new AppConfig([]);
        $this->assertEquals('gitlab', $appConfig->getDefaultGitPlatform());

        $appConfig = new AppConfig(['git' => ['default_platform' => 'gitee']]);
        $this->assertEquals('gitee', $appConfig->getDefaultGitPlatform());
    }

    // ── Jenkins 配置 ──

    public function testGetJenkinsConfigDefaults(): void
    {
        $appConfig = new AppConfig([]);
        $jenkins = $appConfig->getJenkinsConfig();
        $this->assertArrayHasKey('url', $jenkins);
        $this->assertArrayHasKey('user', $jenkins);
        $this->assertArrayHasKey('token', $jenkins);
        $this->assertEquals('http://localhost:8083', $jenkins['url']);
        $this->assertEquals('', $jenkins['user']);
    }

    // ── CORS 配置 ──

    public function testGetCorsConfigDefault(): void
    {
        $appConfig = new AppConfig([]);
        $cors = $appConfig->getCorsConfig();
        $this->assertArrayHasKey('allowed_origins', $cors);
        $this->assertEquals(['*'], $cors['allowed_origins']);
    }

    // ── getGitPlatformsConfig ──

    public function testGetGitPlatformsConfigEmpty(): void
    {
        $appConfig = new AppConfig([]);
        $platforms = $appConfig->getGitPlatformsConfig();
        $this->assertIsArray($platforms);
        $this->assertEmpty($platforms);
    }

    public function testGetGitPlatformsConfigWithGitlab(): void
    {
        $appConfig = new AppConfig([
            'git' => ['gitlab' => ['base_url' => 'https://gitlab.example.com', 'api_version' => 'v4']],
        ]);
        $platforms = $appConfig->getGitPlatformsConfig();
        $this->assertCount(1, $platforms);
        $this->assertEquals('gitlab', $platforms[0]['name']);
        $this->assertEquals('v4', $platforms[0]['api_version']);
        $this->assertStringContainsString('/api/v4', $platforms[0]['api_base_url']);
    }

    public function testGetGitPlatformsConfigSkipsUnconfigured(): void
    {
        $appConfig = new AppConfig([
            'git' => [
                'gitlab' => ['base_url' => 'https://gitlab.example.com'],
                'gitee'  => [], // 未配置
            ],
        ]);
        $platforms = $appConfig->getGitPlatformsConfig();
        $this->assertCount(1, $platforms);
        $this->assertEquals('gitlab', $platforms[0]['name']);
    }

    // ── getLogPath ──

    public function testGetLogPathDefault(): void
    {
        $appConfig = new AppConfig([]);
        $this->assertEquals('', $appConfig->getLogPath());
    }

    public function testGetLogPathCustom(): void
    {
        $appConfig = new AppConfig(['app' => ['log_path' => '/var/log/app']]);
        $this->assertEquals('/var/log/app', $appConfig->getLogPath());
    }

    // ── getHarborConfig ──

    public function testGetHarborConfigDefault(): void
    {
        $appConfig = new AppConfig([]);
        $this->assertEquals([], $appConfig->getHarborConfig());
    }

    public function testGetHarborConfigCustom(): void
    {
        $appConfig = new AppConfig(['harbor' => ['url' => 'https://harbor.example.com', 'username' => 'admin']]);
        $config = $appConfig->getHarborConfig();
        $this->assertEquals('https://harbor.example.com', $config['url']);
        $this->assertEquals('admin', $config['username']);
    }
}
