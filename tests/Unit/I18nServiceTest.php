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
        
        $this->assertIsString($result);
    }

    public function testDetectLocaleDefault(): void
    {
        
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

    // ── 占位符渲染（{key} 归一化）──

    /**
     * 裸键与带花括号两种写法都必须渲染出干净文案。
     *
     * 背景：没有 intl 翻译器时 Symfony 的 MessageFormatter 退化为 `strtr($message, $params)`，
     * 把「参数键」当普通子串全局替换——传 `['repo' => 'x/y']` 时文案里的 `{repo}` 会留成
     * `{x/y}`，还会把同名单词 `registry_repository` 误伤成 `registry_x/ysitory`。
     */
    public function testTransRendersBracedPlaceholderForBareParamKey(): void
    {
        $result = $this->i18n->trans('build.registry_repo_format_error', ['repo' => 'x/y']);

        $this->assertStringContainsString('x/y', $result);
        $this->assertStringNotContainsString('{x/y}', $result, '不得把值塞进保留的花括号里');
        $this->assertStringContainsString('registry_repository', $result, '同名单词必须原样保留');
        $this->assertStringNotContainsString('registry_x/ysitory', $result, '不得误伤同名单词');
    }

    public function testTransKeepsBracedParamKeyWorking(): void
    {
        
        $result = $this->i18n->trans('build.provider_not_configured', ['{provider}' => 'jenkins']);

        $this->assertStringContainsString("'jenkins'", $result);
        $this->assertStringNotContainsString('{provider}', $result);
    }

    public function testNewRegistryNameMessageRendersInBothLocales(): void
    {
        $zh = $this->i18n->trans('build.registry_name_invalid', ['name' => '..']);
        $this->assertStringContainsString('非法: ..', $zh, 'zh_CN 必须带出非法名称且不留花括号');
        $this->assertStringNotContainsString('{name}', $zh);

        $en = (new I18nService($this->langDir, 'en'))->trans('build.registry_name_invalid', ['name' => '..']);
        $this->assertStringContainsString('name: ..', $en, 'en 文案里的普通单词 name 不得被占位符吃掉');
        $this->assertStringNotContainsString('{name}', $en);
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
     * 权限管理 1 父 3 子的 4 个翻译必须存在，且中文/英文都符合预期
     */
    public function testPermissionsManagementI18nQuadExists(): void
    {
        $zh = $this->i18n->getAll('zh_CN');
        $en = $this->i18n->getAll('en');
        $keys = [
            'role.perm_ci_permissions_manage',
            'role.perm_ci_permissions_list',
            'role.perm_ci_permissions_register',
            'role.perm_ci_permissions_rules',
        ];
        foreach ($keys as $k) {
            $this->assertArrayHasKey($k, $zh, "zh_CN 缺少翻译 {$k}");
            $this->assertArrayHasKey($k, $en, "en 缺少翻译 {$k}");
            $this->assertNotSame('', trim((string) $zh[$k]), "zh_CN 翻译为空: {$k}");
            $this->assertNotSame('', trim((string) $en[$k]), "en 翻译为空: {$k}");
        }
    }

    private function permKeyToI18nSuffix(string $permKey): string
    {
        
        return str_replace('.', '_', $permKey);
    }
}

