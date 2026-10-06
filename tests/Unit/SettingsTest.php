<?php
declare(strict_types=1);

namespace App\Test\Unit;

use App\Service\Settings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Settings 单元测试（settings.php / env 只读配置，无 DB 时走默认值）。
 */
class SettingsTest extends TestCase
{
    // ── getSystemType ──

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
        $this->assertEquals($expected, (new Settings($config))->getSystemType());
    }

    // ── getRootAdminUser ──

    public function testGetRootAdminUserDefault(): void
    {
        $this->assertEquals('admin', (new Settings([]))->getRootAdminUser());
    }

    public function testGetRootAdminUserCustom(): void
    {
        $this->assertEquals('root', (new Settings(['admin' => ['user' => 'root']]))->getRootAdminUser());
    }

    // ── getAdminCredentials ──

    public function testGetAdminCredentialsReturnsUserAndPassword(): void
    {
        $creds = (new Settings(['admin' => ['user' => 'admin', 'password' => 'secret']]))->getAdminCredentials();
        $this->assertArrayHasKey('user', $creds);
        $this->assertArrayHasKey('password', $creds);
        $this->assertEquals('admin', $creds['user']);
        $this->assertEquals('secret', $creds['password']);
    }

    public function testGetAdminCredentialsWithoutPasswordReturnsEmpty(): void
    {
        $this->assertEquals('', (new Settings([]))->getAdminCredentials()['password']);
    }

    // ── getAppEnv ──

    public function testGetAppEnvDefault(): void
    {
        $this->assertEquals('production', (new Settings([]))->getAppEnv());
    }

    public function testGetAppEnvCustom(): void
    {
        $this->assertEquals('development', (new Settings(['app' => ['env' => 'development']]))->getAppEnv());
    }

    // ── getApiBaseUrl ──

    public function testGetApiBaseUrlDefaultEmpty(): void
    {
        $this->assertEquals('', (new Settings([]))->getApiBaseUrl());
    }

    public function testGetApiBaseUrlCustom(): void
    {
        $this->assertEquals(
            'https://example.com',
            (new Settings(['app' => ['api_base_url' => 'https://example.com']]))->getApiBaseUrl()
        );
    }

    // ── Git 平台判读 ──

    public function testIsPlatformConfiguredNotConfigured(): void
    {
        $s = new Settings([]);
        $this->assertFalse($s->isPlatformConfigured('gitlab'));
        $this->assertFalse($s->isPlatformConfigured('gitee'));
    }

    public function testIsPlatformConfiguredWithBaseUrl(): void
    {
        $this->assertTrue(
            (new Settings(['git' => ['gitlab' => ['base_url' => 'https://gitlab.example.com']]]))
                ->isPlatformConfigured('gitlab')
        );
    }

    public function testIsPlatformConfiguredWithApiBaseUrl(): void
    {
        $this->assertTrue(
            (new Settings(['git' => ['gitee' => ['api_base_url' => 'https://gitee.com/api/v5']]]))
                ->isPlatformConfigured('gitee')
        );
    }

    public function testGetDefaultGitPlatform(): void
    {
        $this->assertEquals('gitlab', (new Settings([]))->getDefaultGitPlatform());
        $this->assertEquals(
            'gitee',
            (new Settings(['git' => ['default_platform' => 'gitee']]))->getDefaultGitPlatform()
        );
    }

    // ── Jenkins 配置 ──

    public function testGetJenkinsConfigDefaults(): void
    {
        $jenkins = (new Settings([]))->getJenkinsConfig();
        $this->assertArrayHasKey('url', $jenkins);
        $this->assertArrayHasKey('user', $jenkins);
        $this->assertArrayHasKey('token', $jenkins);
        $this->assertEquals('http://localhost:8083', $jenkins['url']);
        $this->assertEquals('', $jenkins['user']);
    }

    // ── 自定义 Build Provider（custom_push）配置 ──

    public function testGetCustomBuildProvidersEmptyByDefault(): void
    {
        $providers = (new Settings([]))->getCustomBuildProviders();
        $this->assertIsArray($providers);
        $this->assertEmpty($providers);
    }

    public function testGetCustomBuildProvidersFromConfig(): void
    {
        $providers = [
            ['name' => 'custom_push', 'class' => 'App\\Service\\Build\\CustomPushBuildProvider', 'config' => ['variables' => []]],
        ];
        $this->assertSame($providers, (new Settings(['build' => ['custom_providers' => $providers]]))->getCustomBuildProviders());
    }

    // ── CORS 配置 ──

    public function testGetCorsConfigDefault(): void
    {
        $cors = (new Settings([]))->getCorsConfig();
        $this->assertArrayHasKey('allowed_origins', $cors);
        $this->assertEquals(['*'], $cors['allowed_origins']);
    }

    // ── getGitPlatformsConfig ──

    public function testGetGitPlatformsConfigEmpty(): void
    {
        $this->assertEmpty((new Settings([]))->getGitPlatformsConfig());
    }

    public function testGetGitPlatformsConfigWithGitlab(): void
    {
        $platforms = (new Settings([
            'git' => ['gitlab' => ['base_url' => 'https://gitlab.example.com', 'api_version' => 'v4']],
        ]))->getGitPlatformsConfig();
        $this->assertCount(1, $platforms);
        $this->assertEquals('gitlab', $platforms[0]['name']);
        $this->assertEquals('v4', $platforms[0]['api_version']);
        $this->assertStringContainsString('/api/v4', $platforms[0]['api_base_url']);
    }

    public function testGetGitPlatformsConfigSkipsUnconfigured(): void
    {
        $platforms = (new Settings([
            'git' => [
                'gitlab' => ['base_url' => 'https://gitlab.example.com'],
                'gitee'  => [],
            ],
        ]))->getGitPlatformsConfig();
        $this->assertCount(1, $platforms);
        $this->assertEquals('gitlab', $platforms[0]['name']);
    }

    // ── getLogPath ──

    public function testGetLogPathDefault(): void
    {
        $this->assertEquals('', (new Settings([]))->getLogPath());
    }

    public function testGetLogPathCustom(): void
    {
        $this->assertEquals(
            '/var/log/app',
            (new Settings(['app' => ['log_path' => '/var/log/app']]))->getLogPath()
        );
    }

    // ── getHarborConfig ──

    public function testGetHarborConfigDefault(): void
    {
        $this->assertEquals([], (new Settings([]))->getHarborConfig());
    }

    public function testGetHarborConfigCustom(): void
    {
        $config = (new Settings(['harbor' => ['url' => 'https://harbor.example.com', 'username' => 'admin']]))
            ->getHarborConfig();
        $this->assertEquals('https://harbor.example.com', $config['url']);
        $this->assertEquals('admin', $config['username']);
    }

    // ── 平台 API 版本：无 PlatformVersionRepository 时走 config/默认 ──

    public function testGetPlatformApiVersionsDefaults(): void
    {
        $versions = (new Settings([]))->getPlatformApiVersions();
        $this->assertSame('v4', $versions['gitlab']);
        $this->assertSame('v5', $versions['gitee']);
    }

    public function testGetPlatformApiVersionsConfigOverride(): void
    {
        $versions = (new Settings([
            'git' => ['gitlab' => ['base_url' => 'https://gitlab.example.com', 'api_version' => 'v3']],
        ]))->getPlatformApiVersions();
        $this->assertSame('v3', $versions['gitlab'], 'config 显式覆盖优先于默认');
    }
}
