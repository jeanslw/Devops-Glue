<?php
declare(strict_types=1);

namespace App\Test\Unit;

use App\Service\Build\GiteaCiBuildProvider;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * GiteaCiBuildProvider 回归测试：锁定与 Gitea 1.27 Actions API 响应结构的兼容性。
 * 通过反射把 provider 私有 $http 换成带 MockHandler 的 Guzzle，避免真实网络调用。
 */
class GiteaCiBuildProviderTest extends TestCase
{
    private function providerWith(array $responses, string $token = 'tok'): GiteaCiBuildProvider
    {
        $mock   = new MockHandler($responses);
        $stack  = HandlerStack::create($mock);
        $client = new Client(['handler' => $stack, 'http_errors' => false]);

        $provider = new GiteaCiBuildProvider('https://gitea.example', $token);

        $prop = new ReflectionProperty(GiteaCiBuildProvider::class, 'http');
        $prop->setAccessible(true);
        $prop->setValue($provider, $client);

        return $provider;
    }

    private static function json(string $body, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], $body);
    }

    public function testGetPipelinesParsesGitea127WorkflowRunsObject(): void
    {
        // Gitea 1.27 GET /actions/runs → {total_count, workflow_runs:[...]}（对象，非数组）
        $provider = $this->providerWith([
            self::json(json_encode([
                'total_count'    => 1,
                'workflow_runs'  => [[
                    'id'           => 42,
                    'run_number'   => 7,
                    'status'       => 'success',
                    'head_branch'  => 'release/2.8.2',
                    'head_sha'     => 'abc123',
                    'started_at'   => '2025-04-01T10:00:00Z',
                    'completed_at' => '2025-04-01T10:05:00Z',
                ]],
            ])),
        ]);

        $pipes = $provider->getPipelines('owner/repo');
        $this->assertCount(1, $pipes);
        $this->assertSame(42, $pipes[0]['id']);
        $this->assertSame(7, $pipes[0]['iid']);
        $this->assertSame('success', $pipes[0]['status']);
        $this->assertStringContainsString('/actions/runs/42', $pipes[0]['web_url']);
        // run 对象时间字段是 started_at/completed_at（无 created_at/updated_at），映射到 created_at/updated_at
        $this->assertSame(date('Y-m-d H:i:s', strtotime('2025-04-01T10:00:00Z')), $pipes[0]['created_at']);
        $this->assertSame(date('Y-m-d H:i:s', strtotime('2025-04-01T10:05:00Z')), $pipes[0]['updated_at']);
    }

    public function testGetJobsParsesGitea127JobsObjectAndCompletedAtDuration(): void
    {
        // Gitea 1.27 GET /actions/runs/{run}/jobs → {total_count, jobs:[...]}（对象，非数组）
        // job 完成时间字段是 completed_at（无 stopped_at）
        $provider = $this->providerWith([
            self::json(json_encode([
                'total_count' => 1,
                'jobs'        => [[
                    'id'           => 9,
                    'name'         => 'build',
                    'status'       => 'success',
                    'runner_name'  => 'runner-x',
                    'runner_id'    => 3,
                    'started_at'   => '2025-04-01T10:00:00Z',
                    'completed_at' => '2025-04-01T10:03:00Z',
                ]],
            ])),
        ]);

        $jobs = $provider->getJobs('owner/repo', 42);
        $this->assertCount(1, $jobs);
        $this->assertSame(9, $jobs[0]['id']);
        $this->assertSame('build', $jobs[0]['name']);
        $duration = $jobs[0]['duration'];
        $this->assertIsInt($duration);
        $this->assertSame(180, $duration);
    }

    public function testTriggerReadsWorkflowRunIdFromRunDetails(): void
    {
        // Gitea 1.27 触发成功返回 RunDetails {workflow_run_id, run_url, html_url}，
        // 没有 GitHub 风格的 id 字段。
        $provider = $this->providerWith([
            self::json(json_encode([
                'workflow_run_id' => 123,
                'run_url'         => 'https://gitea.example/owner/repo/actions/runs/123',
                'html_url'        => 'https://gitea.example/owner/repo/actions/runs/123',
            ])),
        ]);

        $result = $provider->trigger('owner/repo', 'release/2.8.2', ['workflow' => 'build.yml']);
        $this->assertTrue($result['success']);
        $this->assertSame(123, $result['run_id']);
    }

    public function testGetJobsDurationZeroWithoutCompletedAt(): void
    {
        // Gitea job 运行中（completed_at 缺失）不应把时间解析成负值/假值
        $provider = $this->providerWith([
            self::json(json_encode([
                'total_count' => 1,
                'jobs'        => [[
                    'id'         => 10,
                    'name'       => 'build',
                    'status'     => 'running',
                    'started_at' => '2025-04-01T10:00:00Z',
                ]],
            ])),
        ]);

        $jobs = $provider->getJobs('owner/repo', 42);
        $this->assertCount(1, $jobs);
        $this->assertSame(0, $jobs[0]['duration']);
    }
}