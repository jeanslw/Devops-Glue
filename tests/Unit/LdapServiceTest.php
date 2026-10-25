<?php
declare(strict_types=1);

namespace App\Test\Unit;

use App\Service\LdapService;
use App\Service\Settings;
use PHPUnit\Framework\TestCase;

/**
 * LdapService 单测 — 不依赖真实 LDAP 服务器与 ext-ldap 扩展。
 *
 * 覆盖两类无需 mock 即可测的逻辑：
 *   1) 纯函数：escapeFilter / escapeDn（LDAP 注入防护，安全性关键路径）
 *   2) 未启用分支：未装扩展或配置未启用时的短路返回
 *
 * 完整 bind 流程（connect/search/read）需要真实 ext-ldap 或 mock 掉 ldap_* 全局函数，
 * 见 AdminAuthService 通过构造注入 ?LdapService 即可在无 LDAP 时走本地 DB 登录。
 */
class LdapServiceTest extends TestCase
{
    public function testEscapeFilterNeutralizesLdapInjection(): void
    {
        
        $this->assertSame('\2a', LdapService::escapeFilter('*'));
        $this->assertSame('\28', LdapService::escapeFilter('('));
        $this->assertSame('\29', LdapService::escapeFilter(')'));
        $this->assertSame('\5c', LdapService::escapeFilter('\\'));
        $this->assertSame('\00', LdapService::escapeFilter("\x00"));
        
        $this->assertSame('admin\2a\29\28uid=\2a', LdapService::escapeFilter('admin*)(uid=*'));
    }

    public function testEscapeDnNeutralizesDnInjection(): void
    {
        
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
        $service = new LdapService(new Settings([]));

        
        $this->assertSame(['ok' => false, 'error' => 'empty_credentials'], $service->authenticate('', ''));
        $this->assertSame(['ok' => false, 'error' => 'empty_credentials'], $service->authenticate('user', ''));
        $this->assertSame(['ok' => false, 'error' => 'empty_credentials'], $service->authenticate('', 'pass'));
    }

    public function testIsEnabledFalseWhenConfigDisabled(): void
    {
        $service = new LdapService(new Settings([])); 
        $this->assertFalse($service->isEnabled());
    }

    public function testIsEnabledFalseWhenExtensionNotLoaded(): void
    {
        if (extension_loaded('ldap')) {
            $this->markTestSkipped('ext-ldap 已加载，无法覆盖「未安装扩展」降级分支');
        }

        
        
        $service = new LdapService(new Settings(['ldap' => ['enabled' => true, 'host' => 'ldap.example.com']]));

        $this->assertFalse($service->isAvailable());
        $this->assertFalse($service->isEnabled());
        $this->assertSame(
            ['ok' => false, 'error' => 'ldap_extension_missing'],
            $service->authenticate('user', 'pass')
        );
    }
}
