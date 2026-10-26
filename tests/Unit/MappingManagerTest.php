<?php
declare(strict_types=1);

namespace App\Test\Unit;

use App\Config\AppConfig;
use App\Service\AppSettingRepository;
use App\Service\MappingManager;
use PHPUnit\Framework\TestCase;

/**
 * MappingManager::resolveProject 单元测试
 *
 * 专项锁定 custom_push 的推送名称归一化：无论用户 CI 推 `job_name` 还是
 * `current_path`，都归一化到 `job_name`（映射主键，跨 build_provider 切换的稳定身份）。
 * 这样 jenkins ↔ custom_push 互相切换时 ci_pipeline_artifacts.project_key 始终一致，
 * 不会因 current_path 与 job_name 不同名而把同一项目当成两条。
 *
 * 依赖 SQLite 内存库（ci_job_git_map / ci_app_settings 两表），不触网。
 *
 * 运行：vendor/bin/phpunit tests/Unit/MappingManagerTest.php
 */
class MappingManagerTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

        $this->pdo->exec('CREATE TABLE ' . AppConfig::TABLE_JOB_GIT_MAP . ' (
            job_name TEXT PRIMARY KEY,
            git_platform TEXT,
            build_provider TEXT,
            git_remote TEXT,
            project_id TEXT,
            web_url TEXT,
            current_path TEXT,
            registry_repository TEXT,
            api_version TEXT,
            status TEXT DEFAULT "active"
        )');
        $this->pdo->exec('CREATE TABLE ' . AppConfig::TABLE_APP_SETTINGS . ' (
            setting_key TEXT PRIMARY KEY,
            value TEXT,
            updated_at TEXT
        )');
        
        $this->pdo->exec("INSERT INTO " . AppConfig::TABLE_APP_SETTINGS . " (setting_key, value) VALUES ('build_mode', 'both'), ('custom_push_enabled', '1')");
    }

    private function insertMap(array $row): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . AppConfig::TABLE_JOB_GIT_MAP . '
             (job_name, git_platform, build_provider, git_remote, project_id, web_url, current_path, registry_repository, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $row['job_name'],
            $row['git_platform'] ?? null,
            $row['build_provider'] ?? AppConfig::PROVIDER_JENKINS,
            $row['git_remote'] ?? null,
            $row['project_id'] ?? null,
            $row['web_url'] ?? null,
            $row['current_path'] ?? null,
            $row['registry_repository'] ?? null,
            $row['status'] ?? AppConfig::STATUS_ACTIVE,
        ]);
    }

    private function makeManager(): MappingManager
    {
        return new MappingManager(new AppSettingRepository($this->pdo), $this->pdo);
    }

    public function testCustomPushByCurrentPathNormalizesToJobName(): void
    {
        
        $this->insertMap([
            'job_name'       => 'java/registry',
            'build_provider' => AppConfig::PROVIDER_CUSTOM_PUSH,
            'current_path'   => 'tools/registry',
        ]);

        
        $r = $this->makeManager()->resolveProject('tools/registry');
        $this->assertSame(AppConfig::PROVIDER_CUSTOM_PUSH, $r['provider']);
        $this->assertSame('java/registry', $r['projectId']);
    }

    public function testCustomPushByJobNameIsIdempotent(): void
    {
        $this->insertMap([
            'job_name'       => 'java/registry',
            'build_provider' => AppConfig::PROVIDER_CUSTOM_PUSH,
            'current_path'   => 'tools/registry',
        ]);

        
        $r = $this->makeManager()->resolveProject('java/registry');
        $this->assertSame(AppConfig::PROVIDER_CUSTOM_PUSH, $r['provider']);
        $this->assertSame('java/registry', $r['projectId']);
    }

    public function testCustomPushFallsBackToCurrentPathWhenJobNameEmpty(): void
    {
        
        $this->insertMap([
            'job_name'       => '',
            'build_provider' => AppConfig::PROVIDER_CUSTOM_PUSH,
            'current_path'   => 'tools/registry',
        ]);

        $r = $this->makeManager()->resolveProject('tools/registry');
        $this->assertSame(AppConfig::PROVIDER_CUSTOM_PUSH, $r['provider']);
        $this->assertSame('tools/registry', $r['projectId']);
    }

    public function testJenkinsByJobNameIsIdempotent(): void
    {
        $this->insertMap([
            'job_name'       => 'java/registry',
            'build_provider' => AppConfig::PROVIDER_JENKINS,
            'current_path'   => 'tools/registry',
        ]);

        $r = $this->makeManager()->resolveProject('java/registry');
        $this->assertSame(AppConfig::PROVIDER_JENKINS, $r['provider']);
        
        $this->assertSame('java/registry', $r['projectId']);
    }

    public function testJenkinsByCurrentPathResolvesToJobName(): void
    {
        
        
        
        $this->insertMap([
            'job_name'       => 'java/registry',
            'build_provider' => AppConfig::PROVIDER_JENKINS,
            'current_path'   => 'tools/registry',
        ]);

        $r = $this->makeManager()->resolveProject('tools/registry');
        $this->assertSame(AppConfig::PROVIDER_JENKINS, $r['provider']);
        $this->assertSame('java/registry', $r['projectId']);
    }

    public function testDisabledProviderJobNameNotHijackedByOtherProviderCurrentPath(): void
    {
        
        
        
        $this->insertMap([
            'job_name'       => 'jeanslw/Devops_CD',
            'build_provider' => AppConfig::PROVIDER_GITEA_CI,
            'current_path'   => 'jeanslw/Devops_CD',
            'status'         => AppConfig::STATUS_PENDING,
        ]);
        $this->insertMap([
            'job_name'       => 'python/Devops_CD',
            'build_provider' => AppConfig::PROVIDER_JENKINS,
            'current_path'   => 'jeanslw/Devops_CD',
            'status'         => AppConfig::STATUS_ACTIVE,
        ]);

        $r = $this->makeManager()->resolveProject('jeanslw/Devops_CD');
        
        $this->assertSame(AppConfig::PROVIDER_GITEA_CI, $r['provider']);
        $this->assertSame('jeanslw/Devops_CD', $r['projectId']);
    }

    public function testDashPathIsNotNormalizedToSlashJob(): void
    {
        
        
        $this->insertMap([
            'job_name'       => 'java/registry',
            'build_provider' => AppConfig::PROVIDER_JENKINS,
            'current_path'   => 'java/registry',
        ]);

        $r = $this->makeManager()->resolveProject('java-registry');
        
        $this->assertSame('java-registry', $r['projectId']);
    }

    public function testGitlabCiUsesNumericProjectId(): void
    {
        $this->insertMap([
            'job_name'       => 'glproject',
            'build_provider' => AppConfig::PROVIDER_GITLAB_CI,
            'project_id'     => '42',
            'current_path'   => 'glproject',
        ]);

        $r = $this->makeManager()->resolveProject('glproject');
        $this->assertSame(AppConfig::PROVIDER_GITLAB_CI, $r['provider']);
        $this->assertSame('42', $r['projectId']);
    }

    public function testGiteaCiUsesJobNameAsProjectId(): void
    {
        
        $this->pdo->exec("UPDATE " . AppConfig::TABLE_APP_SETTINGS . " SET value='gitea_ci' WHERE setting_key='build_mode'");
        $this->insertMap([
            'job_name'       => 'org/repo',
            'build_provider' => AppConfig::PROVIDER_GITEA_CI,
            'current_path'   => 'org/repo',
        ]);

        $r = $this->makeManager()->resolveProject('org/repo');
        $this->assertSame(AppConfig::PROVIDER_GITEA_CI, $r['provider']);
        $this->assertSame('org/repo', $r['projectId']);
    }

    public function testSaveMapOnlyTouchesOneRow(): void
    {
        
        $this->insertMap(['job_name' => 'job-a', 'current_path' => 'job-a']);
        $this->insertMap(['job_name' => 'job-b', 'current_path' => 'job-b']);

        $this->makeManager()->saveMap([
            'job_name'       => 'job-a',
            'current_path'   => 'job-a',
            'registry_repository' => 'lib/job-a',
        ]);

        $rows = $this->pdo->query('SELECT job_name, registry_repository FROM ' . AppConfig::TABLE_JOB_GIT_MAP . ' ORDER BY job_name')->fetchAll();
        $this->assertCount(2, $rows);
        $this->assertSame('lib/job-a', $rows[0]['registry_repository']);
        $this->assertNull($rows[1]['registry_repository']);
    }

    public function testSaveMapsRejectsEmptyData(): void
    {
        
        $this->insertMap(['job_name' => 'job-a', 'current_path' => 'job-a']);

        $this->expectException(\InvalidArgumentException::class);
        try {
            $this->makeManager()->saveMaps([]);
        } finally {
            $count = (int) $this->pdo->query('SELECT count(*) FROM ' . AppConfig::TABLE_JOB_GIT_MAP)->fetchColumn();
            $this->assertSame(1, $count);
        }
    }
}
