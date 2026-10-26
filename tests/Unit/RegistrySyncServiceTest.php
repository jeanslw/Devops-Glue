<?php
declare(strict_types=1);

namespace App\Test\Unit;

use App\Config\AppConfig;
use App\Service\AppSettingRepository;
use App\Service\Build\BuildProviderRegistry;
use App\Service\I18nService;
use App\Service\MappingManager;
use App\Service\PipelineArtifactService;
use App\Service\Registry\RegistryProviderInterface;
use App\Service\RegistrySyncService;
use App\Service\Settings;
use App\Service\Git\GitProviderInterface;
use App\Service\Git\ProviderRegistry;
use PHPUnit\Framework\TestCase;

/**
 * RegistrySyncService 单元测试
 *
 * 覆盖 scan-sync 与 registry-sync 共用的回写流程：
 *   - ci_status 缺失或非法：返回 400，不写任何记录；
 *   - registry_repository 格式非法：返回 400；
 *   - 镜像仓库未配置：仍写入审计记录，scan_state 为 unknown；
 *   - tag 不在仓库 tag 列表中：只记日志，不阻断审计记录；
 *   - 仅 ci_status=success 写 ci_pipeline_artifacts；
 *   - scan-sync 对不支持扫描的仓库：scan_state 保持 unknown，check_type 为 harbor-scan。
 *
 * 依赖 SQLite 内存库 + mock RegistryProviderInterface，不触网。
 *
 * 运行：vendor/bin/phpunit tests/Unit/RegistrySyncServiceTest.php
 */
class RegistrySyncServiceTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

        $this->pdo->exec('CREATE TABLE ' . AppConfig::TABLE_JOB_GIT_MAP . ' (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            job_name TEXT NOT NULL,
            git_platform TEXT DEFAULT "",
            git_remote TEXT DEFAULT "",
            project_id INTEGER,
            web_url TEXT DEFAULT "",
            current_path TEXT DEFAULT "",
            registry_repository TEXT DEFAULT "",
            build_provider TEXT DEFAULT "",
            status TEXT DEFAULT "active",
            platform_source TEXT DEFAULT "manual",
            detection_method TEXT DEFAULT "",
            api_version TEXT DEFAULT ""
        )');

        $this->pdo->exec('CREATE TABLE ' . AppConfig::TABLE_PIPELINE_ARTIFACTS . ' (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            provider TEXT NOT NULL,
            project_id TEXT NOT NULL,
            pipeline_iid INTEGER NOT NULL,
            project_key TEXT DEFAULT "",
            repository TEXT DEFAULT "",
            tag TEXT DEFAULT "",
            status TEXT DEFAULT "",
            source_updated_at TEXT DEFAULT "",
            created_at TEXT DEFAULT "",
            updated_at TEXT DEFAULT ""
        )');

        $this->pdo->exec('CREATE TABLE ' . AppConfig::TABLE_SECURITY_CHECKS . ' (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            project TEXT NOT NULL,
            sha TEXT DEFAULT "",
            check_type TEXT DEFAULT "",
            state TEXT DEFAULT "",
            context TEXT DEFAULT "",
            description TEXT DEFAULT "",
            tag TEXT DEFAULT "",
            writeback_status TEXT DEFAULT "",
            writeback_message TEXT DEFAULT "",
            created_at TEXT DEFAULT ""
        )');

        $this->insertMap('team/app', 'team/app');
    }

    private function insertMap(string $jobName, string $repo): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO ' . AppConfig::TABLE_JOB_GIT_MAP
            . ' (job_name, git_platform, current_path, registry_repository, build_provider, status)'
            . ' VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$jobName, 'gitlab', $jobName, $repo, 'custom_push', 'active']);
    }

    private function registry(array $tags, bool $scanning = false): RegistryProviderInterface
    {
        return new class($tags, $scanning) implements RegistryProviderInterface {
            
            public function __construct(private array $tags, private bool $scanning)
            {
            }

            public function type(): string
            {
                return 'registry';
            }

            public function supportsScanning(): bool
            {
                return $this->scanning;
            }

            public function getTags(string $repository): array
            {
                return $this->tags;
            }

            public function getTagDetails(string $repository): array
            {
                return $this->tags;
            }

            public function repositoryExists(string $repository): bool
            {
                return true;
            }

            public function getProjects(): array
            {
                return [];
            }

            public function getRepositories(string $project): array
            {
                return [];
            }

            public function getScanReport(string $repository, string $tag): array
            {
                return ['error' => '当前镜像仓库类型（registry）不提供漏洞扫描接口'];
            }
        };
    }

    private function service(?RegistryProviderInterface $registry, ?ProviderRegistry $gitRegistry = null): RegistrySyncService
    {
        $settings = new Settings(['harbor' => ['url' => 'http://registry.test']]);

        return new RegistrySyncService(
            new I18nService(__DIR__ . '/../../lang', 'zh_CN'),
            new MappingManager(new AppSettingRepository($this->pdo), $this->pdo),
            new BuildProviderRegistry(),
            new PipelineArtifactService($this->pdo),
            $gitRegistry,
            $registry,
            $settings,
            $this->pdo
        );
    }

    /**
     * 构造一个记录 setCommitStatus 入参的假 Git Provider，用于断言写入 Git 的 state 合法。
     *
     * @param \ArrayObject<string,mixed> $capture
     */
    private function recordingGitRegistry(\ArrayObject $capture): ProviderRegistry
    {
        $registry = new ProviderRegistry();
        $registry->register('gitlab', fn() => true, fn() => new class($capture) implements GitProviderInterface {
            public function __construct(private \ArrayObject $capture)
            {
            }

            public function getBranches(string $repository): array
            {
                return [];
            }

            public function getTags(string $repository): array
            {
                return [];
            }

            public function setCommitStatus(string $repository, string $sha, string $state, string $context, string $description, string $targetUrl = ''): array
            {
                $this->capture['state']   = $state;
                $this->capture['context'] = $context;
                return ['success' => true, 'message' => 'ok'];
            }

            public function getName(): string
            {
                return 'gitlab';
            }

            public function matchUrl(string $url): bool
            {
                return true;
            }

            public function getApiVersion(): string
            {
                return 'v4';
            }
        });
        return $registry;
    }

    /** @return array<int,array<string,mixed>> */
    private function audits(): array
    {
        return $this->pdo->query('SELECT * FROM ' . AppConfig::TABLE_SECURITY_CHECKS . ' ORDER BY id')->fetchAll();
    }

    /** @return array<int,array<string,mixed>> */
    private function artifacts(): array
    {
        return $this->pdo->query('SELECT * FROM ' . AppConfig::TABLE_PIPELINE_ARTIFACTS . ' ORDER BY id')->fetchAll();
    }

    public function testMissingCiStatusReturns400(): void
    {
        $result = $this->service($this->registry(['v1']))->sync('team/app', [], false, 'registry-sync');

        $this->assertSame(400, $result['http_status']);
        $this->assertSame([], $this->audits());
        $this->assertSame([], $this->artifacts());
    }

    public function testInvalidRepositoryFormatReturns400(): void
    {
        $this->pdo->exec('UPDATE ' . AppConfig::TABLE_JOB_GIT_MAP . " SET registry_repository = 'https://registry.test/team/app'");

        $result = $this->service($this->registry(['v1']))
            ->sync('team/app', ['ci_status' => 'success', 'tag' => 'v1'], false, 'registry-sync');

        $this->assertSame(400, $result['http_status']);
        $this->assertSame([], $this->audits());
    }

    public function testUnconfiguredRegistryStillWritesAudit(): void
    {
        $result = $this->service(null)->sync('team/app', [
            'ci_status' => 'success',
            'tag'       => 'v1',
            'sha'       => 'abc123',
            'pipeline_iid' => 10,
        ], false, 'registry-sync');

        $this->assertSame('unknown', $result['scan_state']);
        $this->assertSame('abc123', $result['sha']);
        $this->assertSame('v1', $result['tag']);

        $audits = $this->audits();
        $this->assertCount(1, $audits);
        $this->assertSame('registry-sync', $audits[0]['check_type']);
        $this->assertSame('success', $audits[0]['writeback_status']);
    }

    public function testTagNotInRegistryDoesNotBlockAudit(): void
    {
        $result = $this->service($this->registry(['v9']))->sync('team/app', [
            'ci_status' => 'success',
            'tag'       => 'v1',
            'sha'       => 'abc123',
            'pipeline_iid' => 10,
        ], false, 'registry-sync');

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame('v1', $result['tag']);

        $audits = $this->audits();
        $this->assertCount(1, $audits);
        $this->assertSame('registry-sync', $audits[0]['check_type']);
    }

    public function testTagFallsBackToLatestWhenOmitted(): void
    {
        $result = $this->service($this->registry(['v3', 'v2']))->sync('team/app', [
            'ci_status' => 'success',
            'sha'       => 'abc123',
            'pipeline_iid' => 10,
        ], false, 'registry-sync');

        $this->assertSame('v3', $result['tag']);
    }

    public function testArtifactWrittenForSuccess(): void
    {
        $this->service($this->registry(['v1']))->sync('team/app', [
            'ci_status' => 'success',
            'tag'       => 'v1',
            'sha'       => 'abc123',
            'pipeline_iid' => 10,
        ], false, 'registry-sync');

        $this->assertCount(1, $this->artifacts());
    }

    public function testFailedStatusWritesAuditButNoArtifact(): void
    {
        
        
        
        
        
        $this->service($this->registry(['v1']))->sync('team/app', [
            'ci_status' => 'failed',
            'tag'       => 'v1',
            'sha'       => 'abc123',
            'pipeline_iid' => 10,
        ], false, 'registry-sync');

        $this->assertSame([], $this->artifacts(), 'failed 不得产生可部署产物');

        $audits = $this->audits();
        $this->assertCount(1, $audits, 'failed 也必须留审计记录');
        $this->assertSame('registry-sync', $audits[0]['check_type']);
        $this->assertSame('skipped', $audits[0]['writeback_status'], '非 success 不得写成推送事实');
        $this->assertSame('v1', $audits[0]['tag']);
        $this->assertStringContainsString('ci_status=failed', (string) $audits[0]['writeback_message']);
        $this->assertStringContainsString(
            'artifact_write=skipped (ci_status=failed)',
            (string) $audits[0]['writeback_message']
        );
    }

    public function testRunningStatusWritesAuditButNoArtifact(): void
    {
        
        $this->service($this->registry(['v1']))->sync('team/app', [
            'ci_status' => 'running',
            'tag'       => 'v1',
            'sha'       => 'abc123',
            'pipeline_iid' => 10,
        ], false, 'registry-sync');

        $this->assertSame([], $this->artifacts(), 'running 不得产生可部署产物');

        $audits = $this->audits();
        $this->assertCount(1, $audits, 'running 也必须留审计记录');
        $this->assertSame('skipped', $audits[0]['writeback_status']);
        $this->assertStringContainsString('ci_status=running', (string) $audits[0]['writeback_message']);
    }

    public function testScanSyncKeepsUnknownScanStateForNonScanningRegistry(): void
    {
        $result = $this->service($this->registry(['v1']))->sync('team/app', [
            'ci_status' => 'success',
            'tag'       => 'v1',
            'sha'       => 'abc123',
            'pipeline_iid' => 10,
        ], true, 'harbor-scan');

        $this->assertSame('unknown', $result['scan_state']);
        $this->assertSame(0, $result['vulnerability_count']);

        $audits = $this->audits();
        $this->assertCount(1, $audits);
        $this->assertSame('harbor-scan', $audits[0]['check_type']);
    }

    public function testCommitStatusStateIsGitValidWhenNoScanSuccess(): void
    {
        $capture = new \ArrayObject();
        $gitRegistry = $this->recordingGitRegistry($capture);

        $result = $this->service($this->registry(['v1']), $gitRegistry)->sync('team/app', [
            'ci_status'    => 'success',
            'tag'          => 'v1',
            'sha'          => 'abc123',
            'pipeline_iid' => 10,
        ], false, 'registry-sync');

        
        $this->assertSame('unknown', $result['scan_state']);
        $this->assertSame('success', $capture['state']);
        $this->assertSame('registry-sync', $capture['context']);
        $this->assertSame(['success' => true, 'message' => 'ok'], $result['commit_status']);
    }

    public function testCommitStatusStateDerivesPendingFromRunningCiStatus(): void
    {
        $capture = new \ArrayObject();
        $gitRegistry = $this->recordingGitRegistry($capture);

        $result = $this->service($this->registry(['v1']), $gitRegistry)->sync('team/app', [
            'ci_status'    => 'running',
            'tag'          => 'v1',
            'sha'          => 'abc123',
            'pipeline_iid' => 10,
        ], false, 'registry-sync');

        $this->assertSame('unknown', $result['scan_state']);
        $this->assertSame('pending', $capture['state']);
    }
}
