<?php
declare(strict_types=1);

namespace App\Test\Unit;

use App\Config\AppConfig;
use App\Service\ApiAccessLogRepository;
use PHPUnit\Framework\TestCase;

/**
 * ApiAccessLogRepository 单元测试（内存 SQLite）。
 *
 * 锁定审计日志的安全/功能契约：
 *   - record() 静默写、超长截断、空串归一为 null；
 *   - list() 筛选/分页，默认近 30 天窗口；
 *   - purge() 按保留期删除；
 *   - 全程不接触 token 原文（调用方责任，仓储只给入口）。
 */
class ApiAccessLogRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private ApiAccessLogRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        // 与 database/sqlite_init.sql 8.2 同构
        $this->pdo->exec('CREATE TABLE ' . AppConfig::TABLE_API_ACCESS_LOGS . ' (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT DEFAULT "",
            token_name TEXT DEFAULT "",
            scopes TEXT DEFAULT "",
            method TEXT NOT NULL,
            route TEXT NOT NULL,
            status_code INTEGER NOT NULL DEFAULT 0,
            result TEXT NOT NULL DEFAULT "success",
            error_reason TEXT DEFAULT "",
            ip TEXT DEFAULT "",
            duration_ms INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL
        )');
        $this->repo = new ApiAccessLogRepository($this->pdo);
    }

    private function insertRow(array $overrides = [], ?string $createdAt = null): void
    {
        $row = array_merge([
            'username'     => 'ci-bot',
            'token_name'   => 'ci-bot-token',
            'scopes'       => 'main,git',
            'method'       => 'GET',
            'route'        => '/api/main/map/list',
            'status_code'  => 200,
            'result'       => 'success',
            'error_reason' => null,
            'ip'           => '10.0.0.1',
            'duration_ms'  => 12,
        ], $overrides);
        $createdAt ??= date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . AppConfig::TABLE_API_ACCESS_LOGS
            . ' (username, token_name, scopes, method, route, status_code, result, error_reason, ip, duration_ms, created_at)'
            . ' VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            $row['username'], $row['token_name'], $row['scopes'], $row['method'], $row['route'],
            $row['status_code'], $row['result'], $row['error_reason'], $row['ip'],
            $row['duration_ms'], $createdAt,
        ]);
    }

    public function testRecordPersistsEntry(): void
    {
        $this->repo->record([
            'username' => 'ci-bot', 'token_name' => 'deploy-token', 'scopes' => 'main',
            'method' => 'POST', 'route' => '/api/build/x/trigger', 'status_code' => 201,
            'result' => 'success', 'error_reason' => null, 'ip' => '10.0.0.2', 'duration_ms' => 35,
        ]);

        $row = $this->pdo->query('SELECT * FROM ' . AppConfig::TABLE_API_ACCESS_LOGS)->fetch();
        $this->assertSame('deploy-token', $row['token_name']);
        $this->assertSame('POST', $row['method']);
        $this->assertSame('/api/build/x/trigger', $row['route']);
        $this->assertEquals(201, $row['status_code']);
        $this->assertSame('success', $row['result']);
        $this->assertNull($row['error_reason']);
        $this->assertNotEmpty($row['created_at']);
    }

    public function testRecordTruncatesLongFieldsAndNullsBlankStrings(): void
    {
        $this->repo->record([
            'username' => '   ', 'token_name' => str_repeat('a', 400), 'scopes' => str_repeat('s', 600),
            'method' => 'GET', 'route' => str_repeat('r', 600), 'status_code' => 200,
            'result' => 'success', 'error_reason' => '   ', 'ip' => '1.2.3.4', 'duration_ms' => 1,
        ]);

        $row = $this->pdo->query('SELECT * FROM ' . AppConfig::TABLE_API_ACCESS_LOGS)->fetch();
        $this->assertNull($row['username'], '空白字符串归一为 null');
        $this->assertNull($row['error_reason']);
        $this->assertSame(255, mb_strlen($row['token_name']));
        $this->assertSame(500, mb_strlen($row['scopes']));
        $this->assertSame(500, mb_strlen($row['route']));
    }

    public function testRecordNeverThrowsEvenWhenTableMissing(): void
    {
        $this->pdo->exec('DROP TABLE ' . AppConfig::TABLE_API_ACCESS_LOGS);
        // 不抛出即通过（静默降级）
        $this->repo->record([
            'username' => 'u', 'token_name' => 't', 'scopes' => '', 'method' => 'GET',
            'route' => '/api/health', 'status_code' => 500, 'result' => 'failure',
            'error_reason' => 'boom', 'ip' => '', 'duration_ms' => 0,
        ]);
        $this->assertTrue(true);
    }

    public function testListFiltersByResultMethodAndKeyword(): void
    {
        $this->insertRow(['result' => 'success', 'method' => 'GET', 'token_name' => 'alpha-token', 'route' => '/api/main/map/list', 'status_code' => 200]);
        $this->insertRow(['result' => 'failure', 'method' => 'POST', 'token_name' => 'beta-token', 'route' => '/api/build/x/trigger', 'status_code' => 500, 'error_reason' => 'http_500']);
        $this->insertRow(['result' => 'denied', 'method' => 'GET', 'token_name' => 'gamma-token', 'route' => '/api/admin/users', 'status_code' => 403, 'error_reason' => 'scope_forbidden']);

        $r = $this->repo->list(['result' => 'denied'], 1, 20);
        $this->assertSame(1, $r['total']);
        $this->assertSame('gamma-token', $r['items'][0]['token_name']);

        $r = $this->repo->list(['method' => 'post'], 1, 20);
        $this->assertSame(1, $r['total']);
        $this->assertSame('POST', $r['items'][0]['method']);

        $r = $this->repo->list(['token_name' => 'alpha'], 1, 20);
        $this->assertSame(1, $r['total']);

        $r = $this->repo->list(['route' => 'trigger'], 1, 20);
        $this->assertSame(1, $r['total']);
    }

    public function testListDefaultWindowExcludesRowsOlderThan30Days(): void
    {
        $this->insertRow(['token_name' => 'recent']);
        $this->insertRow(['token_name' => 'old'], date('Y-m-d H:i:s', time() - 31 * 86400));

        // 默认窗口：只看到近 30 天
        $r = $this->repo->list([], 1, 20);
        $this->assertSame(1, $r['total']);
        $this->assertSame('recent', $r['items'][0]['token_name']);

        // 显式宽日期范围：两条都在
        $r = $this->repo->list(['date_from' => date('Y-m-d', time() - 60 * 86400)], 1, 20);
        $this->assertSame(2, $r['total']);
    }

    public function testListPaginates(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->insertRow(['token_name' => "tok-{$i}"]);
        }
        $r = $this->repo->list([], 1, 2);
        $this->assertSame(5, $r['total']);
        $this->assertSame(3, $r['total_pages']);
        $this->assertCount(2, $r['items']);
        // 倒序：最新插入在前
        $this->assertSame('tok-4', $r['items'][0]['token_name']);

        $r2 = $this->repo->list([], 3, 2);
        $this->assertCount(1, $r2['items']);
        $this->assertSame('tok-0', $r2['items'][0]['token_name']);
    }

    public function testListNeverThrowsWhenTableMissing(): void
    {
        $this->pdo->exec('DROP TABLE ' . AppConfig::TABLE_API_ACCESS_LOGS);
        $r = $this->repo->list([], 1, 20);
        $this->assertSame(0, $r['total']);
        $this->assertSame([], $r['items']);
    }

    public function testPurgeDeletesOnlyExpiredRows(): void
    {
        $this->insertRow(['token_name' => 'fresh'], date('Y-m-d H:i:s', time() - 10 * 86400));
        $this->insertRow(['token_name' => 'expired'], date('Y-m-d H:i:s', time() - 100 * 86400));

        $deleted = $this->repo->purge(90);
        $this->assertSame(1, $deleted);

        $names = $this->pdo
            ->query('SELECT token_name FROM ' . AppConfig::TABLE_API_ACCESS_LOGS)
            ->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(['fresh'], $names);
    }

    public function testPurgeClampsRetainDaysToAtLeastOne(): void
    {
        $this->insertRow(['token_name' => 'now']);
        // 0 / 负数被钳为 1：今天的行仍保留，不会清空全表
        $this->assertSame(0, $this->repo->purge(0));
        $this->assertSame(1, (int)$this->pdo->query('SELECT count(*) FROM ' . AppConfig::TABLE_API_ACCESS_LOGS)->fetchColumn());
    }

    // ── 写入级别（shouldRecord）──

    public function testShouldRecordLevelMatrix(): void
    {
        // all：全记
        $this->assertTrue(ApiAccessLogRepository::shouldRecord('success', 'all'));
        $this->assertTrue(ApiAccessLogRepository::shouldRecord('denied', 'all'));
        $this->assertTrue(ApiAccessLogRepository::shouldRecord('failure', 'all'));
        // warning：跳过 success
        $this->assertFalse(ApiAccessLogRepository::shouldRecord('success', 'warning'));
        $this->assertTrue(ApiAccessLogRepository::shouldRecord('denied', 'warning'));
        $this->assertTrue(ApiAccessLogRepository::shouldRecord('failure', 'warning'));
        // error：只记 failure
        $this->assertFalse(ApiAccessLogRepository::shouldRecord('success', 'error'));
        $this->assertFalse(ApiAccessLogRepository::shouldRecord('denied', 'error'));
        $this->assertTrue(ApiAccessLogRepository::shouldRecord('failure', 'error'));
        // off：全不记
        $this->assertFalse(ApiAccessLogRepository::shouldRecord('success', 'off'));
        $this->assertFalse(ApiAccessLogRepository::shouldRecord('denied', 'off'));
        $this->assertFalse(ApiAccessLogRepository::shouldRecord('failure', 'off'));
        // 未知 result 按 failure 兜底（宁可多记）；未知 level 按 off 兜底（fail-closed）
        $this->assertTrue(ApiAccessLogRepository::shouldRecord('weird', 'all'));
        $this->assertFalse(ApiAccessLogRepository::shouldRecord('success', 'bogus'));
    }

    // ── 级别存取（AppSettingRepository）──

    public function testApiAccessLogLevelRoundTrip(): void
    {
        $this->pdo->exec('CREATE TABLE ' . AppConfig::TABLE_APP_SETTINGS . ' (
            setting_key TEXT PRIMARY KEY,
            value TEXT,
            updated_at TEXT
        )');
        $settings = new \App\Service\AppSettingRepository($this->pdo);

        // 未设置 → 默认 all
        $this->assertSame('all', $settings->getApiAccessLogLevel());
        // 存取往返 + 大小写归一
        $settings->setApiAccessLogLevel('WARNING');
        $this->assertSame('warning', $settings->getApiAccessLogLevel());
        // 非法存量值回退默认
        $this->pdo->exec('UPDATE ' . AppConfig::TABLE_APP_SETTINGS . " SET value='bogus' WHERE setting_key='" . AppConfig::SETTING_API_ACCESS_LOG_LEVEL . "'");
        $this->assertSame('all', $settings->getApiAccessLogLevel());
        // 非法写入抛异常
        $this->expectException(\InvalidArgumentException::class);
        $settings->setApiAccessLogLevel('verbose');
    }

    // ── 保留天数与清理开关（AppSettingRepository，操作日志同用）──

    public function testApiAccessLogRetainDaysAndCleanupRoundTrip(): void
    {
        $this->pdo->exec('CREATE TABLE ' . AppConfig::TABLE_APP_SETTINGS . ' (
            setting_key TEXT PRIMARY KEY,
            value TEXT,
            updated_at TEXT
        )');
        $settings = new \App\Service\AppSettingRepository($this->pdo);

        // 未设置：默认 90 天、开关开启、has=false
        $this->assertSame(90, $settings->getApiAccessLogRetainDays());
        $this->assertFalse($settings->hasApiAccessLogRetainDays());
        $this->assertTrue($settings->getApiAccessLogCleanupEnabled());

        // 存取往返
        $settings->setApiAccessLogRetainDays(30);
        $this->assertSame(30, $settings->getApiAccessLogRetainDays());
        $this->assertTrue($settings->hasApiAccessLogRetainDays());

        // 非法存量值回退默认
        $this->pdo->exec('UPDATE ' . AppConfig::TABLE_APP_SETTINGS . " SET value='0' WHERE setting_key='" . AppConfig::SETTING_API_ACCESS_LOG_RETAIN_DAYS . "'");
        $this->assertSame(90, $settings->getApiAccessLogRetainDays());

        // 开关往返
        $settings->setApiAccessLogCleanupEnabled(false);
        $this->assertFalse($settings->getApiAccessLogCleanupEnabled());
        $settings->setApiAccessLogCleanupEnabled(true);
        $this->assertTrue($settings->getApiAccessLogCleanupEnabled());

        // 非法写入抛异常
        $this->expectException(\InvalidArgumentException::class);
        $settings->setApiAccessLogRetainDays(0);
    }

    // ── 操作日志 purge（与调用日志统一策略，CLI 调度）──

    public function testOperationLogPurge(): void
    {
        $this->pdo->exec('CREATE TABLE ' . AppConfig::TABLE_OPERATION_LOGS . ' (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT, action TEXT, target TEXT, detail TEXT,
            ip TEXT, operator_type TEXT, result TEXT, created_at TEXT
        )');
        $repo = new \App\Service\OperationLogRepository($this->pdo);
        $old = date('Y-m-d H:i:s', time() - 100 * 86400);
        $now = date('Y-m-d H:i:s');
        $ins = $this->pdo->prepare('INSERT INTO ' . AppConfig::TABLE_OPERATION_LOGS . ' (username, action, created_at) VALUES (?, ?, ?)');
        $ins->execute(['root', 'login', $old]);
        $ins->execute(['root', 'login', $now]);

        $this->assertSame(1, $repo->purge(90));
        $this->assertSame(1, (int)$this->pdo->query('SELECT count(*) FROM ' . AppConfig::TABLE_OPERATION_LOGS)->fetchColumn());
        // 表缺失时静默返回 0
        $this->pdo->exec('DROP TABLE ' . AppConfig::TABLE_OPERATION_LOGS);
        $this->assertSame(0, $repo->purge(90));
    }
}
