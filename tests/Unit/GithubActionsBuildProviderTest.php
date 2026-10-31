<?php
declare(strict_types=1);

namespace App\Test\Unit;

use App\Service\Build\GithubActionsBuildProvider;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * GithubActionsBuildProvider 回归测试：锁定与 GitHub Actions REST API 的差异点——
 *   1. 端点直接挂在 /repos/... 下（无 Gitea 的 /api/v1 前缀）；
 *   2. run/job 的 status（queued/in_progress/completed）与 conclusion（success/failure/…）分离，
 *      completed 时须取 conclusion 才是最终结果。
 * 通过反射把 provider 私有 $http 换成带 MockHandler 的 Guzzle，避免真实网络调用。
 */
class GithubActionsBuildProviderTest extends TestCase
{
    /** @param list<Response> $responses */
    private function providerWith(array $responses, string $token = 'tok'): GithubActionsBuildProvider
    {
        $mock   = new MockHandler($responses);
        $stack  = HandlerStack::create($mock);
        $client = new Client(['handler' => $stack, 'http_errors' => false]);

        $provider = new GithubActionsBuildProvider('https://api.github.com', $token, new \App\Service\I18nService(__DIR__ . '/../../lang'));

        $prop = new ReflectionProperty(GithubActionsBuildProvider::class, 'http');
        $prop->setAccessible(true);
        $prop->setValue($provider, $client);

        return $provider;
    }

    private static function json(string $body, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], $body);
    }

    public function testGetPipelinesUsesReposPathWithoutApiV1Prefix(): void
    {
        $history = [];
        $mock    = new MockHandler([self::json(json_encode(['workflow_runs' => []]))]);
        $stack   = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $client  = new Client(['handler' => $stack, 'http_errors' => false]);

        $provider = new GithubActionsBuildProvider('https://api.github.com', 'tok', new \App\Service\I18nService(__DIR__ . '/../../lang'));
        $prop = new ReflectionProperty(GithubActionsBuildProvider::class, 'http');
        $prop->setAccessible(true);
        $prop->setValue($provider, $client);

        $provider->getPipelines('owner/repo');

        $this->assertCount(1, $history);
        
        $request = $history[0]['request'];
        $this->assertStringContainsString('/repos/owner/repo/actions/runs', (string) $request->getUri());
        $this->assertStringNotContainsString('/api/v1', (string) $request->getUri());
    }

    public function testGetPipelinesMapsCompletedConclusionToStatus(): void
    {
        
        $provider = $this->providerWith([
            self::json(json_encode([
                'workflow_runs' => [[
                    'id'           => 42,
                    'run_number'   => 7,
                    'status'       => 'completed',
                    'conclusion'   => 'failure',
                    'head_branch'  => 'main',
                    'head_sha'     => 'abc123',
                    'html_url'     => 'https://github.com/owner/repo/actions/runs/42',
                    'created_at'   => '2025-04-01T10:00:00Z',
                    'updated_at'   => '2025-04-01T10:05:00Z',
                ]],
            ])),
        ]);

        $pipes = $provider->getPipelines('owner/repo');
        $this->assertCount(1, $pipes);
        $this->assertSame(42, $pipes[0]['id']);
        $this->assertSame(7, $pipes[0]['iid']);
        $this->assertSame('failed', $pipes[0]['status']);
        $this->assertSame('https://github.com/owner/repo/actions/runs/42', $pipes[0]['web_url']);
    }

    public function testGetPipelinesMapsInProgressWithoutConclusionToRunning(): void
    {
        
        $provider = $this->providerWith([
            self::json(json_encode([
                'workflow_runs' => [[
                    'id'          => 42,
                    'run_number'  => 7,
                    'status'      => 'in_progress',
                    'conclusion'  => null,
                    'head_branch' => 'main',
                    'head_sha'    => 'abc123',
                ]],
            ])),
        ]);

        $pipes = $provider->getPipelines('owner/repo');
        $this->assertSame('running', $pipes[0]['status']);
        
        $this->assertStringContainsString('https://github.com/owner/repo/actions/runs/42', $pipes[0]['web_url']);
    }

    public function testGetJobsMapsCompletedConclusionAndDuration(): void
    {
        $provider = $this->providerWith([
            self::json(json_encode([
                'jobs' => [[
                    'id'           => 9,
                    'name'         => 'build',
                    'status'       => 'completed',
                    'conclusion'   => 'success',
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
        $this->assertSame('success', $jobs[0]['status']);
        $this->assertSame(180, $jobs[0]['duration']);
    }

    public function testTriggerDispatchReturnsSuccessOn204NoContent(): void
    {
        
        $provider = $this->providerWith([
            new Response(204),
        ]);

        $result = $provider->trigger('owner/repo', 'main', ['workflow' => 'build.yml']);
        $this->assertTrue($result['success']);
    }

    public function testTriggerKeepsBareBranchAndTagNames(): void
    {
        
        $history = [];
        $mock    = new MockHandler([new Response(204), new Response(204)]);
        $stack   = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $client  = new Client(['handler' => $stack, 'http_errors' => false]);

        $provider = new GithubActionsBuildProvider('https://api.github.com', 'tok', new \App\Service\I18nService(__DIR__ . '/../../lang'));
        $prop = new ReflectionProperty(GithubActionsBuildProvider::class, 'http');
        $prop->setAccessible(true);
        $prop->setValue($provider, $client);

        $provider->trigger('owner/repo', 'main', ['workflow' => 'ci.yml']);
        $provider->trigger('owner/repo', 'refs/tags/v1.0.0', ['workflow' => 'release.yml']);

        $this->assertCount(2, $history);
        $refs = [];
        foreach ($history as $entry) {
            
            $request = $entry['request'];
            $payload = json_decode((string) $request->getBody(), true);
            $refs[]  = $payload['ref'] ?? null;
        }
        $this->assertSame(['main', 'v1.0.0'], $refs);
    }

    public function testGetJobsPaginatesBeyondFirstPage(): void
    {
        
        $makeJob = fn(int $id): array => [
            'id'           => $id,
            'name'         => 'job-' . $id,
            'status'       => 'completed',
            'conclusion'   => 'success',
            'runner_name'  => 'r',
            'runner_id'    => 1,
            'started_at'   => '2025-04-01T10:00:00Z',
            'completed_at' => '2025-04-01T10:01:00Z',
        ];
        $page1 = array_map($makeJob, range(1, 100));
        $page2 = [$makeJob(101)];

        $provider = $this->providerWith([
            self::json(json_encode(['jobs' => $page1])),
            self::json(json_encode(['jobs' => $page2])),
        ]);

        $jobs = $provider->getJobs('owner/repo', 42);
        $this->assertCount(101, $jobs);
        $this->assertSame(101, $jobs[100]['id']);
    }
}
