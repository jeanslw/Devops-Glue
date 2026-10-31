<?php

declare(strict_types=1);

namespace App\Test\Unit;

use App\Service\Build\BuildStatusMachine;
use PHPUnit\Framework\TestCase;

/**
 * 构建状态机纯逻辑测试（无 DB / 无网络）。
 */
class BuildStatusMachineTest extends TestCase
{
    public function testSameStateIsIdempotent(): void
    {
        $this->assertTrue(BuildStatusMachine::canTransition('success', 'success'));
        $this->assertTrue(BuildStatusMachine::canTransition('failed', 'failed'));
        $this->assertTrue(BuildStatusMachine::canTransition('', 'success'));
    }

    public function testSuccessIsIrreversible(): void
    {
        $this->assertFalse(BuildStatusMachine::canTransition('success', 'failed'));
        $this->assertFalse(BuildStatusMachine::canTransition('success', 'aborted'));
        $this->assertFalse(BuildStatusMachine::canTransition('success', 'error'));
        $this->assertFalse(BuildStatusMachine::canTransition('success', 'pending'));
        $this->assertFalse(BuildStatusMachine::canTransition('success', 'running'));
    }

    public function testAnythingCanUpgradeToSuccess(): void
    {
        $this->assertTrue(BuildStatusMachine::canTransition('failed', 'success'));
        $this->assertTrue(BuildStatusMachine::canTransition('aborted', 'success'));
        $this->assertTrue(BuildStatusMachine::canTransition('error', 'success'));
        $this->assertTrue(BuildStatusMachine::canTransition('running', 'success'));
        $this->assertTrue(BuildStatusMachine::canTransition('pending', 'success'));
    }

    public function testNonSuccessTransitionsAllowed(): void
    {
        $this->assertTrue(BuildStatusMachine::canTransition('failed', 'aborted'));
        $this->assertTrue(BuildStatusMachine::canTransition('aborted', 'failed'));
        $this->assertTrue(BuildStatusMachine::canTransition('pending', 'running'));
    }

    public function testIsStaleByTimestamp(): void
    {
        $this->assertTrue(BuildStatusMachine::isStale('2026-01-01 00:00:00', '2026-01-02 00:00:00'));
        $this->assertFalse(BuildStatusMachine::isStale('2026-01-02 00:00:00', '2026-01-01 00:00:00'));
        $this->assertFalse(BuildStatusMachine::isStale('2026-01-01 00:00:00', '2026-01-01 00:00:00'));
    }

    public function testIsStaleFallsBackToStringCompare(): void
    {
        $this->assertTrue(BuildStatusMachine::isStale('abc', 'abd'));
        $this->assertFalse(BuildStatusMachine::isStale('abd', 'abc'));
    }
}
