<?php

declare(strict_types=1);

namespace App\Test\Unit;

use App\Helper\ClientIp;
use PHPUnit\Framework\TestCase;

/**
 * ClientIp 安全语义回归（必须与 Devops_CD backend/login_guard.py::client_ip 一致）：
 *   - hops=0 永远只信 REMOTE_ADDR，XFF 被忽略；
 *   - hops>0 仅当对端是内网反代时，从 XFF 右端倒数取地址；
 *   - 直连公网客户端即便带 XFF 也不采信；
 *   - XFF 过短 / 含非法 IP 时回退 REMOTE_ADDR。
 */
class ClientIpTest extends TestCase
{
    public function testHopsZeroIgnoresXffEvenFromLoopback(): void
    {
        $server = [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '1.2.3.4',
        ];
        $this->assertSame('127.0.0.1', ClientIp::resolve($server, 0));
    }

    public function testHopsZeroIgnoresSpoofedXffFromPublicPeer(): void
    {
        $server = [
            'REMOTE_ADDR' => '8.8.8.8',
            'HTTP_X_FORWARDED_FOR' => '10.0.0.1',
        ];
        $this->assertSame('8.8.8.8', ClientIp::resolve($server, 1));
    }

    public function testOneHopFromLoopbackTakesRightmostEntry(): void
    {
        $server = [
            'REMOTE_ADDR' => '127.0.0.1',
            // 左端是攻击者可伪造的内容，真实客户端由反代追加在链尾
            'HTTP_X_FORWARDED_FOR' => '1.2.3.4, 198.51.100.9',
        ];
        $this->assertSame('198.51.100.9', ClientIp::resolve($server, 1));
    }

    public function testOneHopSingleEntry(): void
    {
        $server = [
            'REMOTE_ADDR' => '10.0.0.254',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.9',
        ];
        $this->assertSame('198.51.100.9', ClientIp::resolve($server, 1));
    }

    public function testTwoHopsTakesSecondFromRight(): void
    {
        $server = [
            'REMOTE_ADDR' => '172.16.0.1',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.9, 10.0.0.2, 10.0.0.1',
        ];
        $this->assertSame('10.0.0.2', ClientIp::resolve($server, 2));
    }

    public function testHopsExceedingChainLengthFallsBackToRemote(): void
    {
        $server = [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.9',
        ];
        $this->assertSame('127.0.0.1', ClientIp::resolve($server, 2));
    }

    public function testInvalidXffEntryFallsBackToRemote(): void
    {
        $server = [
            'REMOTE_ADDR' => '192.168.1.1',
            'HTTP_X_FORWARDED_FOR' => 'not-an-ip',
        ];
        $this->assertSame('192.168.1.1', ClientIp::resolve($server, 1));
    }

    public function testMissingXffFallsBackToRemote(): void
    {
        $this->assertSame('127.0.0.1', ClientIp::resolve(['REMOTE_ADDR' => '127.0.0.1'], 1));
    }

    public function testIpv6LoopbackAndLinkLocalTrustedAsProxy(): void
    {
        $this->assertSame(
            '2001:db8::1',
            ClientIp::resolve([
                'REMOTE_ADDR' => '::1',
                'HTTP_X_FORWARDED_FOR' => '2001:db8::1',
            ], 1)
        );
        $this->assertSame(
            '2001:db8::2',
            ClientIp::resolve([
                'REMOTE_ADDR' => 'fe80::1',
                'HTTP_X_FORWARDED_FOR' => '2001:db8::2',
            ], 1)
        );
    }

    public function testEmptyRemoteReturnsUnknown(): void
    {
        $this->assertSame('unknown', ClientIp::resolve([], 1));
    }

    public function testNegativeHopsTreatedAsZero(): void
    {
        $this->assertSame(
            '127.0.0.1',
            ClientIp::resolve([
                'REMOTE_ADDR' => '127.0.0.1',
                'HTTP_X_FORWARDED_FOR' => '1.2.3.4',
            ], -1)
        );
    }
}
