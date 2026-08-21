<?php
declare(strict_types=1);

namespace App\Test\Unit;

use App\Config\AppConfig;
use App\Service\MappingManager;
use PHPUnit\Framework\TestCase;

/**
 * MappingManager::resolveProject 单元测试
 *
 * 专项锁定 custom_push 的推送名称归一化：无论用户 CI 推 `job_name` 还是
 * `current_path`，都归一化到 `job_name`（映射主键，跨 build_provider 切换的稳定身份）。
 * 这样 jenkins ↔ custom_push 互相切换时 ci_pipeline_tags.project 始终一致，
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
            harbor_repository TEXT,
            api_version TEXT,
            status TEXT DEFAULT "active"
        )');
        $this->pdo->exec('CREATE TABLE ' . AppConfig::TABLE_APP_SETTINGS . ' (
            setting_key TEXT PRIMARY KEY,
            value TEXT,
            updated_at TEXT
        )');
        // both 模式 + custom_push 开启 → activeMaps 保留 jenkins / gitlab_ci / custom_push 全部 provider
        $this->pdo->exec("INSERT INTO " . AppConfig::TABLE_APP_SETTINGS . " (setting_key, value) VALUES ('build_mode', 'both'), ('custom_push_enabled', '1')");
    }

    private function insertMap(array $row): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . AppConfig::TABLE_JOB_GIT_MAP . '
             (job_name, git_platform, build_provider, git_remote, project_id, web_url, current_path, harbor_repository, status)
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
            $row['harbor_repository'] ?? null,
            $row['status'] ?? AppConfig::STATUS_ACTIVE,
        ]);
    }

    private function makeManager(): MappingManager
    {
        return new MappingManager(new AppConfig([], $this->pdo));
    }

    public function testCustomPushByCurrentPathNormalizesToJobName(): void
    {
        // Jenkins 命名差异：job_name=java/registry，current_path=tools/registry
        $this->insertMap([
            'job_name'       => 'java/registry',
            'build_provider' => AppConfig::PROVIDER_CUSTOM_PUSH,
            'current_path'   => 'tools/registry',
        ]);

        // 推 current_path → 归一化到 job_name（映射主键，与 Jenkins 身份一致）
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

        // 推 job_name → 还是 job_name（推 job_name / current_path 收敛到同一条）
        $r = $this->makeManager()->resolveProject('java/registry');
        $this->assertSame(AppConfig::PROVIDER_CUSTOM_PUSH, $r['provider']);
        $this->assertSame('java/registry', $r['projectId']);
    }

    public function testCustomPushFallsBackToCurrentPathWhenJobNameEmpty(): void
    {
        // job_name 是主键，正常不会为空；此处锁定兜底顺序：job_name → current_path
        $this->insertMap([
            'job_name'       => '',
            'build_provider' => AppConfig::PROVIDER_CUSTOM_PUSH,
            'current_path'   => 'tools/registry',
        ]);

        $r = $this->makeManager()->resolveProject('tools/registry');
        $this->assertSame(AppConfig::PROVIDER_CUSTOM_PUSH, $r['provider']);
        $this->assertSame('tools/registry', $r['projectId']);
    }

    public function testJenkinsKeepsRawPath(): void
    {
        $this->insertMap([
            'job_name'       => 'java/registry',
            'build_provider' => AppConfig::PROVIDER_JENKINS,
            'current_path'   => 'tools/registry',
        ]);

        $r = $this->makeManager()->resolveProject('java/registry');
        $this->assertSame(AppConfig::PROVIDER_JENKINS, $r['provider']);
        // jenkins 不做归一化，projectId 保持原始 path
        $this->assertSame('java/registry', $r['projectId']);
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
}
