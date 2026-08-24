<?php
declare(strict_types=1);

namespace App\Test\Unit;

use App\Config\AppConfig;
use App\Service\LdapService;
use PHPUnit\Framework\TestCase;

/**
 * LdapService 单测 — 不依赖真实 LDAP 服务器 / ext-ldap 扩展。
 *
 * 覆盖两类「无需 mock 即可测」的逻辑：
 *   1) 纯函数：escapeFilter / escapeDn（LDAP 注入防护，安全性关键路径）
 *   2) 降级分支：未装扩展 / 配置未启用时的短路返回（用户当前正是「不用 LDAP」场景）
 *
 * 完整 bind 流程（connect/search/read）需要真实 ext-ldap 或 mock 掉 ldap_* 全局函数，
 * 见 AdminAuthService 通过构造注入 ?LdapService 即可在无 LDAP 时走本地 DB 登录。
 */
class LdapServiceTest extends TestCase
{
    public function testEscapeFilterNeutralizesLdapInjection(): void
    {
        // LDAP filter 注入字符 * ( ) \ NUL 必须被转义，否则 '(uid=*)' 会被当成通配/子过滤器
        $this->assertSame('\2a', LdapService::escapeFilter('*'));
        $this->assertSame('\28', LdapService::escapeFilter('('));
        $this->assertSame('\29', LdapService::escapeFilter(')'));
        $this->assertSame('\5c', LdapService::escapeFilter('\\'));
        $this->assertSame('\00', LdapService::escapeFilter("\x00"));
        // 组合场景：'=' 不属于 filter 注入字符，保持原样
        $this->assertSame('admin\2a\29\28uid=\2a', LdapService::escapeFilter('admin*)(uid=*'));
    }

    public function testEscapeDnNeutralizesDnInjection(): void
    {
        // DN 注入字符 , = + < > # ; \ " 必须被转义，防止拼接 user_dn_pattern 时注入额外的 DN 分支
        $this->assertSame('\2c', LdapService::escapeDn(','));
        $this->assertSame('\3d', LdapService::escapeDn('='));
        $this->assertSame('\2b', LdapService::escapeDn('+'));
        $this->assertSame('\3c', LdapService::escapeDn('<'));
        $this->assertSame('\3e', LdapService::escapeDn('>'));
        $this->assertSame('\23', LdapService::escapeDn('#'));
        $this->assertSame('\3b', LdapService::escapeDn(';'));
        $this->assertSame('\5c', LdapService::escapeDn('\\'));
        $this->assertSame('\22', LdapService::escapeDn('"'));
        $this->assertSame('cn\3dadmin\2cou\3dusers', LdapService::escapeDn('cn=admin,ou=users'));
    }

    public function testAuthenticateReturnsEmptyCredentialsForBlankInput(): void
    {
        $service = new LdapService(new AppConfig([]));

        // 空凭据在 isAvailable() 之前短路，无需扩展即可断言
        $this->assertSame(['ok' => false, 'error' => 'empty_credentials'], $service->authenticate('', ''));
        $this->assertSame(['ok' => false, 'error' => 'empty_credentials'], $service->authenticate('user', ''));
        $this->assertSame(['ok' => false, 'error' => 'empty_credentials'], $service->authenticate('', 'pass'));
    }

    public function testIsEnabledFalseWhenConfigDisabled(): void
    {
        $service = new LdapService(new AppConfig([])); // 未配置 ldap.enabled → getLdapConfig() = ['enabled' => false]
        $this->assertFalse($service->isEnabled());
    }

    public function testIsEnabledFalseWhenExtensionNotLoaded(): void
    {
        if (extension_loaded('ldap')) {
            $this->markTestSkipped('ext-ldap 已加载，无法覆盖「未安装扩展」降级分支');
        }

        // 配置 enabled=true 但扩展缺失：isEnabled 必须为 false，authenticate 直接返回 extension_missing，
        // 绝不尝试 ldap_connect（这正是「不用 LDAP 也能安全运行」的契约）。
        $service = new LdapService(new AppConfig(['ldap' => ['enabled' => true, 'host' => 'ldap.example.com']]));

        $this->assertFalse($service->isAvailable());
        $this->assertFalse($service->isEnabled());
        $this->assertSame(
            ['ok' => false, 'error' => 'ldap_extension_missing'],
            $service->authenticate('user', 'pass')
        );
    }
}
