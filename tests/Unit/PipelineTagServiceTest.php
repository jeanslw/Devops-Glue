<?php
declare(strict_types=1);

namespace App\Test\Unit;

use App\Config\AppConfig;
use App\Service\HarborService;
use App\Service\PipelineTagService;
use PHPUnit\Framework\TestCase;

/**
 * PipelineTagService::cleanupStaleTags 单元测试
 *
 * 专项锁定「以 Harbor 为准」清理 ci_pipeline_tags 的安全不变量：
 *   - 只删「Harbor 明确返回了 tag 列表且其中没有这条」的行；
 *   - Harbor 不可达 → 该仓库跳过，绝不误删；
 *   - harbor_repository / tag 为空 → 跳过（不可校验 = 保留）；
 *   - Harbor 未配置（null）→ 整体跳过；
 *   - 只碰 ci_pipeline_tags，绝不碰 cd_* 表。
 *
 * 依赖 SQLite 内存库 + mock HarborService（override getTags），不触网。
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

        $this->pdo->exec('CREATE TABLE ' . AppConfig::TABLE_PIPELINE_TAGS . ' (
            project TEXT NOT NULL,
            pipeline_iid INTEGER NOT NULL,
            tag TEXT NOT NULL,
            harbor_repository TEXT,
            status TEXT DEFAULT "",
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (project, pipeline_iid)
        )');
        // 顺带造一张 cd_* 表，用于锁定「绝不碰 cd_*」
        $this->pdo->exec('CREATE TABLE cd_registry_artifacts (
            id INTEGER PRIMARY KEY,
            tag TEXT
        )');
    }

    private function insertTag(string $project, int $pipelineIid, string $tag, ?string $harborRepo): void
    {
        $this->pdo->prepare(
            'INSERT INTO ' . AppConfig::TABLE_PIPELINE_TAGS . ' (project, pipeline_iid, tag, harbor_repository) VALUES (?, ?, ?, ?)'
        )->execute([$project, $pipelineIid, $tag, $harborRepo]);
    }

    private function countTags(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM ' . AppConfig::TABLE_PIPELINE_TAGS)->fetchColumn();
    }

    /**
     * mock HarborService：只 override getTags，按 "project/repo" 返回可控 tag 列表。
     * 未 stub 的仓库返回 ['error' => ...]（模拟 Harbor 不可达）。
     */
    private function makeHarbor(array $tagsByRepo): HarborService
    {
        $harbor = $this->getMockBuilder(HarborService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getTags'])
            ->getMock();
        $harbor->method('getTags')->willReturnCallback(
            function (string $project, string $repository) use ($tagsByRepo): array {
                $key = $project . '/' . $repository;
                if (!array_key_exists($key, $tagsByRepo)) {
                    return ['error' => 'harbor unreachable (not stubbed)'];
                }
                return $tagsByRepo[$key];
            }
        );
        return $harbor;
    }

    public function testDeletesStaleTagsOnly(): void
    {
        $this->insertTag('java/registry', 1, 'v1.0.0', 'mycode/registry'); // Harbor 有 → 保留
        $this->insertTag('java/registry', 2, 'v0.9.0', 'mycode/registry'); // Harbor 无 → 删除
        $harbor = $this->makeHarbor(['mycode/registry' => ['v1.0.0', 'latest']]);

        $stat = (new PipelineTagService($this->pdo, $harbor))->cleanupStaleTags();

        $this->assertSame(1, $stat['deleted']);
        $this->assertSame(2, $stat['checked']);
        $this->assertSame(0, $stat['unreachable']);
        $this->assertSame(0, $stat['unverifiable']);
        $this->assertSame(1, $this->countTags());
        // 剩下的那行是 v1.0.0
        $row = $this->pdo->query('SELECT tag FROM ' . AppConfig::TABLE_PIPELINE_TAGS)->fetch();
        $this->assertSame('v1.0.0', $row['tag']);
    }

    public function testSkipsWhenHarborUnreachable(): void
    {
        $this->insertTag('java/registry', 1, 'v1.0.0', 'mycode/registry');
        // getTags 返回 error → 模拟 Harbor 不可达
        $harbor = $this->makeHarbor(['mycode/registry' => ['error' => 'harbor down']]);

        $stat = (new PipelineTagService($this->pdo, $harbor))->cleanupStaleTags();

        $this->assertSame(0, $stat['deleted']);
        $this->assertSame(0, $stat['checked']);
        $this->assertSame(1, $stat['unreachable']);
        $this->assertSame(1, $this->countTags()); // 一条不删
    }

    public function testSkipsEmptyHarborRepositoryOrTag(): void
    {
        $this->insertTag('p1', 1, 'v1', '');               // harbor_repository 空
        $this->insertTag('p2', 1, '', 'mycode/registry');  // tag 空
        $harbor = $this->makeHarbor(['mycode/registry' => ['v1']]);

        $stat = (new PipelineTagService($this->pdo, $harbor))->cleanupStaleTags();

        $this->assertSame(0, $stat['deleted']);
        $this->assertSame(0, $stat['checked']);
        $this->assertSame(0, $stat['unreachable']);
        $this->assertSame(2, $stat['unverifiable']);
        $this->assertSame(2, $this->countTags()); // 两条都保留
    }

    public function testNullHarborDeletesNothing(): void
    {
        $this->insertTag('java/registry', 1, 'v1.0.0', 'mycode/registry');

        $stat = (new PipelineTagService($this->pdo, null))->cleanupStaleTags();

        $this->assertSame([0, 0, 0, 0], array_values($stat));
        $this->assertSame(1, $this->countTags());
    }

    public function testDoesNotTouchCdTables(): void
    {
        // ci 表里有一条会过期的记录
        $this->insertTag('java/registry', 1, 'gone', 'mycode/registry');
        // cd 表里也放一条「同名」记录，清理后必须原封不动
        $this->pdo->prepare('INSERT INTO cd_registry_artifacts (id, tag) VALUES (?, ?)')->execute([1, 'gone']);
        $harbor = $this->makeHarbor(['mycode/registry' => ['latest']]); // 'gone' 已不在 Harbor

        $stat = (new PipelineTagService($this->pdo, $harbor))->cleanupStaleTags();

        $this->assertSame(1, $stat['deleted']);
        $this->assertSame(0, $this->countTags()); // ci 表清空了
        // cd 表未被触碰
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM cd_registry_artifacts')->fetchColumn());
    }
}
