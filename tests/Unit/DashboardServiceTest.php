<?php
declare(strict_types=1);

namespace App\Test\Unit;

use App\Config\AppConfig;
use App\Service\Build\BuildProviderRegistry;
use App\Service\DashboardService;
use App\Service\MappingManager;
use PHPUnit\Framework\TestCase;

/**
 * DashboardService 回归测试（内存 SQLite）
 *
 * 锁定看板只读 API 的「数据源严格隔离」约定：
 *  - getMapping()      只读 ci_job_git_map
 *  - getDeploymentData() 只读 cd_deploy_logs（表缺失 → 空数组，不 500）
 *  - getBuildData()    ci_custom_builds + jenkins/gitlab 实时流水线（provider 未配置 → error 降级）
 *  - getTrends()       时序聚合
 *
 * 字段名以真实建表脚本为准，本测试用与 database/*_init.sql、Devops_CD init_mysql.sql
 * 一致的列名建表，任何臆造列名都会被 SQL 报错暴露。
 */
class DashboardServiceTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
    }

    private function makeService(?string $skipTable = null): DashboardService
    {
        if ($skipTable !== 'cd_deploy_logs') {
            $this->pdo->exec('CREATE TABLE cd_deploy_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT, deploy_id INTEGER DEFAULT 0, project TEXT, tag TEXT,
                image TEXT, deploy_type TEXT, target TEXT, status TEXT, output TEXT,
                triggered_by TEXT DEFAULT "", deploy_note TEXT DEFAULT "", duration_ms INTEGER DEFAULT 0,
                stage_times TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
        }
        if ($skipTable !== 'ci_custom_builds') {
            $this->pdo->exec('CREATE TABLE ' . AppConfig::TABLE_CUSTOM_BUILDS . ' (
                id INTEGER PRIMARY KEY AUTOINCREMENT, job_name TEXT NOT NULL, pipeline_iid INTEGER NOT NULL,
                ref TEXT, sha TEXT, variables_json TEXT, status TEXT DEFAULT "pending", exit_code INTEGER,
                log_url TEXT, web_url TEXT, triggered_at TEXT, started_at TEXT, finished_at TEXT,
                UNIQUE (job_name, pipeline_iid))');
        }
        $this->pdo->exec('CREATE TABLE ' . AppConfig::TABLE_JOB_GIT_MAP . ' (
            job_name TEXT PRIMARY KEY, git_platform TEXT, build_provider TEXT DEFAULT "jenkins",
            git_remote TEXT, project_id INTEGER, web_url TEXT, current_path TEXT,
            harbor_repository TEXT, api_version TEXT, status TEXT DEFAULT "active")');
        $this->pdo->exec('CREATE TABLE ' . AppConfig::TABLE_PIPELINE_TAGS . ' (
            project TEXT NOT NULL, pipeline_iid INTEGER NOT NULL, tag TEXT NOT NULL,
            harbor_repository TEXT, status TEXT DEFAULT "", created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (project, pipeline_iid))');
        $this->pdo->exec('CREATE TABLE ci_app_settings (setting_key TEXT PRIMARY KEY, value TEXT NOT NULL, updated_at TEXT)');
        $this->pdo->exec("INSERT INTO ci_app_settings (setting_key, value) VALUES ('build_mode','both'), ('custom_push_enabled','0')");

        $config  = new AppConfig([], $this->pdo);
        $mapping = new MappingManager($config);
        return new DashboardService($this->pdo, new BuildProviderRegistry(), $mapping);
    }

    public function testGetMappingOnlyExposesJobGitMapFields(): void
    {
        $svc = $this->makeService();
        $this->pdo->exec("INSERT INTO " . AppConfig::TABLE_JOB_GIT_MAP . "
            (job_name, git_platform, build_provider, git_remote, web_url, current_path, harbor_repository, status) VALUES
            ('myapp-backend', 'gitlab', 'jenkins', 'git@x/backend.git', 'https://x/backend', 'myapp/backend', 'mycode/backend', 'active')");

        $rows = $svc->getMapping();

        $this->assertCount(1, $rows);
        $row = $rows[0];
        // 只保留 job_git_map 字段，不得混入 cd_deploy_logs / ci_custom_builds 列
        $this->assertSame(
            ['job_name', 'git_platform', 'build_provider', 'project_id', 'git_remote', 'web_url', 'current_path', 'harbor_repository', 'map_status'],
            array_keys($row)
        );
        $this->assertSame('myapp-backend', $row['job_name']);
        $this->assertSame('jenkins', $row['build_provider']);
    }

    public function testGetDeploymentDataOnlyExposesCdDeployLogsFields(): void
    {
        $svc = $this->makeService();
        $this->pdo->exec("INSERT INTO cd_deploy_logs (deploy_id, project, tag, image, deploy_type, target, status, triggered_by, created_at) VALUES
            (1, 'myapp-backend', 'v1.0', 'harbor/mycode/backend:v1.0', 'k8s', 'prod', 'success', 'admin', '2026-08-22 11:00:00')");

        $rows = $svc->getDeploymentData();

        $this->assertCount(1, $rows);
        $this->assertSame(
            ['id', 'deploy_id', 'project', 'tag', 'image', 'deploy_type', 'target', 'status', 'triggered_by', 'created_at'],
            array_keys($rows[0])
        );
        $this->assertSame('success', $rows[0]['status']);
    }

    public function testGetDeploymentDataReturnsEmptyWhenTableMissing(): void
    {
        $svc = $this->makeService('cd_deploy_logs'); // 不建 cd_deploy_logs
        $this->assertSame([], $svc->getDeploymentData());
    }

    public function testGetBuildDataSeparatesCustomBuildsAndJenkinsGitlab(): void
    {
        $svc = $this->makeService();
        $this->pdo->exec("INSERT INTO " . AppConfig::TABLE_JOB_GIT_MAP . "
            (job_name, git_platform, build_provider, git_remote, web_url, current_path, harbor_repository, status) VALUES
            ('myapp-backend', 'gitlab', 'jenkins', 'git@x/backend.git', 'https://x/backend', 'myapp/backend', 'mycode/backend', 'active'),
            ('myapp-worker', 'gitlab', 'custom_push', '', '', 'myapp/worker', 'mycode/worker', 'active')");
        $this->pdo->exec("INSERT INTO " . AppConfig::TABLE_CUSTOM_BUILDS . "
            (job_name, pipeline_iid, ref, sha, status, exit_code, log_url, web_url, triggered_at, finished_at) VALUES
            ('myapp-worker', 12, 'main', 'abc123', 'success', 0, 'https://log/12', 'https://build/12', '2026-08-23 10:00:00', '2026-08-23 10:05:00')");

        $data = $svc->getBuildData();

        $this->assertArrayHasKey('custom_builds', $data);
        $this->assertArrayHasKey('jenkins_gitlab', $data);

        // custom_builds 只含 ci_custom_builds 字段
        $this->assertCount(1, $data['custom_builds']);
        $this->assertSame(
            ['id', 'job_name', 'pipeline_iid', 'ref', 'sha', 'status', 'exit_code', 'log_url', 'web_url', 'triggered_at', 'started_at', 'finished_at'],
            array_keys($data['custom_builds'][0])
        );

        // jenkins_gitlab 只含 jenkins（custom_push 被 activeMaps 过滤），空 registry 降级为 error
        $this->assertCount(1, $data['jenkins_gitlab']);
        $this->assertSame('jenkins', $data['jenkins_gitlab'][0]['provider']);
        $this->assertSame('provider 未配置', $data['jenkins_gitlab'][0]['error']);
    }

    public function testGetTrendsAggregatesTagsAndDeploysByDay(): void
    {
        $svc = $this->makeService();
        $this->pdo->exec("INSERT INTO " . AppConfig::TABLE_PIPELINE_TAGS . "
            (project, pipeline_iid, tag, harbor_repository, status, created_at) VALUES
            ('myapp-backend', 100, 'v1.0', 'mycode/backend', 'success', '2026-08-22 10:00:00')");
        $this->pdo->exec("INSERT INTO cd_deploy_logs (deploy_id, project, tag, image, deploy_type, target, status, triggered_by, created_at) VALUES
            (1, 'myapp-backend', 'v1.0', 'harbor/mycode/backend:v1.0', 'k8s', 'prod', 'success', 'admin', '2026-08-22 11:00:00'),
            (2, 'myapp-backend', 'v1.0', 'harbor/mycode/backend:v1.0', 'k8s', 'prod', 'failed', 'admin', '2026-08-22 12:00:00')");

        $trends = $svc->getTrends('2026-08-22', '2026-08-23');

        $this->assertSame('2026-08-22', $trends['from']);
        $this->assertSame('2026-08-23', $trends['to']);
        $this->assertSame([['day' => '2026-08-22', 'cnt' => 1]], $trends['tags']);
        $this->assertCount(1, $trends['deploys']);
        $this->assertSame(2, $trends['deploys'][0]['total']);
        $this->assertSame(1, $trends['deploys'][0]['success']);
        $this->assertSame(1, $trends['deploys'][0]['failed']);
    }
}
