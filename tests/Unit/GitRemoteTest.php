<?php

declare(strict_types=1);

namespace App\Test\Unit;

use App\Helper\GitRemote;
use PHPUnit\Framework\TestCase;

class GitRemoteTest extends TestCase
{
    /**
     * @dataProvider remoteProvider
     */
    public function testExtractPath(string $remote, ?string $expected): void
    {
        $this->assertSame($expected, GitRemote::extractPath($remote), "remote: {$remote}");
    }

    public static function remoteProvider(): array
    {
        return [
            // ── 常规两段路径 ──
            'https 带 .git'      => ['https://gitlab.com/acme/app.git', 'acme/app'],
            'https 不带 .git'    => ['https://gitlab.com/acme/app', 'acme/app'],
            'http'               => ['http://gitlab.internal/acme/app.git', 'acme/app'],
            'git 协议'           => ['git://gitlab.com/acme/app.git', 'acme/app'],
            'scp 形式'           => ['git@gitlab.com:acme/app.git', 'acme/app'],
            'IP 地址 host'       => ['https://10.0.0.5/team/repo.git', 'team/repo'],

            // ── GitLab 子群组：改动前会被截断成最后两段 ──
            'https 一级子群组'   => ['https://gitlab.com/acme/infra/app.git', 'acme/infra/app'],
            'https 二级子群组'   => ['https://gitlab.com/acme/infra/platform/app.git', 'acme/infra/platform/app'],
            'scp 子群组'         => ['git@gitlab.com:acme/infra/app.git', 'acme/infra/app'],
            'ssh 子群组'         => ['ssh://git@gitlab.com/acme/infra/app.git', 'acme/infra/app'],

            // ── ssh:// 带端口：改动前端口会混入路径（2222/acme/app）──
            'ssh 带端口'         => ['ssh://git@gitlab.com:2222/acme/app.git', 'acme/app'],
            'ssh 带端口+子群组'  => ['ssh://git@gitlab.com:2222/acme/infra/app.git', 'acme/infra/app'],

            // ── 大小写必须原样保留（GitLab API 以路径原文定位项目）──
            '保留大小写'         => ['https://gitlab.com/Acme/MyApp.git', 'Acme/MyApp'],

            // ── 格式清理 ──
            '尾部斜杠'           => ['https://gitlab.com/acme/app/', 'acme/app'],
            '首尾空白'           => ['  https://gitlab.com/acme/app.git  ', 'acme/app'],
            '大写 .GIT 后缀'     => ['https://gitlab.com/acme/app.GIT', 'acme/app'],

            // ── 无效输入 → null，由调用方兜底 ──
            '空串'               => ['', null],
            '纯空白'             => ['   ', null],
            '只有 host'          => ['https://gitlab.com', null],
            '不足两段'           => ['https://gitlab.com/app.git', null],
        ];
    }

    /**
     * 同一仓库经 https 与 ssh 两种协议接入，必须解析出同一路径。
     * 这是 CI→CD 链路 join 的前提。
     */
    public function testHttpsAndSshAgreeOnSameRepo(): void
    {
        $https = GitRemote::extractPath('https://gitlab.com/acme/infra/app.git');
        $ssh   = GitRemote::extractPath('ssh://git@gitlab.com:2222/acme/infra/app.git');
        $scp   = GitRemote::extractPath('git@gitlab.com:acme/infra/app.git');

        $this->assertSame('acme/infra/app', $https);
        $this->assertSame($https, $ssh);
        $this->assertSame($https, $scp);
    }
}
