<?php
declare(strict_types=1);

namespace App\Test\Unit;

use App\Controller\Build\PipelineTagController;
use PHPUnit\Framework\TestCase;

/**
 * PipelineTagController::extractTagFromTrace / pickTag 回归测试
 *
 * 当日志里先出现上一条构建的 tag（v20260827-129）、后出现当前构建的 tag（v20260827-130）时，
 * 必须优先返回与当前 pipelineId 尾号一致的那条，避免镜像 Tag 列显示上一条构建的 tag。
 */
class BuildTagExtractTest extends TestCase
{
    /** 反射调用私有方法 extractTagFromTrace（纯函数，无需构造依赖） */
    private function extract(string $trace, string $harborRepo, string $keyword, int $pipelineId): array
    {
        $m = new \ReflectionMethod(PipelineTagController::class, 'extractTagFromTrace');
        $m->setAccessible(true);
        $res = $m->invokeArgs(
            (new \ReflectionClass(PipelineTagController::class))->newInstanceWithoutConstructor(),
            [$trace, $harborRepo, $keyword, $pipelineId]
        );
        return is_array($res) ? $res : ['tag' => (string) $res, 'digest' => ''];
    }

    public function testPrefersCurrentPipelineTagOverPrevious(): void
    {
        $trace = "previous image mycode/runner-ci:v20260827-129\n"
            . "digest: sha256:abc pushing mycode/runner-ci:v20260827-130";
        $r = $this->extract($trace, 'mycode/runner-ci', 'digest', 130);
        $this->assertSame('v20260827-130', $r['tag']);
        $this->assertSame('', $r['digest']);
    }

    public function testFallsBackToFirstWhenNoPipelineMatch(): void
    {
        $trace = "mycode/runner-ci:v1.0.0\ndigest: sha256:abc mycode/runner-ci:latest";
        $r = $this->extract($trace, 'mycode/runner-ci', 'digest', 999);
        $this->assertSame('v1.0.0', $r['tag']);
        $this->assertSame('', $r['digest']);
    }

    public function testKeywordGateStillFilters(): void
    {
        $trace = "mycode/runner-ci:v20260827-130";
        $r = $this->extract($trace, 'mycode/runner-ci', 'digest', 130);
        $this->assertSame('', $r['tag']);
        $this->assertSame('', $r['digest']);
    }

    /** 不符合 Docker tag 规范的取值须丢弃：${TAG} 这类未展开变量不得充当 tag */
    public function testDropsUnsanitizedCandidates(): void
    {
        $trace = 'digest: sha256:abc mycode/runner-ci:${TAG}' . "\n"
            . 'docker push mycode/runner-ci:latest';
        $r = $this->extract($trace, 'mycode/runner-ci', 'digest', 0);
        $this->assertSame('latest', $r['tag']);
        $this->assertSame('', $r['digest']);
    }

    /** 超过 Docker tag 上限（128 字符）的取值将被丢弃 */
    public function testRejectsOverLongTag(): void
    {
        $long  = str_repeat('a', 200);
        $trace = "digest: sha256:abc mycode/runner-ci:{$long}";
        $r = $this->extract($trace, 'mycode/runner-ci', 'digest', 0);
        $this->assertSame('', $r['tag']);
        $this->assertSame('', $r['digest']);
    }

    /** 日志句末句号属于标点，清洗后还原真实 tag（v1.0. → v1.0） */
    public function testTrimsTrailingSentencePeriod(): void
    {
        $trace = 'digest: sha256:abc Successfully pushed mycode/runner-ci:v1.0.';
        $r = $this->extract($trace, 'mycode/runner-ci', 'digest', 0);
        $this->assertSame('v1.0', $r['tag']);
        $this->assertSame('', $r['digest']);
    }

    /** 推送成功行若含完整 sha256 digest，应一并提取 */
    public function testExtractsDigestFromTrace(): void
    {
        $hex   = str_repeat('a', 64);
        $trace = "digest: sha256:{$hex} mycode/runner-ci:v1.0.0";
        $r = $this->extract($trace, 'mycode/runner-ci', 'digest', 0);
        $this->assertSame('v1.0.0', $r['tag']);
        $this->assertSame('sha256:' . $hex, $r['digest']);
    }
}
