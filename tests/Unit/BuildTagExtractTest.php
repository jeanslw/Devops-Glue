<?php
declare(strict_types=1);

namespace App\Test\Unit;

use App\Controller\BuildController;
use PHPUnit\Framework\TestCase;

/**
 * BuildController::extractTagFromTrace / pickTag 回归测试
 *
 * 当日志里先出现上一条构建的 tag（v20260827-129）、后出现当前构建的 tag（v20260827-130）时，
 * 必须优先返回与当前 pipelineId 尾号一致的那条，避免镜像 Tag 列显示上一条构建的 tag。
 */
class BuildTagExtractTest extends TestCase
{
    /** 反射调用私有方法 extractTagFromTrace（纯函数，无需构造依赖） */
    private function extract(string $trace, string $harborRepo, string $keyword, int $pipelineId): string
    {
        $m = new \ReflectionMethod(BuildController::class, 'extractTagFromTrace');
        $m->setAccessible(true);
        return (string) $m->invokeArgs(
            (new \ReflectionClass(BuildController::class))->newInstanceWithoutConstructor(),
            [$trace, $harborRepo, $keyword, $pipelineId]
        );
    }

    public function testPrefersCurrentPipelineTagOverPrevious(): void
    {
        $trace = "previous image mycode/runner-ci:v20260827-129\n"
            . "digest: sha256:abc pushing mycode/runner-ci:v20260827-130";
        $this->assertSame(
            'v20260827-130',
            $this->extract($trace, 'mycode/runner-ci', 'digest', 130)
        );
    }

    public function testFallsBackToFirstWhenNoPipelineMatch(): void
    {
        $trace = "mycode/runner-ci:v1.0.0\ndigest: sha256:abc mycode/runner-ci:latest";
        $this->assertSame(
            'v1.0.0',
            $this->extract($trace, 'mycode/runner-ci', 'digest', 999)
        );
    }

    public function testKeywordGateStillFilters(): void
    {
        $trace = "mycode/runner-ci:v20260827-130"; // 无 digest 关键字 → 视为非推送日志
        $this->assertSame('', $this->extract($trace, 'mycode/runner-ci', 'digest', 130));
    }

    /** 不符合 Docker tag 规范的取值须丢弃：${TAG} 这类未展开变量不得充当 tag */
    public function testDropsUnsanitizedCandidates(): void
    {
        $trace = 'digest: sha256:abc mycode/runner-ci:${TAG}' . "\n"
            . 'docker push mycode/runner-ci:latest';
        $this->assertSame('latest', $this->extract($trace, 'mycode/runner-ci', 'digest', 0));
    }

    /** 超过 Docker tag 上限（128 字符）的取值将被丢弃 */
    public function testRejectsOverLongTag(): void
    {
        $long  = str_repeat('a', 200);
        $trace = "digest: sha256:abc mycode/runner-ci:{$long}";
        $this->assertSame('', $this->extract($trace, 'mycode/runner-ci', 'digest', 0));
    }
}
