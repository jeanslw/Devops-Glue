<?php

declare(strict_types=1);

namespace App\Test\Unit;

use App\Controller\MainController;
use PHPUnit\Framework\TestCase;

/**
 * /api/health（MainController::healthDetail）三条外部探测的「可达口径」接线测试。
 *
 * 症状：Jenkins 内部故障返回 HTTP 500 时 checks.jenkins 仍为 true。
 * 根因：探测客户端以 http_errors=false 构造，500 不抛异常，探测闭包只看
 * 「请求发出即算成功」，未依据状态码判定；而同一方法内的 Git 平台探测与
 * 镜像仓库探测都以「状态码 < 500」判可达 —— 三处口径必须一致，
 * 否则 degraded（503）永远只在 Git / 仓库故障时触发。
 *
 * 探测客户端在控制器内联构造（无法注入 Guzzle mock handler），
 * 因此这里钉住源码形态，并用反射验证 probeWithRetry 的重试语义。
 */
class HealthCheckProbeWiringTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    private function read(string $relative): string
    {
        $content = file_get_contents(self::ROOT . '/' . $relative);
        $this->assertIsString($content, "{$relative} 必须存在");

        return $content;
    }

    /** Jenkins 探测分支源码（healthDetail 内的 build_mode 判定到 else 之间） */
    private function jenkinsProbeBlock(): string
    {
        $src = $this->read('src/Controller/MainController.php');

        $fnPos = strpos($src, 'public function healthDetail(');
        $this->assertIsInt($fnPos, 'MainController 必须有 healthDetail');

        // 从 healthDetail 起找，避免命中 jobsList 等更早出现的同名常量
        $start = strpos($src, 'AppConfig::PROVIDER_JENKINS', $fnPos);
        $this->assertIsInt($start, 'healthDetail 必须按 build_modes 决定是否探测 Jenkins');

        $end = strpos($src, '} else {', $start);
        $this->assertIsInt($end, 'Jenkins 分支后应有 else（未启用 Jenkins 时 jenkins = null）');
        $this->assertLessThan(
            strpos($src, 'public function healthStatic('),
            $end,
            '取到的必须是 healthDetail 内的 Jenkins 分支'
        );

        return substr($src, $start, $end - $start);
    }

    public function testJenkinsProbeTreats5xxAsUnreachable(): void
    {
        $block = $this->jenkinsProbeBlock();

        $this->assertStringContainsString(
            'getStatusCode()',
            $block,
            'Jenkins 探测必须读响应状态码：http_errors=false 下 500 不会抛异常'
        );
        $this->assertMatchesRegularExpression(
            '/\$httpCode\s*>\s*0\s*&&\s*\$httpCode\s*<\s*500/',
            $block,
            '5xx 及以上必须判为不可达，与 Git / 镜像仓库探测口径一致'
        );
        $this->assertStringContainsString(
            'throw new \\RuntimeException',
            $block,
            '不可达要抛异常，交给 probeWithRetry 重试并由 catch 判 checks.jenkins = false'
        );

        $checkPos  = strpos($block, '$reachable = $httpCode > 0 && $httpCode < 500;');
        $assignPos = strpos($block, "\$checks['jenkins'] = true;");
        $this->assertIsInt($checkPos, '必须真的用状态码判定，而不是读了状态码却不用');
        $this->assertIsInt($assignPos);
        $this->assertLessThan(
            $assignPos,
            $checkPos,
            '顺序有意义：先判可达再置 jenkins = true，否则 5xx 仍会被当作健康'
        );
    }

    public function testGitAndRegistryProbesKeepTheFiveHundredThreshold(): void
    {
        $src = $this->read('src/Controller/MainController.php');

        $this->assertStringContainsString(
            '$reachable = $httpCode > 0 && $httpCode < 500;',
            $src,
            'Git 平台探测的 5xx 口径（HEAD 请求）不能退化'
        );
        $this->assertStringContainsString(
            '$code >= 200 && $code < 500',
            $src,
            '镜像仓库组件探测的 5xx 口径不能退化'
        );
    }

    public function testDegradedStatusStillDependsOnTheJenkinsFlag(): void
    {
        $src = $this->read('src/Controller/MainController.php');

        $this->assertMatchesRegularExpression(
            "/\\\$allOk\s*=\s*\(\\\$checks\['jenkins'\]\s*===\s*true\s*\|\|\s*\\\$checks\['jenkins'\]\s*===\s*null\)/",
            $src,
            'degraded / 503 的汇总判定必须依赖 jenkins 结果（null = 未启用 Jenkins，跳过）'
        );
    }

    public function testProbeWithRetryRetriesOnceThenPropagatesTheFailure(): void
    {
        $ctrl   = (new \ReflectionClass(MainController::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(MainController::class, 'probeWithRetry');

        $attempts = 0;
        try {
            $method->invoke($ctrl, function () use (&$attempts): void {
                $attempts++;
                throw new \RuntimeException('HTTP 500');
            });
            $this->fail('探测始终失败时必须抛给调用方，检查才会判为不可达');
        } catch (\RuntimeException $e) {
            $this->assertSame('HTTP 500', $e->getMessage(), '异常原样抛出，由 catch 记日志');
        }
        $this->assertSame(2, $attempts, '失败重试一次（retries=1），避免瞬时抖动误报不可达');

        $ok = 0;
        $method->invoke($ctrl, function () use (&$ok): void {
            $ok++;
        });
        $this->assertSame(1, $ok, '首次即成功时不再重试');
    }
}
