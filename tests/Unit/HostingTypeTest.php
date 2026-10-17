<?php
declare(strict_types=1);

namespace App\Test\Unit;

use App\Service\Git\HostingType;
use PHPUnit\Framework\TestCase;

/**
 * HostingType 托管类型判定回归测试：按 base_url 域名区分 saas / 自建，不依赖平台名。
 */
class HostingTypeTest extends TestCase
{
    public function testSaasByDomain(): void
    {
        $this->assertSame(HostingType::SAAS, HostingType::classify('https://github.com'));
        $this->assertSame(HostingType::SAAS, HostingType::classify('https://gitee.com/api/v5'));
        $this->assertSame(HostingType::SAAS, HostingType::classify('https://gitlab.com'));
        $this->assertSame(HostingType::SAAS, HostingType::classify('https://bitbucket.org'));
        $this->assertSame(HostingType::SAAS, HostingType::classify('https://gitcode.net'));
    }

    public function testSaasBySubdomain(): void
    {
        // api.github.com / api.gitee.com 是 SaaS 主机的子域，应归入 SaaS
        $this->assertSame(HostingType::SAAS, HostingType::classify('https://api.github.com'));
        $this->assertSame(HostingType::SAAS, HostingType::classify('https://api.gitee.com'));
    }

    public function testSelfHostedByDomainOrIp(): void
    {
        // 自建域名（不以 SaaS 主机精确或子域结尾）与内网 IP 均应判为自建
        $this->assertSame(HostingType::SELF_HOSTED, HostingType::classify('https://gitlab.example.com'));
        $this->assertSame(HostingType::SELF_HOSTED, HostingType::classify('http://10.0.0.5'));
        $this->assertSame(HostingType::SELF_HOSTED, HostingType::classify('https://gitea.corp.local'));
    }

    public function testEmptyUrlIsSelfHosted(): void
    {
        $this->assertSame(HostingType::SELF_HOSTED, HostingType::classify(''));
    }
}
