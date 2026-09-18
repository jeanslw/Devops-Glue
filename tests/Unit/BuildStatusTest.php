<?php
declare(strict_types=1);

namespace App\Test\Unit;

use App\Service\Build\BuildStatus;
use PHPUnit\Framework\TestCase;

/**
 * BuildStatus 统一枚举回归测试：锁定三路 CI + custom_push 的原生状态到统一词汇的映射。
 */
class BuildStatusTest extends TestCase
{
    public function testJenkinsStatuses(): void
    {
        $this->assertSame('success', BuildStatus::normalize('SUCCESS'));
        $this->assertSame('failed', BuildStatus::normalize('FAILURE'));
        $this->assertSame('unstable', BuildStatus::normalize('UNSTABLE'));
        $this->assertSame('canceled', BuildStatus::normalize('ABORTED'));
        $this->assertSame('skipped', BuildStatus::normalize('NOT_BUILT'));
        $this->assertSame('unknown', BuildStatus::normalize('UNKNOWN'));
    }

    public function testGitlabStatuses(): void
    {
        $this->assertSame('pending', BuildStatus::normalize('created'));
        $this->assertSame('pending', BuildStatus::normalize('waiting_for_resource'));
        $this->assertSame('pending', BuildStatus::normalize('preparing'));
        $this->assertSame('pending', BuildStatus::normalize('pending'));
        $this->assertSame('running', BuildStatus::normalize('running'));
        $this->assertSame('success', BuildStatus::normalize('success'));
        $this->assertSame('failed', BuildStatus::normalize('failed'));
        $this->assertSame('canceled', BuildStatus::normalize('canceled'));
        $this->assertSame('skipped', BuildStatus::normalize('skipped'));
        $this->assertSame('manual', BuildStatus::normalize('manual'));
        $this->assertSame('pending', BuildStatus::normalize('scheduled'));
    }

    public function testGiteaStatuses(): void
    {
        $this->assertSame('success', BuildStatus::normalize('success'));
        $this->assertSame('failed', BuildStatus::normalize('failure'));
        $this->assertSame('canceled', BuildStatus::normalize('cancelled'));
        $this->assertSame('pending', BuildStatus::normalize('waiting'));
        $this->assertSame('pending', BuildStatus::normalize('blocked'));
        $this->assertSame('running', BuildStatus::normalize('running'));
        $this->assertSame('skipped', BuildStatus::normalize('skipped'));
        $this->assertSame('unknown', BuildStatus::normalize('unknown'));
    }

    public function testCustomPushStatuses(): void
    {
        $this->assertSame('success', BuildStatus::normalize('success'));
        $this->assertSame('failed', BuildStatus::normalize('failed'));
        $this->assertSame('canceled', BuildStatus::normalize('aborted'));
    }

    public function testFallbackAndIdempotent(): void
    {
        // 空串归一为 unknown；未收录的值小写透传
        $this->assertSame('unknown', BuildStatus::normalize(''));
        $this->assertSame('weird_state', BuildStatus::normalize('Weird_State'));

        // 幂等：归一结果再归一不变
        foreach (['success', 'failed', 'unstable', 'canceled', 'running', 'pending', 'manual', 'skipped', 'unknown'] as $canonical) {
            $this->assertSame($canonical, BuildStatus::normalize($canonical));
        }
    }
}
