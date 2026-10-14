<?php

declare(strict_types=1);

namespace App\Test\Unit;

use App\Service\Git\GitProviderInterface;
use App\Service\Git\ProviderRegistry;
use App\Service\GitRemoteResolver;
use App\Service\GitService;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\TestCase;

/**
 * GitService 的分支 / tag / 合并 refs 查询单元测试（v2.9.0 起 CD 构建页可用 tag 作构建 ref）。
 *
 * 用假 provider 顶掉真实平台 HTTP，锁住三件事（这三条都是「改错了也不报错」的隐性契约）：
 *   - tags 与 branches 复用同一套映射解析与错误语义：无映射 → 空集；平台连接失败 → 上抛，
 *     不得吞成空集（否则「仓库没有 tag」与「平台挂了」无法区分，CD 会把发布页刷成空下拉）；
 *   - refs 只解析一次 job→仓库映射（这是合并端点存在的理由）；
 *   - GitLab 仓库路径的 urlencode 在 tag/refs 路径上同样生效（漏编码即 404）。
 */
class GitServiceRefsTest extends TestCase
{
    public function testGetTagsForJobReturnsProviderTags(): void
    {
        $provider = new RecordingGitProvider([], ['v2.8.9', 'v2.9.0']);
        $service = $this->makeService($this->map(), $provider);

        $this->assertSame(['v2.8.9', 'v2.9.0'], $service->getTagsForJob('group/proj'));
    }

    public function testGetBranchesForJobStillReturnsBranches(): void
    {
        $provider = new RecordingGitProvider(['main', 'develop'], []);
        $service = $this->makeService($this->map(), $provider);

        $this->assertSame(['main', 'develop'], $service->getBranchesForJob('group/proj'));
    }

    public function testGetRefsForJobResolvesMappingOnceAndReturnsBothLists(): void
    {
        $provider = new RecordingGitProvider(['main'], ['v1.0.0']);
        // exactly(1)：合并端点必须只解析一次映射，否则它就失去了相对 branches+tags 两次调用的意义
        $service = $this->makeService($this->map(), $provider, 1);

        $this->assertSame(
            ['branches' => ['main'], 'tags' => ['v1.0.0']],
            $service->getRefsForJob('group/proj')
        );
        $this->assertSame('group%2Fproj', $provider->lastRepository, 'refs 走的仍是 GitLab 编码后的仓库标识');
    }

    public function testGitlabRepositoryPathIsUrlEncodedForTags(): void
    {
        $provider = new RecordingGitProvider([], ['v1.0.0']);
        $service = $this->makeService($this->map('http://gitlab.example.com/group/sub/proj.git'), $provider);

        $service->getTagsForJob('group/sub/proj');

        $this->assertSame(
            'group%2Fsub%2Fproj',
            $provider->lastRepository,
            'GitLab projects API 需要整体编码的 path：这是唯一允许的编码点，漏编码/重复编码都会 404'
        );
    }
    public function testGetTagsForJobReturnsEmptyArrayWhenJobHasNoMapping(): void
    {
        $provider = new RecordingGitProvider([], ['v1.0.0']);
        $resolver = $this->createMock(GitRemoteResolver::class);
        $resolver->method('getByJobName')->willReturn(null);

        $registry = new ProviderRegistry();
        $registry->register('gitlab', static fn(string $url): bool => true, static fn(): GitProviderInterface => $provider);

        $this->assertSame([], (new GitService($resolver, $registry))->getTagsForJob('not-in-job-git-map'));
        $this->assertSame('', $provider->lastRepository, '无映射时不得触碰平台 API');
    }

    public function testGetRefsForJobReturnsEmptyListsWhenJobHasNoMapping(): void
    {
        $provider = new RecordingGitProvider(['main'], ['v1.0.0']);
        $resolver = $this->createMock(GitRemoteResolver::class);
        $resolver->method('getByJobName')->willReturn(null);

        $registry = new ProviderRegistry();
        $registry->register('gitlab', static fn(string $url): bool => true, static fn(): GitProviderInterface => $provider);

        $this->assertSame(
            ['branches' => [], 'tags' => []],
            (new GitService($resolver, $registry))->getRefsForJob('not-in-job-git-map')
        );
    }

