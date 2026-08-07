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
        $roles = [AppConfig::ROLE_SUPER_ADMIN, AppConfig::ROLE_ADMIN, AppConfig::ROLE_DEPLOYER, AppConfig::ROLE_VIEWER];
        $this->assertCount(4, array_unique($roles), '四个角色值必须互不相同');
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

    // ── IMPLIED_PERMISSIONS ──

    public function testImpliedPermissionsKeysAreValid(): void
    {
        $validKeys = array_keys(AppConfig::DEFAULT_PERMISSIONS);
        foreach (AppConfig::IMPLIED_PERMISSIONS as $key => $children) {
            $this->assertContains($key, $validKeys, "隐含权限键 '{$key}' 必须在 DEFAULT_PERMISSIONS 中");
            $this->assertIsArray($children, "'{$key}' 的值必须是数组");
            foreach ($children as $child) {
                $this->assertContains($child, $validKeys, "隐含权限 '{$key}' 的子键 '{$child}' 必须在 DEFAULT_PERMISSIONS 中");
            }
        }
    }

    public function testImpliedPermissionsNoKeyImpliesItself(): void
    {
        foreach (AppConfig::IMPLIED_PERMISSIONS as $key => $children) {
            $this->assertNotContains($key, $children, "隐含权限 '{$key}' 不能隐含自身");
        }
    }

    public function testImpliedPermissionsNoCyclicDependency(): void
    {
        // 简单检测：A→B 且 B→A 构成循环
        // 例外：cd.notification-manage ↔ cd.bot / cd.webhook 是有意设计的双向（勾父自动有子，勾子自动显示父菜单）
        $allowedCyclic = [
            [AppConfig::PERM_CD_NOTIFY, AppConfig::PERM_CD_BOT],
            [AppConfig::PERM_CD_NOTIFY, AppConfig::PERM_CD_WEBHOOK],
        ];
        $isAllowed = function($a, $b) use ($allowedCyclic) {
            foreach ($allowedCyclic as $pair) {
                if (($pair[0] === $a && $pair[1] === $b) || ($pair[0] === $b && $pair[1] === $a)) {
                    return true;
                }
            }
            return false;
        };
        $implied = AppConfig::IMPLIED_PERMISSIONS;
        $checkedPairs = 0;
        foreach ($implied as $key => $children) {
            foreach ($children as $child) {
                if (isset($implied[$child]) && in_array($key, $implied[$child], true)) {
                    $this->assertTrue($isAllowed($key, $child), "隐含权限循环：'{$key}' → '{$child}' → '{$key}'");
                    $checkedPairs++;
                }
            }
        }
        // 至少要验证过一次空断言，避免 risky（如果真没循环，显式 assertTrue 一次记录检查次数）
        $this->assertTrue(true, "未发现非法隐含循环（已检查 {$checkedPairs} 对双向边）");
    }

    public function testParentChildRelationship(): void
    {
        // 验证常见的父子关系存在
        $implied = AppConfig::IMPLIED_PERMISSIONS;
        $this->assertArrayHasKey(AppConfig::PERM_CD_BUILD, $implied, 'cd.build-manage 必须有隐含子权限');
        $this->assertContains(AppConfig::PERM_CI_TRIGGER, $implied[AppConfig::PERM_CD_BUILD], 'cd.build-manage 必须隐含 ci.trigger');
        // CI 用户管理子权限选中时自动带上父权限
        $this->assertContains(AppConfig::PERM_CI_USERS_MANAGE, $implied[AppConfig::PERM_CI_USERS_LIST], 'ci.users.list 必须隐含 ci.users.manage');
        $this->assertContains(AppConfig::PERM_CI_USERS_MANAGE, $implied[AppConfig::PERM_CI_USERS_PASSWORD], 'ci.users.password 必须隐含 ci.users.manage');
        $this->assertContains(AppConfig::PERM_CI_USERS_MANAGE, $implied[AppConfig::PERM_CI_USERS_MANAGE_ADMIN], 'ci.users.manage_admin 必须隐含 ci.users.manage');
        // CI 权限管理：1 父 3 子，子 → 父隐含
        $this->assertArrayHasKey(AppConfig::PERM_CI_PERMISSIONS_LIST, $implied);
        $this->assertArrayHasKey(AppConfig::PERM_CI_PERMISSIONS_REGISTER, $implied);
        $this->assertArrayHasKey(AppConfig::PERM_CI_PERMISSIONS_RULES, $implied);
        $this->assertContains(AppConfig::PERM_CI_PERMISSIONS_MANAGE, $implied[AppConfig::PERM_CI_PERMISSIONS_LIST], 'ci.permissions.list 必须隐含 ci.permissions.manage');
        $this->assertContains(AppConfig::PERM_CI_PERMISSIONS_MANAGE, $implied[AppConfig::PERM_CI_PERMISSIONS_REGISTER], 'ci.permissions.register 必须隐含 ci.permissions.manage');
        $this->assertContains(AppConfig::PERM_CI_PERMISSIONS_MANAGE, $implied[AppConfig::PERM_CI_PERMISSIONS_RULES], 'ci.permissions.rules 必须隐含 ci.permissions.manage');
    }

    // ── 权限 key 结构一致性：本次改动的专项测试 ──

    public function testDeprecatedRolesManageKeyIsGone(): void
    {
        // v2.4.2 撤销 ci.roles.manage，不能再出现在 DEFAULT_PERMISSIONS / 常量 / IMPLIED_PERMISSIONS 里
        $defaultKeys = array_keys(AppConfig::DEFAULT_PERMISSIONS);
        $this->assertNotContains('ci.roles.manage', $defaultKeys, "废弃 key 'ci.roles.manage' 不能存在于 DEFAULT_PERMISSIONS");
        // 反射所有 PERM_* 公共常量，确认没有值为 ci.roles.manage
        $r = new \ReflectionClass(AppConfig::class);
        foreach ($r->getConstants(\ReflectionClassConstant::IS_PUBLIC) as $name => $value) {
            if (str_starts_with($name, 'PERM_') && is_string($value)) {
                $this->assertNotSame('ci.roles.manage', $value, "常量 {$name} 不能指向废弃 key 'ci.roles.manage'");
            }
        }
        foreach (AppConfig::IMPLIED_PERMISSIONS as $src => $targets) {
            $this->assertNotSame('ci.roles.manage', $src, "IMPLIED 源 key 不能是废弃的 'ci.roles.manage'");
            $this->assertNotContains('ci.roles.manage', $targets, "IMPLIED 目标 key 里不能包含废弃的 'ci.roles.manage'");
        }
    }

    public function testPermissionsManagementOneParentThreeChildren(): void
    {
        $defaults = AppConfig::DEFAULT_PERMISSIONS;
        $defaultKeys = array_keys($defaults);
        $children = [
            AppConfig::PERM_CI_PERMISSIONS_LIST,
            AppConfig::PERM_CI_PERMISSIONS_REGISTER,
            AppConfig::PERM_CI_PERMISSIONS_RULES,
        ];
        // 1) 四个权限都在白名单里
        $this->assertContains(AppConfig::PERM_CI_PERMISSIONS_MANAGE, $defaultKeys, 'ci.permissions.manage 必须在 DEFAULT_PERMISSIONS');
        foreach ($children as $c) $this->assertContains($c, $defaultKeys, "子权限 '{$c}' 必须在 DEFAULT_PERMISSIONS");
        // 2) 父级 parent_key 为 null；三个子级 parent_key 均为 ci.permissions.manage
        $this->assertNull($this->extractParent($defaults, AppConfig::PERM_CI_PERMISSIONS_MANAGE), 'ci.permissions.manage parent_key 必须为 null');
        foreach ($children as $c) {
            $this->assertSame(AppConfig::PERM_CI_PERMISSIONS_MANAGE, $this->extractParent($defaults, $c), "子权限 '{$c}' 的 parent_key 必须是 ci.permissions.manage");
        }
        // 3) 三个子权限都定义了子 → 父隐含关系
        foreach ($children as $c) {
            $this->assertArrayHasKey($c, AppConfig::IMPLIED_PERMISSIONS, "子权限 '{$c}' 必须在 IMPLIED_PERMISSIONS 中定义子→父隐含");
        }
    }

    public function testCiUsersManageAdminStillCoversRoles(): void
    {
        // 撤销 ci.roles.manage 后，ci.users.manage_admin 必须：仍在 DEFAULT_PERMISSIONS，parent 指向 ci.users.manage
        $defaults = AppConfig::DEFAULT_PERMISSIONS;
        $this->assertArrayHasKey(AppConfig::PERM_CI_USERS_MANAGE_ADMIN, $defaults, 'ci.users.manage_admin 必须存在（已恢复上一版）');
        $this->assertSame(AppConfig::PERM_CI_USERS_MANAGE, $this->extractParent($defaults, AppConfig::PERM_CI_USERS_MANAGE_ADMIN), 'ci.users.manage_admin parent 必须是 ci.users.manage');
    }

    public function testNoDuplicatePermissionsInDefaults(): void
    {
        $keys = array_keys(AppConfig::DEFAULT_PERMISSIONS);
        $this->assertCount(count(array_unique($keys)), $keys, 'DEFAULT_PERMISSIONS 里不能有重复 key');
    }

    private function extractParent(array $defaults, string $key): mixed
    {
        $def = $defaults[$key];
        if (is_array($def)) return $def['parent'] ?? null;
        return null;
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
