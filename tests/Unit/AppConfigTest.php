<?php
declare(strict_types=1);

namespace App\Test\Unit;

use App\Config\AppConfig;
use PHPUnit\Framework\TestCase;

/**
 * AppConfig 常量目录测试（纯常量类，不允许实例化、不含 IO）。
 *
 * 配置读取行为见 SettingsTest；DB 设置行为见 AppSettingRepositoryTest。
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
        $modes = [AppConfig::BUILD_MODE_JENKINS, AppConfig::BUILD_MODE_GITLAB_CI, AppConfig::BUILD_MODE_GITEA_CI, AppConfig::BUILD_MODE_BOTH];
        $this->assertCount(4, array_unique($modes));
    }

    public function testProviderConstantsMatchBuildModes(): void
    {
        $this->assertEquals(AppConfig::BUILD_MODE_JENKINS, AppConfig::PROVIDER_JENKINS);
        $this->assertEquals(AppConfig::BUILD_MODE_GITLAB_CI, AppConfig::PROVIDER_GITLAB_CI);
        $this->assertEquals(AppConfig::BUILD_MODE_GITEA_CI, AppConfig::PROVIDER_GITEA_CI);
    }

    public function testBuiltinPullProvidersContainThreeSources(): void
    {
        $this->assertSame(
            [AppConfig::PROVIDER_JENKINS, AppConfig::PROVIDER_GITLAB_CI, AppConfig::PROVIDER_GITEA_CI],
            AppConfig::BUILTIN_PULL_PROVIDERS,
            '内置拉取式 provider 集合必须是 jenkins/gitlab_ci/gitea_ci 且顺序稳定'
        );
    }

    public function testCustomPushConstants(): void
    {
        $this->assertEquals('custom_push', AppConfig::PROVIDER_CUSTOM_PUSH);
        $this->assertEquals('ci_custom_builds', AppConfig::TABLE_CUSTOM_BUILDS);
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
        $this->assertNotEmpty(AppConfig::TABLE_API_ACCESS_LOGS);
        $this->assertNotEmpty(AppConfig::TABLE_PIPELINE_ARTIFACTS);
        $this->assertNotEmpty(AppConfig::TABLE_SECURITY_CHECKS);
        $this->assertNotEmpty(AppConfig::TABLE_PLATFORM_VERSIONS);
        $this->assertNotEmpty(AppConfig::TABLE_CUSTOM_BUILDS);
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

    public function testAppConfigHasNoConstructor(): void
    {
        // 纯常量类：不允许再挂实例状态/IO，职责已拆到 Settings / *Repository / MappingManager
        $this->assertFalse(
            (new \ReflectionClass(AppConfig::class))->hasMethod('__construct'),
            'AppConfig 必须是纯常量类，不能有构造函数'
        );
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
        $this->assertTrue(true, "未发现非法隐含循环（已检查 {$checkedPairs} 对双向边）");
    }

    public function testParentChildRelationship(): void
    {
        $implied = AppConfig::IMPLIED_PERMISSIONS;
        $this->assertArrayHasKey(AppConfig::PERM_CD_BUILD, $implied, 'cd.build-manage 必须有隐含子权限');
        $this->assertContains(AppConfig::PERM_CI_TRIGGER, $implied[AppConfig::PERM_CD_BUILD], 'cd.build-manage 必须隐含 ci.trigger');
        $this->assertContains(AppConfig::PERM_CI_USERS_MANAGE, $implied[AppConfig::PERM_CI_USERS_LIST], 'ci.users.list 必须隐含 ci.users.manage');
        $this->assertContains(AppConfig::PERM_CI_USERS_MANAGE, $implied[AppConfig::PERM_CI_USERS_PASSWORD], 'ci.users.password 必须隐含 ci.users.manage');
        $this->assertContains(AppConfig::PERM_CI_USERS_MANAGE, $implied[AppConfig::PERM_CI_USERS_MANAGE_ADMIN], 'ci.users.manage_admin 必须隐含 ci.users.manage');
        $this->assertArrayHasKey(AppConfig::PERM_CI_PERMISSIONS_LIST, $implied);
        $this->assertArrayHasKey(AppConfig::PERM_CI_PERMISSIONS_REGISTER, $implied);
        $this->assertArrayHasKey(AppConfig::PERM_CI_PERMISSIONS_RULES, $implied);
        $this->assertContains(AppConfig::PERM_CI_PERMISSIONS_MANAGE, $implied[AppConfig::PERM_CI_PERMISSIONS_LIST], 'ci.permissions.list 必须隐含 ci.permissions.manage');
        $this->assertContains(AppConfig::PERM_CI_PERMISSIONS_MANAGE, $implied[AppConfig::PERM_CI_PERMISSIONS_REGISTER], 'ci.permissions.register 必须隐含 ci.permissions.manage');
        $this->assertContains(AppConfig::PERM_CI_PERMISSIONS_MANAGE, $implied[AppConfig::PERM_CI_PERMISSIONS_RULES], 'ci.permissions.rules 必须隐含 ci.permissions.manage');
        // 新增：API 调用日志 → 日志中心父菜单
        $this->assertContains(AppConfig::PERM_CI_LOGS, $implied[AppConfig::PERM_CI_API_LOGS], 'ci.api-logs 必须隐含 ci.logs');
    }

    public function testApiLogsPermissionRegistered(): void
    {
        $this->assertSame(
            AppConfig::PERM_CI_LOGS,
            AppConfig::DEFAULT_PERMISSIONS[AppConfig::PERM_CI_API_LOGS]['parent'] ?? null,
            'ci.api-logs 必须注册在日志中心下'
        );
        $this->assertContains(
            AppConfig::PERM_CI_API_LOGS,
            AppConfig::DEFAULT_ROLES[AppConfig::ROLE_VIEWER],
            'viewer 默认角色应含 ci.api-logs（只读审计）'
        );
    }

    // ── 权限 key 结构一致性 ──

    public function testDeprecatedRolesManageKeyIsGone(): void
    {
        $defaultKeys = array_keys(AppConfig::DEFAULT_PERMISSIONS);
        $this->assertNotContains('ci.roles.manage', $defaultKeys, "废弃 key 'ci.roles.manage' 不能存在于 DEFAULT_PERMISSIONS");
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
        $this->assertContains(AppConfig::PERM_CI_PERMISSIONS_MANAGE, $defaultKeys, 'ci.permissions.manage 必须在 DEFAULT_PERMISSIONS');
        foreach ($children as $c) $this->assertContains($c, $defaultKeys, "子权限 '{$c}' 必须在 DEFAULT_PERMISSIONS");
        $this->assertNull($this->extractParent($defaults, AppConfig::PERM_CI_PERMISSIONS_MANAGE), 'ci.permissions.manage parent_key 必须为 null');
        foreach ($children as $c) {
            $this->assertSame(AppConfig::PERM_CI_PERMISSIONS_MANAGE, $this->extractParent($defaults, $c), "子权限 '{$c}' 的 parent_key 必须是 ci.permissions.manage");
        }
        foreach ($children as $c) {
            $this->assertArrayHasKey($c, AppConfig::IMPLIED_PERMISSIONS, "子权限 '{$c}' 必须在 IMPLIED_PERMISSIONS 中定义子→父隐含");
        }
    }

    public function testCiUsersManageAdminStillCoversRoles(): void
    {
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
}