    public function testPlatformFailurePropagatesAsRuntimeExceptionForTags(): void
    {
        $provider = new RecordingGitProvider([], [], $this->timeout());
        $service = $this->makeService($this->map(), $provider);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/tag 查询失败/');
        $service->getTagsForJob('group/proj');
    }

    public function testFallbackDetectionHintIsAppendedToTagFailureMessage(): void
    {
        $provider = new RecordingGitProvider([], [], $this->timeout());
        $service = $this->makeService(
            $this->map('http://git.example.com/group/proj.git', ['detection_method' => 'fallback']),
            $provider
        );

        // 兜底识别出的平台连不上时，必须把「可能是 DEFAULT_GIT_PLATFORM 配错了」提示带上，
        // 否则用户只看到一句超时，无从判断是平台选错还是网络问题。
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/DEFAULT_GIT_PLATFORM/');
        $service->getTagsForJob('group/proj');
    }

    public function testGetRefsForJobPropagatesFailureInsteadOfReturningHalfResults(): void
    {
        $provider = new RecordingGitProvider(['main'], [], $this->timeout());
        $service = $this->makeService($this->map(), $provider);

        // 只回一半候选值会让用户误判；refs 先查 branches，故失败文案是「分支查询失败」
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/分支查询失败/');
        $service->getRefsForJob('group/proj');
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function map(string $remote = 'http://gitlab.example.com/group/proj.git', array $overrides = []): array
    {
        return array_merge([
            'git_platform'     => 'gitlab',
            'git_remote'       => $remote,
            'platform_source'  => 'auto',
            'detection_method' => 'exact',
        ], $overrides);
    }

    /**
     * @param array<string,mixed> $map
     */
    private function makeService(array $map, GitProviderInterface $provider, int $expectedResolveCalls = 1): GitService
    {
        $resolver = $this->createMock(GitRemoteResolver::class);
        $resolver->expects($this->exactly($expectedResolveCalls))
            ->method('getByJobName')
            ->willReturn($map);

        $registry = new ProviderRegistry();
        $registry->register('gitlab', static fn(string $url): bool => true, static fn(): GitProviderInterface => $provider);

        return new GitService($resolver, $registry);
    }

    private function timeout(): ConnectException
    {
        return new ConnectException('cURL error 28: Connection timed out', new Request('GET', '/projects'));
    }
}

/**
 * 假 Git provider：记录最后一次收到的仓库标识，按预置列表应答或抛出预置异常。
 * 只放在本文件 —— 仅此测试需要「可观测的 provider」。
 */
final class RecordingGitProvider implements GitProviderInterface
{
    public string $lastRepository = '';

    /**
     * @param list<string> $branches
     * @param list<string> $tags
     */
    public function __construct(
        private array $branches = [],
        private array $tags = [],
        private ?\Throwable $failure = null
    ) {
    }

    public function getBranches(string $repository): array
    {
        return $this->respond($repository, $this->branches);
    }

    public function getTags(string $repository): array
    {
        return $this->respond($repository, $this->tags);
    }

    public function setCommitStatus(
        string $repository,
        string $sha,
        string $state,
        string $context,
        string $description,
        string $targetUrl = ''
    ): array {
        return ['success' => true, 'message' => ''];
    }

    public function getName(): string
    {
        return 'recording';
    }

    public function matchUrl(string $url): bool
    {
        return true;
    }

    public function getApiVersion(): string
    {
        return 'v1';
    }

    /**
     * @param list<string> $list
     * @return list<string>
     */
    private function respond(string $repository, array $list): array
    {
        $this->lastRepository = $repository;
        if ($this->failure !== null) {
            throw $this->failure;
        }
        return $list;
    }
}

