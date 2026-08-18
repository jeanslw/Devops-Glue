<?php
declare(strict_types=1);

namespace App\Test\Unit;

use App\Config\AppConfig;
use App\Service\Build\CustomPushBuildProvider;
use App\Service\Database;
use PHPUnit\Framework\TestCase;

/**
 * 自定义推送式 CI（custom_push）Build Provider 单元测试
 *
 * 覆盖：
 *   - getName()（默认 / 自定义注册名）
 *   - report()（创建终态记录 / 缺 pipeline_iid / 非法 status / 缺 finished_at / 控制字段隔离 /
 *     重复覆盖保住 id / exit_code=0 落库 / started_at 可选）
 *   - trigger()（不支持主动触发，返回 success=false）
 *   - findByIid() / getPipelines() / getJobs()
 *   - getJobTrace()（无 log_url 时兜底文案，不发起网络请求）
 *   - getVariables()（string / array 两种参数定义）
 *   - retry / cancel / setCommitStatus / getBranches（不支持或委托降级）
 *
 * 依赖 SQLite 内存库（自定义建 ci_custom_builds / ci_pipeline_tags 表），不触网。
 *
 * 运行：vendor/bin/phpunit tests/Unit/CustomPushBuildProviderTest.php
 */
class CustomPushBuildProviderTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        // sqlUpsert 依赖 Database::$driver 选择 SQLite / MySQL 语法，此处强制 sqlite
        Database::init(['driver' => 'sqlite', 'auto_migrate' => false]);

        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

        $this->pdo->exec('CREATE TABLE ' . AppConfig::TABLE_CUSTOM_BUILDS . ' (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            job_name TEXT NOT NULL,
            pipeline_iid INTEGER NOT NULL,
            ref TEXT,
            sha TEXT,
            variables_json TEXT,
            status TEXT DEFAULT "pending",
            exit_code INTEGER,
            log_url TEXT,
            web_url TEXT,
            triggered_at TEXT,
            started_at TEXT,
            finished_at TEXT,
            UNIQUE (job_name, pipeline_iid)
        )');
        $this->pdo->exec('CREATE TABLE ' . AppConfig::TABLE_PIPELINE_TAGS . ' (
            project TEXT NOT NULL,
            pipeline_iid INTEGER NOT NULL,
            tag TEXT NOT NULL,
            harbor_repository TEXT,
            status TEXT DEFAULT "",
            created_at TEXT,
            PRIMARY KEY (project, pipeline_iid)
        )');
    }

    protected function tearDown(): void
    {
        Database::reset();
        parent::tearDown();
    }

    private function makeProvider(array $config = []): CustomPushBuildProvider
    {
        return new CustomPushBuildProvider($config, $this->pdo);
    }

    // ── getName ──

    public function testGetNameDefaultsToCustomPush(): void
    {
        $this->assertSame(AppConfig::PROVIDER_CUSTOM_PUSH, $this->makeProvider()->getName());
    }

    public function testGetNameUsesConfiguredName(): void
    {
        $this->assertSame('my_ci', $this->makeProvider(['name' => 'my_ci'])->getName());
    }

    // ── trigger（不支持主动触发） ──

    public function testTriggerReturnsUnsupported(): void
    {
        $result = $this->makeProvider()->trigger('jobA', 'main', ['pipeline_iid' => 1]);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('report', $result['message']);
    }

    // ── report ──

    public function testReportRequiresPipelineIid(): void
    {
        $result = $this->makeProvider()->report('jobA', ['status' => 'success', 'finished_at' => '2026-08-18 10:00:00']);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('pipeline_iid', $result['message']);
    }

    public function testReportRejectsInvalidStatus(): void
    {
        $result = $this->makeProvider()->report('jobA', [
            'pipeline_iid' => 10,
            'status'       => 'running',
            'finished_at'  => '2026-08-18 10:00:00',
        ]);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('status', $result['message']);
    }

    public function testReportRequiresFinishedAt(): void
    {
        $result = $this->makeProvider()->report('jobA', [
            'pipeline_iid' => 10,
            'status'       => 'success',
        ]);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('finished_at', $result['message']);
    }

    public function testReportCreatesTerminalRecord(): void
    {
        $result = $this->makeProvider()->report('jobA', [
            'pipeline_iid' => 10,
            'status'       => 'success',
            'finished_at'  => '2026-08-18 10:05:30',
            'started_at'   => '2026-08-18 10:00:00',
            'ref'          => 'main',
            'sha'          => 'abc1234567',
            'log_url'      => 'http://logs.example/10',
            'web_url'      => 'http://ci.example/10',
            'exit_code'    => 0,
            'zone'         => 'az1',       // 自定义构建参数
        ]);

        $this->assertTrue($result['success']);
        $this->assertEquals(10, $result['pipeline_iid']);
        $this->assertEquals('created', $result['action']);

        $row = $this->pdo->query('SELECT * FROM ' . AppConfig::TABLE_CUSTOM_BUILDS . ' WHERE job_name = "jobA"')->fetch();
        $this->assertNotFalse($row);
        $this->assertEquals('success', $row['status']);
        $this->assertEquals('2026-08-18 10:05:30', $row['finished_at']);
        $this->assertEquals('2026-08-18 10:00:00', $row['started_at']);
        $this->assertEquals('main', $row['ref']);
        $this->assertEquals('abc1234567', $row['sha']);
        $this->assertNotNull($row['triggered_at']);
        // exit_code=0 应被落库为 0，而非当作「未传」落为 NULL
        $this->assertNotNull($row['exit_code']);
        $this->assertSame(0, (int) $row['exit_code']);
        // 控制字段（pipeline_iid/status/finished_at/tag/...）不应被写进 variables_json
        $vars = json_decode($row['variables_json'], true);
        $this->assertSame(['zone' => 'az1'], $vars);
    }

    public function testReportStartedAtIsOptional(): void
    {
        $result = $this->makeProvider()->report('jobA', [
            'pipeline_iid' => 11,
            'status'       => 'failed',
            'finished_at'  => '2026-08-18 10:05:30',
        ]);
        $this->assertTrue($result['success']);

        $row = $this->pdo->query('SELECT * FROM ' . AppConfig::TABLE_CUSTOM_BUILDS . ' WHERE job_name = "jobA" AND pipeline_iid = 11')->fetch();
        $this->assertEquals('failed', $row['status']);
        $this->assertNull($row['started_at']);
    }

    public function testReportOverwritesPreservingId(): void
    {
        $provider = $this->makeProvider();
        $first = $provider->report('jobA', [
            'pipeline_iid' => 30,
            'status'       => 'failed',
            'finished_at'  => '2026-08-18 10:05:30',
            'sha'          => 'oldsha123',
        ]);
        $before = $provider->findByIid('jobA', 30);
        $beforeId = (int) $before['id'];

        // 重复上报同一 pipeline_iid → 覆盖（UPDATE），非新增，保住原自增 id
        $second = $provider->report('jobA', [
            'pipeline_iid' => 30,
            'status'       => 'success',
            'finished_at'  => '2026-08-18 10:10:00',
            'sha'          => 'newsha456',
        ]);

        $this->assertTrue($first['success']);
        $this->assertEquals('created', $first['action']);
        $this->assertTrue($second['success']);
        $this->assertEquals('updated', $second['action']);
        $this->assertEquals($beforeId, $second['pipeline_id']);

        $row = $this->pdo->query('SELECT * FROM ' . AppConfig::TABLE_CUSTOM_BUILDS . ' WHERE job_name = "jobA" AND pipeline_iid = 30')->fetch();
        $this->assertEquals($beforeId, (int) $row['id']);
        $this->assertEquals('success', $row['status']);
        $this->assertEquals('newsha456', $row['sha']);
        $this->assertEquals('2026-08-18 10:10:00', $row['finished_at']);
    }

    public function testReportOverwritePreservesUnprovidedFields(): void
    {
        $provider = $this->makeProvider();
        $provider->report('jobA', [
            'pipeline_iid' => 35,
            'status'       => 'success',
            'finished_at'  => '2026-08-18 10:05:30',
            'ref'          => 'main',
            'sha'          => 'abc1234567',
            'log_url'      => 'http://logs.example/35',
            'web_url'      => 'http://ci.example/35',
            'started_at'   => '2026-08-18 10:00:00',
            'zone'         => 'az1',
        ]);

        // 重复上报只带必填字段（未带 log_url/ref/sha 等）→ 应保留首次写入的值，而非清空
        $second = $provider->report('jobA', [
            'pipeline_iid' => 35,
            'status'       => 'failed',
            'finished_at'  => '2026-08-18 10:20:00',
        ]);
        $this->assertTrue($second['success']);
        $this->assertEquals('updated', $second['action']);

        $row = $this->pdo->query('SELECT * FROM ' . AppConfig::TABLE_CUSTOM_BUILDS . ' WHERE job_name = "jobA" AND pipeline_iid = 35')->fetch();
        $this->assertEquals('failed', $row['status']);
        $this->assertEquals('2026-08-18 10:20:00', $row['finished_at']);
        // 未提供的字段保留原值
        $this->assertEquals('main', $row['ref']);
        $this->assertEquals('abc1234567', $row['sha']);
        $this->assertEquals('http://logs.example/35', $row['log_url']);
        $this->assertEquals('http://ci.example/35', $row['web_url']);
        $this->assertEquals('2026-08-18 10:00:00', $row['started_at']);
        // variables_json 同样保留
        $vars = json_decode($row['variables_json'], true);
        $this->assertSame(['zone' => 'az1'], $vars);
    }

    // ── findByIid ──

    public function testFindByIidReturnsNullWhenMissing(): void
    {
        $this->assertNull($this->makeProvider()->findByIid('jobA', 999));
    }

    public function testFindByIidReturnsRecord(): void
    {
        $provider = $this->makeProvider();
        $provider->report('jobA', ['pipeline_iid' => 40, 'status' => 'success', 'finished_at' => '2026-08-18 10:05:30']);

        $row = $provider->findByIid('jobA', 40);
        $this->assertIsArray($row);
        $this->assertEquals('jobA', $row['job_name']);
        $this->assertEquals(40, $row['pipeline_iid']);
    }

    // ── getPipelines / getJobs ──

    public function testGetPipelinesReturnsEmptyWhenNoRecords(): void
    {
        $this->assertSame([], $this->makeProvider()->getPipelines('jobA'));
    }

    public function testGetPipelinesMapsShapeAndOrdersDesc(): void
    {
        $provider = $this->makeProvider();
        $provider->report('jobA', ['pipeline_iid' => 1, 'status' => 'failed',  'finished_at' => '2026-08-18 10:01:00', 'sha' => 's1']);
        $provider->report('jobA', ['pipeline_iid' => 2, 'status' => 'success', 'finished_at' => '2026-08-18 10:02:00', 'sha' => 's2']);

        $pipes = $provider->getPipelines('jobA');
        $this->assertCount(2, $pipes);
        // 排序按 pipeline_iid DESC
        $this->assertEquals(2, $pipes[0]['iid']);
        $this->assertEquals('success', $pipes[0]['status']);
        $this->assertArrayHasKey('created_at', $pipes[0]);
        $this->assertArrayHasKey('updated_at', $pipes[0]);
        // updated_at = 完成时间（finished_at）
        $this->assertEquals('2026-08-18 10:02:00', $pipes[0]['updated_at']);
    }

    public function testGetJobsReturnsSingleBuildJob(): void
    {
        $provider = $this->makeProvider();
        $provider->report('jobA', ['pipeline_iid' => 80, 'status' => 'success', 'finished_at' => '2026-08-18 10:05:30']);
        $row = $provider->findByIid('jobA', 80);
        $id  = (int)$row['id'];

        $jobs = $provider->getJobs('jobA', $id);
        $this->assertCount(1, $jobs);
        $this->assertEquals('success', $jobs[0]['status']);
        $this->assertEquals('build', $jobs[0]['name']);
    }

    // ── getJobTrace ──

    public function testGetJobTraceWithoutLogUrlReturnsFallback(): void
    {
        $provider = $this->makeProvider();
        $provider->report('jobA', ['pipeline_iid' => 90, 'status' => 'success', 'finished_at' => '2026-08-18 10:05:30']);
        $row = $provider->findByIid('jobA', 90);
        $id  = (int)$row['id'];

        $trace = $provider->getJobTrace('jobA', $id);
        $this->assertStringContainsString('无构建日志', $trace);
    }

    // ── getVariables ──

    public function testGetVariablesEmptyByDefault(): void
    {
        $this->assertSame([], $this->makeProvider()->getVariables('jobA'));
    }

    public function testGetVariablesMapsStringAndArrayDefinitions(): void
    {
        $provider = $this->makeProvider([
            'variables' => [
                'zone'     => 'az1',                              // string 简写
                'branches' => ['type' => 'choice', 'choices' => ['master', 'dev'], 'description' => '分支'],
            ],
        ]);

        $vars = $provider->getVariables('jobA');
        $byKey = [];
        foreach ($vars as $v) $byKey[$v['key']] = $v;

        $this->assertArrayHasKey('zone', $byKey);
        $this->assertEquals('string', $byKey['zone']['type']);
        $this->assertEquals('az1', $byKey['zone']['defaultValue']);

        $this->assertArrayHasKey('branches', $byKey);
        $this->assertEquals('choice', $byKey['branches']['type']);
        $this->assertEquals(['master', 'dev'], $byKey['branches']['choices']);
    }

    // ── 不支持的操作 ──

    public function testRetryAndCancelAreNotSupported(): void
    {
        $provider = $this->makeProvider();
        $this->assertFalse($provider->retry('jobA', 1)['success']);
        $this->assertFalse($provider->cancel('jobA', 1)['success']);
    }

    public function testSetCommitStatusIsNotSupported(): void
    {
        $result = $this->makeProvider()->setCommitStatus('jobA', 'sha123', 'success', 'harbor-scan', 'x');
        $this->assertFalse($result['success']);
    }

    public function testGetBranchesReturnsEmptyWithoutGitService(): void
    {
        $this->assertSame([], $this->makeProvider()->getBranches('jobA'));
    }
}
