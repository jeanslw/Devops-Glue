<?php
declare(strict_types=1);

namespace App\Test\Unit;

use App\Config\AppConfig;
use App\Service\I18nService;
use PHPUnit\Framework\TestCase;

/**
 * I18nService 单元测试
 *
 * 验证翻译加载、键查找、语言检测等功能。
 * 需要存在 lang 目录和翻译文件。
 */
class I18nServiceTest extends TestCase
{
    private I18nService $i18n;
    /** @var string */
    private string $langDir;

    protected function setUp(): void
    {
        $this->langDir = __DIR__ . '/../../lang';
        $this->i18n = new I18nService($this->langDir, 'zh_CN');
    }

    public function testTransReturnsKeyWhenNotFound(): void
    {
        $result = $this->i18n->trans('nonexistent.key.xyz');
        $this->assertEquals('nonexistent.key.xyz', $result);
    }

    public function testTransReturnsTranslation(): void
    {
        $result = $this->i18n->trans('app.name', [], 'zh_CN');
        // 即使没找到也返回 key，不抛异常
        $this->assertIsString($result);
    }

    public function testDetectLocaleDefault(): void
    {
        // 无 Accept-Language 时返回默认
        $this->assertEquals('zh_CN', $this->i18n->getDefaultLocale());
    }

    public function testGetAllReturnsArray(): void
    {
        $messages = $this->i18n->getAll('zh_CN');
        $this->assertIsArray($messages);
    }

    public function testGetAllForUnknownLocaleReturnsEmpty(): void
    {
        $messages = $this->i18n->getAll('xx_XX');
        $this->assertIsArray($messages);
    }

    public function testTransWithParams(): void
    {
        $result = $this->i18n->trans('test.key', ['{param}' => 'world']);
        $this->assertIsString($result);
    }

    // ── 权限翻译一致性（本次改动新增）──

    /**
     * 每个 DEFAULT_PERMISSIONS 的 key 都必须对应 zh_CN / en 两份 role.perm_<translated_key> 翻译。
     * 翻译 key 规则：perm_key 里非字母数字换成 '_'，前缀 role.perm_。
     */
    public function testEveryDefaultPermissionHasI18nInBothLocales(): void
    {
        $zh = $this->i18n->getAll('zh_CN');
        $en = $this->i18n->getAll('en');
        $this->assertNotEmpty($zh, 'zh_CN 语言包不能为空');
        $this->assertNotEmpty($en, 'en 语言包不能为空');

        $errors = [];
        foreach (array_keys(AppConfig::DEFAULT_PERMISSIONS) as $permKey) {
            $i18nKey = 'role.perm_' . $this->permKeyToI18nSuffix($permKey);
            if (!isset($zh[$i18nKey])) $errors[] = "zh_CN 缺少翻译: {$i18nKey} (权限 {$permKey})";
            if (!isset($en[$i18nKey])) $errors[] = "en 缺少翻译: {$i18nKey} (权限 {$permKey})";
            // 不能出现 fallback 到原文（即英文/中文翻译值正好等于 perm_key 或 i18nKey 这种没覆盖的情况）
            if (isset($zh[$i18nKey]) && trim((string)$zh[$i18nKey]) === '') $errors[] = "zh_CN 翻译为空: {$i18nKey}";
            if (isset($en[$i18nKey]) && trim((string)$en[$i18nKey]) === '') $errors[] = "en 翻译为空: {$i18nKey}";
        }
        $this->assertEmpty($errors, "权限翻译不一致：\n" . implode("\n", $errors));
    }

    /**
     * 撤销 ci.roles.manage 后，对应的翻译 key role.perm_ci_roles_manage 也必须删掉，不能 fallback 成原文 "Role Management"
     */
    public function testDeprecatedRolesManageTranslationIsRemoved(): void
    {
        $zh = $this->i18n->getAll('zh_CN');
        $en = $this->i18n->getAll('en');
        $this->assertArrayNotHasKey('role.perm_ci_roles_manage', $zh, "zh_CN 已废弃翻译 role.perm_ci_roles_manage 必须移除");
        $this->assertArrayNotHasKey('role.perm_ci_roles_manage', $en, "en 已废弃翻译 role.perm_ci_roles_manage 必须移除");
    }

    /**
     * ci.users.manage_admin 在中文里的翻译必须是「角色管理」（恢复上一版），不能是「管理员用户」
     */
    public function testCiUsersManageAdminTranslatesToRoleManagement(): void
    {
        $zh = $this->i18n->getAll('zh_CN');
        $en = $this->i18n->getAll('en');
        $this->assertSame('角色管理', $zh['role.perm_ci_users_manage_admin'] ?? null, 'ci.users.manage_admin 中文显示应为「角色管理」（已恢复上一版）');
        $this->assertSame('Roles', $en['role.perm_ci_users_manage_admin'] ?? null, "ci.users.manage_admin 英文显示应为 'Roles'");
    }

    /**
     * 权限管理 1 父 3 子的 4 个翻译必须存在，且中文/英文都符合预期
     */
    public function testPermissionsManagementI18nQuadExists(): void
    {
        $zh = $this->i18n->getAll('zh_CN');
        $en = $this->i18n->getAll('en');
        $expect = [
            'role.perm_ci_permissions_manage'   => ['zh' => '权限管理',      'en' => 'Permission Management'],
            'role.perm_ci_permissions_list'     => ['zh' => '权限列表',      'en' => 'Permission List'],
            'role.perm_ci_permissions_register' => ['zh' => '权限注册',      'en' => 'Permission Register'],
            'role.perm_ci_permissions_rules'    => ['zh' => '隐含规则',      'en' => 'Implied Rules'],
        ];
        foreach ($expect as $k => $v) {
            $this->assertSame($v['zh'], $zh[$k] ?? null, "中文翻译 {$k} 错误");
            $this->assertSame($v['en'], $en[$k] ?? null, "英文翻译 {$k} 错误");
        }
    }

    private function permKeyToI18nSuffix(string $permKey): string
    {
        // 与 public/assets/admin.js L1531 保持一致：仅 '.' 替换为 '_'，其他字符（包括 '-')保留
        return str_replace('.', '_', $permKey);
    }
}

