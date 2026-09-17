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

    // ── normalize()：跨协议去重键（host/org/repo，端口忽略、host 参与比对） ──

    public function testNormalizeSameHostDifferentPortSameKey(): void
    {
        $http = GitRemote::normalize('http://192.168.137.5:3000/jeanslw/Devops_CD.git');
        $ssh  = GitRemote::normalize('ssh://git@192.168.137.5:222/jeanslw/Devops_CD.git');

        $this->assertSame('192.168.137.5/jeanslw/devops_cd', $http);
        $this->assertSame($http, $ssh, '同一 host 仅端口不同（SSH 222 / HTTP 3000）应命中同一仓库');
    }

    public function testNormalizeDifferentHostSamePathNotMerged(): void
    {
        $gitlab = GitRemote::normalize('https://gitlab.example.com/org/repo.git');
        $gitea  = GitRemote::normalize('git@gitea.example.com:org/repo.git');

        $this->assertSame('gitlab.example.com/org/repo', $gitlab);
        $this->assertSame('gitea.example.com/org/repo', $gitea);
        $this->assertNotSame($gitlab, $gitea, 'host 不同、路径相同也不得归一化');
    }

    public function testNormalizeScpStyle(): void
    {
        $this->assertSame('github.com/org/repo', GitRemote::normalize('git@github.com:org/repo.git'));
        $this->assertSame('192.168.10.1/app', GitRemote::normalize('git@192.168.10.1:/app'));
    }

    public function testNormalizeProtocolsSuffixAndCase(): void
    {
        $this->assertSame('github.com/org/repo', GitRemote::normalize('https://github.com/org/repo'));
        $this->assertSame('github.com/org/repo', GitRemote::normalize('https://github.com/org/repo.git'));
        $this->assertSame('github.com/org/repo', GitRemote::normalize('https://GitHub.com/ORG/Repo.git'));
        $this->assertSame('10.0.0.5/team/repo', GitRemote::normalize('https://10.0.0.5:3000/team/repo'));
    }

    public function testNormalizeTrailingSlash(): void
    {
        $this->assertSame('host/org/repo', GitRemote::normalize('https://host/org/repo/'));
    }

    public function testNormalizeEmptyAndBarePath(): void
    {
        $this->assertSame('', GitRemote::normalize(''));
        $this->assertSame('', GitRemote::normalize('   '));
        $this->assertSame('org/repo', GitRemote::normalize('org/repo')); // 裸路径无 host
    }
}
