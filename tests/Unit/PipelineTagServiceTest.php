<?php
declare(strict_types=1);

namespace App\Test\Unit;

use App\Config\AppConfig;
use App\Service\PipelineTagService;
use App\Service\Registry\RegistryProviderInterface;
use PHPUnit\Framework\TestCase;

/**
 * PipelineTagService 单元测试
 *
 * 覆盖以镜像仓库为准清理 ci_pipeline_artifacts 时的安全约束：
 *   - 只删镜像仓库明确返回了 tag 列表且其中没有这条的记录；
 *   - 镜像仓库不可达：该仓库跳过，不删除任何记录；
 *   - repository / tag 为空：跳过（无法校验即保留）；
 *   - 镜像仓库未配置（null）：整体跳过；
 *   - 只操作 ci_pipeline_artifacts，不碰 cd_* 表。
 *
 * 依赖 SQLite 内存库 + mock RegistryProviderInterface，不触网。
 *
 * 运行：vendor/bin/phpunit tests/Unit/PipelineTagServiceTest.php
 */
class PipelineTagServiceTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

        $this->pdo->exec('CREATE TABLE ' . AppConfig::TABLE_PIPELINE_ARTIFACTS . ' (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            provider TEXT NOT NULL,
            project_id TEXT NOT NULL,
            pipeline_iid INTEGER NOT NULL,
            project_key TEXT NOT NULL,
            repository TEXT NOT NULL,
            tag TEXT NOT NULL,
            status TEXT DEFAULT "",
            source_updated_at TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (provider, project_id, pipeline_iid)
        )');
        
        $this->pdo->exec('CREATE TABLE cd_registry_artifacts (
            id INTEGER PRIMARY KEY,
            tag TEXT
        )');
        
        $this->pdo->exec('CREATE TABLE ' . AppConfig::TABLE_BUILD_TAG_CACHE . ' (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            project_key TEXT DEFAULT "",
            provider TEXT DEFAULT "",
            project_id TEXT DEFAULT "",
            sha TEXT NOT NULL UNIQUE,
            pipeline_id TEXT DEFAULT "",
            tag TEXT DEFAULT "",
            repository TEXT DEFAULT "",
            source TEXT DEFAULT "log",
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP
        )');
    }

    private function insertTag(string $project, int $pipelineIid, string $tag, ?string $harborRepo): void
    {
        $this->pdo->prepare(
            'INSERT INTO ' . AppConfig::TABLE_PIPELINE_ARTIFACTS . ' (provider, project_id, pipeline_iid, project_key, repository, tag) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute(['jenkins', $project, $pipelineIid, $project, $harborRepo ?? '', $tag]);
    }

    private function insertBuildLog(string $provider, string $projectId, int $pipelineIid, string $tag, string $repo, string $sha): void
    {
        $this->pdo->prepare(
            'INSERT INTO ' . AppConfig::TABLE_BUILD_TAG_CACHE . ' (project_key, provider, project_id, sha, pipeline_id, tag, repository, source) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$projectId, $provider, $projectId, $sha, (string) $pipelineIid, $tag, $repo, 'log']);
    }

    private function countTags(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM ' . AppConfig::TABLE_PIPELINE_ARTIFACTS)->fetchColumn();
    }

    /**
     * mock RegistryProviderInterface：按仓库返回可控 tag 列表。
     * 未 stub 的仓库返回 ['error' => ...]（模拟镜像仓库不可达）。
     */
    private function makeRegistry(array $tagsByRepo): RegistryProviderInterface
    {
        $registry = $this->createMock(RegistryProviderInterface::class);
        $registry->method('type')->willReturn('harbor');
        $registry->method('supportsScanning')->willReturn(true);
        $registry->method('repositoryExists')->willReturn(true);
        $registry->method('getScanReport')->willReturn([]);
        $registry->method('getTags')->willReturnCallback(
            function (string $repository) use ($tagsByRepo): array {
                if (!array_key_exists($repository, $tagsByRepo)) {
                    return ['error' => 'registry unreachable (not stubbed)'];
                }
                return $tagsByRepo[$repository];
            }
        );
        return $registry;
    }

    public function testDeletesStaleTagsOnly(): void
    {
        $this->insertTag('java/registry', 1, 'v1.0.0', 'mycode/registry'); 
        $this->insertTag('java/registry', 2, 'v0.9.0', 'mycode/registry'); 
        $registry = $this->makeRegistry(['mycode/registry' => ['v1.0.0', 'latest']]);

        $stat = (new PipelineTagService($this->pdo, $registry))->cleanupStaleTags();

        $this->assertSame(1, $stat['deleted']);
        $this->assertSame(2, $stat['checked']);
        $this->assertSame(0, $stat['unreachable']);
        $this->assertSame(0, $stat['unverifiable']);
        $this->assertSame(1, $this->countTags());
        
        $row = $this->pdo->query('SELECT tag FROM ' . AppConfig::TABLE_PIPELINE_ARTIFACTS)->fetch();
        $this->assertSame('v1.0.0', $row['tag']);
    }

    public function testSkipsWhenRegistryUnreachable(): void
    {
        $this->insertTag('java/registry', 1, 'v1.0.0', 'mycode/registry');
        
        $registry = $this->makeRegistry(['mycode/registry' => ['error' => 'registry down']]);

        $stat = (new PipelineTagService($this->pdo, $registry))->cleanupStaleTags();

        $this->assertSame(0, $stat['deleted']);
        $this->assertSame(0, $stat['checked']);
        $this->assertSame(1, $stat['unreachable']);
        $this->assertSame(1, $this->countTags()); 
    }

    public function testSkipsEmptyRegistryRepositoryOrTag(): void
    {
        $this->insertTag('p1', 1, 'v1', '');               
        $this->insertTag('p2', 1, '', 'mycode/registry');  
        $registry = $this->makeRegistry(['mycode/registry' => ['v1']]);

        $stat = (new PipelineTagService($this->pdo, $registry))->cleanupStaleTags();

        $this->assertSame(0, $stat['deleted']);
        $this->assertSame(0, $stat['checked']);
        $this->assertSame(0, $stat['unreachable']);
        $this->assertSame(2, $stat['unverifiable']);
        $this->assertSame(2, $this->countTags()); 
    }

    public function testNullRegistryDeletesNothing(): void
    {
        $this->insertTag('java/registry', 1, 'v1.0.0', 'mycode/registry');

        $stat = (new PipelineTagService($this->pdo, null))->cleanupStaleTags();

        $this->assertSame([0, 0, 0, 0], array_values($stat));
        $this->assertSame(1, $this->countTags());
    }

    public function testDoesNotTouchCdTables(): void
    {
        
        $this->insertTag('java/registry', 1, 'gone', 'mycode/registry');
        
        $this->pdo->prepare('INSERT INTO cd_registry_artifacts (id, tag) VALUES (?, ?)')->execute([1, 'gone']);
        $registry = $this->makeRegistry(['mycode/registry' => ['latest']]); 

        $stat = (new PipelineTagService($this->pdo, $registry))->cleanupStaleTags();

        $this->assertSame(1, $stat['deleted']);
        $this->assertSame(0, $this->countTags()); 
        
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM cd_registry_artifacts')->fetchColumn());
    }

    // ── backfillTagsFromBuildLog ──

    public function testBackfillPromotesVerifiedTag(): void
    {
        $this->insertBuildLog('jenkins', 'java/registry', 99, 'v1.2.3', 'mycode/registry', 'sha-a');
        $registry = $this->makeRegistry(['mycode/registry' => ['v1.2.3', 'latest']]);

        $stat = (new PipelineTagService($this->pdo, $registry))->backfillTagsFromBuildLog();

        $this->assertSame(1, $stat['promoted']);
        $this->assertSame(1, $stat['checked']);
        $this->assertSame(0, $stat['skipped']);
        $row = $this->pdo->query('SELECT tag FROM ' . AppConfig::TABLE_PIPELINE_ARTIFACTS)->fetch();
        $this->assertSame('v1.2.3', $row['tag']);
    }

    public function testBackfillSkipsTagNotInRegistry(): void
    {
        $this->insertBuildLog('jenkins', 'java/registry', 99, 'v9.9.9', 'mycode/registry', 'sha-b');
        $registry = $this->makeRegistry(['mycode/registry' => ['latest']]);

        $stat = (new PipelineTagService($this->pdo, $registry))->backfillTagsFromBuildLog();

        $this->assertSame(0, $stat['promoted']);
        $this->assertSame(1, $stat['checked']);
        $this->assertSame(0, $this->countTags()); 
    }

    public function testBackfillSkipsExistingCanonicalTag(): void
    {
        $this->insertTag('java/registry', 99, 'v2.0.0', 'mycode/registry'); 
        $this->insertBuildLog('jenkins', 'java/registry', 99, 'v1.2.3', 'mycode/registry', 'sha-c');
        $registry = $this->makeRegistry(['mycode/registry' => ['v1.2.3']]);

        $stat = (new PipelineTagService($this->pdo, $registry))->backfillTagsFromBuildLog();

        $this->assertSame(0, $stat['promoted']);
        $this->assertSame(1, $stat['skipped']);
        $row = $this->pdo->query('SELECT tag FROM ' . AppConfig::TABLE_PIPELINE_ARTIFACTS)->fetch();
        $this->assertSame('v2.0.0', $row['tag']); 
    }

    public function testBackfillSkipsRegistryUnreachable(): void
    {
        $this->insertBuildLog('jenkins', 'java/registry', 99, 'v1.2.3', 'mycode/registry', 'sha-d');
        $registry = $this->makeRegistry(['mycode/registry' => ['error' => 'registry down']]);

        $stat = (new PipelineTagService($this->pdo, $registry))->backfillTagsFromBuildLog();

        $this->assertSame(0, $stat['promoted']);
        $this->assertSame(0, $stat['checked']);
        $this->assertSame(1, $stat['unreachable']);
        $this->assertSame(0, $this->countTags());
    }

    public function testBackfillNullRegistryNoop(): void
    {
        $this->insertBuildLog('jenkins', 'java/registry', 99, 'v1.2.3', 'mycode/registry', 'sha-e');

        $stat = (new PipelineTagService($this->pdo, null))->backfillTagsFromBuildLog();

        $this->assertSame([0, 0, 0, 0, 0], array_values($stat));
        $this->assertSame(0, $this->countTags());
    }
}
