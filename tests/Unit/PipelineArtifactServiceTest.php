<?php

declare(strict_types=1);

namespace App\Test\Unit;

use App\Config\AppConfig;
use App\Service\Database;
use App\Service\PipelineArtifactService;
use App\Service\PipelineIdentity;
use PHPUnit\Framework\TestCase;

class PipelineArtifactServiceTest extends TestCase
{
    private \PDO $pdo;
    private PipelineArtifactService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Database::init(['driver' => 'sqlite', 'auto_migrate' => false]);
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
        $this->service = new PipelineArtifactService($this->pdo);
    }

    protected function tearDown(): void
    {
        Database::reset();
        parent::tearDown();
    }

    public function testCanonicalIdentityIsUnique(): void
    {
        $id = new PipelineIdentity('gitlab_ci', '123', 7);
        $this->assertSame('gitlab_ci:123:7', $id->key());

        $this->service->record($id, 'group/app', 'v1', 'repo/app', 'success', '2026-08-22 10:00:00', '2026-08-22 10:00:00');
        $this->service->record($id, 'group/app', 'v1.1', 'repo/app', 'success', '2026-08-22 10:01:00', '2026-08-22 10:01:00');

        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM ' . AppConfig::TABLE_PIPELINE_ARTIFACTS)->fetchColumn());
        $row = $this->service->find($id);
        $this->assertSame('v1.1', $row['tag']);
    }

    public function testOlderSourceEventCannotOverwriteNewerArtifact(): void
    {
        $id = new PipelineIdentity('custom_push', 'jobA', 20);
        $this->service->record($id, 'jobA', 'new', 'repo/app', 'success', '2026-08-22 10:10:00', '2026-08-22 10:10:00');
        $result = $this->service->record($id, 'jobA', 'old', 'repo/app', 'failed', '2026-08-22 10:09:00', '2026-08-22 10:09:00');

        $this->assertFalse($result['written']);
        $this->assertTrue($result['ignored']);
        $row = $this->service->find($id);
        $this->assertSame('new', $row['tag']);
        $this->assertSame('success', $row['status']);
    }

    public function testDifferentProvidersMayShareSameProjectAndPipelineNumber(): void
    {
        $this->service->record(new PipelineIdentity('jenkins', 'jobA', 1), 'jobA', 'j1', 'repo/app', 'success');
        $this->service->record(new PipelineIdentity('gitlab_ci', '123', 1), 'jobA', 'g1', 'repo/app', 'success');

        $this->assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM ' . AppConfig::TABLE_PIPELINE_ARTIFACTS)->fetchColumn());
    }
}
