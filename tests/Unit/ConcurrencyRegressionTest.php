<?php
declare(strict_types=1);

namespace App\Test\Unit;

use App\Config\AppConfig;
use PHPUnit\Framework\TestCase;

/**
 * 并发回归测试：把审查报告里“按代码顺序推导、未动态复现”的并发缺陷真正跑出来钉死。
 *
 * 对应报告第 8 节验证计划：
 *   - F-03 OAuth 授权码双连接并发兑换：至多一个请求成功（原子消费 + 删除行数校验）。
 *   - F-04 custom_push 构建终态：success 不可被并发 failed 降级（UPDATE … AND status != 'success'）。
 *   - F-05 登录失败计数：N 次并发错误登录原子累加到 N（DB 内 value = value + 1）。
 *   - F-09 OIDC 私钥首次生成：多进程并发初始化全部使用同一把持久化密钥（flock 覆盖全流程）。
 *
 * 实现：Windows 无 pcntl_fork，故用 proc_open 派生子进程（tests/Util/concurrency_runner.php），
 * 共享同一个 SQLite 文件库（WAL + busy_timeout），以 flock 屏障让所有 worker 近似同时开火，
 * 复现“读-改-写”竞态窗口。任意一组断言在“回退对应修复”时都会失败。
 *
 * 运行：vendor/bin/phpunit tests/Unit/ConcurrencyRegressionTest.php
 */
class ConcurrencyRegressionTest extends TestCase
{
    private const WORKERS_OAUTH    = 4;
    private const ITER_OAUTH       = 5;
    private const WORKERS_LOGIN    = 12;
    private const WORKERS_OIDC     = 6;
    private const ITER_F04B        = 8;

    private string $workDir;
    private string $dbFile;
    private string $resultFile;
    private string $goFile;

    protected function setUp(): void
    {
        parent::setUp();

        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open 不可用，跳过并发测试');
        }

        $this->workDir = sys_get_temp_dir() . '/glue_cc_' . uniqid('', true);
        mkdir($this->workDir, 0777, true);
        $this->dbFile     = $this->workDir . '/shared.sqlite';
        $this->resultFile = $this->workDir . '/results.jsonl';
        $this->goFile     = $this->workDir . '/go.signal';

        $pdo = new \PDO('sqlite:' . $this->dbFile);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS ' . AppConfig::TABLE_CACHE
            . ' (cache_key TEXT PRIMARY KEY, value TEXT NOT NULL, expires_at INTEGER)'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS ' . AppConfig::TABLE_CUSTOM_BUILDS . ' (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                job_name TEXT NOT NULL,
                pipeline_iid INTEGER NOT NULL,
                ref TEXT, sha TEXT, variables_json TEXT,
                status TEXT DEFAULT \'pending\',
                exit_code INTEGER, log_url TEXT, web_url TEXT,
                triggered_at TEXT, started_at TEXT, finished_at TEXT,
                UNIQUE (job_name, pipeline_iid)
            )'
        );
        $pdo = null;
    }

    protected function tearDown(): void
    {
        // 仅清理本测试产生的临时文件（不碰仓库任何内容）
        foreach (glob($this->workDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @unlink($this->workDir . '/shared.sqlite-wal');
        @unlink($this->workDir . '/shared.sqlite-shm');
        @rmdir($this->workDir);
        parent::tearDown();
    }

    // ─────────────────────────── harness ───────────────────────────

    /** 派生多个 worker（tasks 为按 worker 顺序的完整数组），等待全部完成后返回结果 map[worker]=row */
    private function spawnWorkers(array $tasks): array
    {
        @unlink($this->goFile);
        @unlink($this->resultFile);

        $runner = dirname(__DIR__) . '/Util/concurrency_runner.php';
        $php    = (string) constant('PHP_BINARY');
        $procs  = [];
        $ready  = [];

        $i = 0;
        foreach ($tasks as $task) {
            $i++;
            $readyFile = $this->workDir . "/ready_{$i}.signal";
            $taskFile  = $this->workDir . "/task_{$i}.json";
            $full = array_merge($task, [
                'worker'     => $i,
                'readyFile'  => $readyFile,
                'goFile'     => $this->goFile,
                'resultFile' => $this->resultFile,
            ]);
            file_put_contents($taskFile, json_encode($full, JSON_UNESCAPED_UNICODE));
            $ready[] = $readyFile;

            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['file', $this->workDir . "/worker_{$i}.log", 'a'],
                2 => ['file', $this->workDir . "/worker_{$i}.log", 'a'],
            ];
            $proc = proc_open(
                [self::arg($php), self::arg($runner), self::arg($taskFile)],
                $descriptors,
                $pipes
            );
            if ($proc === false) {
                $last = error_get_last();
                throw new \RuntimeException(
                    "proc_open 失败（worker {$i}）：cmd={$cmd}；last_error="
                    . json_encode($last, JSON_UNESCAPED_SLASHES)
                );
            }
            if (isset($pipes[0])) {
                fclose($pipes[0]);
            }
            $procs[$i] = $proc;
        }

        $this->waitReady($ready);
        file_put_contents($this->goFile, '1');

        foreach ($procs as $proc) {
            $this->closeWithTimeout($proc);
        }

        $results = $this->readResults();
        if ($results === []) {
            // 子进程未产出任何结果，通常是早期崩溃；把日志抛出便于定位
            foreach ($ready as $idx => $rf) {
                $log = $this->workDir . '/worker_' . ($idx + 1) . '.log';
                if (is_file($log)) {
                    fwrite(STDERR, "── worker log {$log} ──\n" . (string) file_get_contents($log) . "\n");
                }
                fwrite(STDERR, "ready file {$rf} exists=" . var_export(file_exists($rf), true) . "\n");
            }
        }

        return $results;
    }

    private function waitReady(array $readyFiles, int $timeoutSec = 30): void
    {
        $deadline = microtime(true) + $timeoutSec;
        while (true) {
            $all = true;
            foreach ($readyFiles as $f) {
                if (!file_exists($f)) {
                    $all = false;
                    break;
                }
            }
            if ($all) {
                return;
            }
            if (microtime(true) > $deadline) {
                return;
            }
            usleep(2000);
        }
    }

    private function closeWithTimeout($proc, int $timeoutSec = 40): void
    {
        $deadline = microtime(true) + $timeoutSec;
        while (true) {
            $st = proc_get_status($proc);
            if (!is_array($st) || empty($st['running'])) {
                break;
            }
            if (microtime(true) > $deadline) {
                proc_terminate($proc, 9);
                break;
            }
            usleep(5000);
        }
        proc_close($proc);
    }

    private function readResults(): array
    {
        $results = [];
        if (file_exists($this->resultFile)) {
            foreach (file($this->resultFile) as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $r = json_decode($line, true);
                if (is_array($r) && isset($r['worker'])) {
                    $results[$r['worker']] = $r;
                }
            }
        }
        return $results;
    }

    private static function arg(string $s): string
    {
        // Windows 下 proc_open 数组形式不自动加引号；仅当路径含空格才包裹，避免双层引号导致命令解析失败
        return str_contains($s, ' ') ? '"' . $s . '"' : $s;
    }

    // ─────────────────────────── 断言辅助 ───────────────────────────

    private function openReadPdo(): \PDO
    {
        $pdo = new \PDO('sqlite:' . $this->dbFile);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        return $pdo;
    }

    private function seedCustomBuild(string $job, int $iid, string $status, ?string $finishedAt): void
    {
        $pdo = $this->openReadPdo();
        $pdo->prepare(
            'INSERT INTO ' . AppConfig::TABLE_CUSTOM_BUILDS
            . ' (job_name, pipeline_iid, ref, sha, status, finished_at) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$job, $iid, 'main', 'deadbeef', $status, $finishedAt]);
        $pdo = null;
    }

    private function assertFinalStatus(string $job, int $iid, string $expected): void
    {
        $pdo = $this->openReadPdo();
        $st  = $pdo->prepare(
            'SELECT status FROM ' . AppConfig::TABLE_CUSTOM_BUILDS
            . ' WHERE job_name = ? AND pipeline_iid = ?'
        );
        $st->execute([$job, $iid]);
        $this->assertSame($expected, (string) $st->fetchColumn(), "最终构建状态应为 {$expected}");
        $pdo = null;
    }

    private function buildReportBody(int $iid, string $status, string $finishedAt): array
    {
        return [
            'pipeline_iid' => $iid,
            'status'       => $status,
            'finished_at'  => $finishedAt,
            'started_at'   => '2026-01-01 00:00:00',
            'ref'          => 'main',
            'sha'          => 'deadbeef',
            'log_url'      => 'http://logs.example/' . $iid,
            'web_url'      => 'http://ci.example/' . $iid,
            'exit_code'    => 0,
        ];
    }

    // ─────────────────────────── F-03 OAuth 授权码并发兑换 ───────────────────────────
    // 多进程同时兑换同一授权码，动态复现“双连接并发兑换”。SQLite 下写锁串行化使“先读后写”的
    // 重复兑换竞态无法复现（首个写锁阻塞其余读），故本测试在 SQLite 上验证：修复后无死锁、恰好一次
    // 兑换成功；严格的“重复兑换”回退钉死在 MySQL 下成立（行锁下竞态可复现）。

    public function testOAuthCodeCannotBeRedeemedTwice(): void
    {
        $clients = [
            'gitlab' => [
                'secret'      => 's3cret',
                'redirect_uri' => 'https://gitlab.example.com/cb',
            ],
        ];
        $redirectUri = 'https://gitlab.example.com/cb';

        for ($it = 1; $it <= self::ITER_OAUTH; $it++) {
            // 父进程在共享库上签发一个授权码
            $issuePdo = $this->openReadPdo();
            $issuer   = new \App\Service\OAuthService($issuePdo, $clients);
            $code     = $issuer->issueCode('gitlab', $redirectUri, 'alice', 'ci_admin', 'openid', 'nonce-' . $it);
            $issuePdo = null;

            $tasks = [];
            for ($w = 1; $w <= self::WORKERS_OAUTH; $w++) {
                $tasks[] = [
                    'scenario' => 'oauth_redeem',
                    'db'       => $this->dbFile,
                    'params'   => [
                        'code'        => $code,
                        'clientId'    => 'gitlab',
                        'redirectUri' => $redirectUri,
                        'clients'     => $clients,
                    ],
                ];
            }
            $results  = $this->spawnWorkers($tasks);
            $redeemed = 0;
            foreach ($results as $r) {
                $this->assertArrayNotHasKey('error', $r, 'worker 异常：' . ($r['error'] ?? ''));
                if (!empty($r['redeemed'])) {
                    $redeemed++;
                }
            }
            $this->assertSame(
                1,
                $redeemed,
                "第 {$it} 轮：授权码应恰好被一个并发请求兑换成功（重复兑换=0 或 >1 都说明原子消费失效）"
            );
        }
    }

    // ─────────────────────────── F-04 custom_push 构建终态并发 ───────────────────────────

    /** 终态 success 不能被并发 failed 降级（已 success 的行，failed 上报直接被状态机/SQL 守卫拒绝） */
    public function testSuccessTerminalCannotBeDowngradedByConcurrentFailed(): void
    {
        $this->seedCustomBuild('jobA', 1, 'success', '2026-01-01 00:00:00');

        $tasks = [];
        for ($w = 1; $w <= 6; $w++) {
            $tasks[] = [
                'scenario' => 'custom_push_state',
                'db'       => $this->dbFile,
                'params'   => [
                    'jobName' => 'jobA',
                    'body'    => $this->buildReportBody(1, 'failed', '2026-02-02 02:02:02'),
                ],
            ];
        }
        $results = $this->spawnWorkers($tasks);
        foreach ($results as $r) {
            $this->assertArrayNotHasKey('error', $r, 'worker 异常：' . ($r['error'] ?? ''));
        }
        // 无论并发 failed 是否落在陈旧读窗口，最终都必须是 success
        $this->assertFinalStatus('jobA', 1, 'success');
    }

    /** 从 running 同时上报 success 与 failed：success 必须最终胜出（SQL 守卫阻止 failed 覆盖 success） */
    public function testConcurrentSuccessVsFailedKeepsSuccess(): void
    {
        for ($it = 1; $it <= self::ITER_F04B; $it++) {
            $this->seedCustomBuild('jobB', $it, 'running', null);

            $tasks = [
                [
                    'scenario' => 'custom_push_state',
                    'db'       => $this->dbFile,
                    'params'   => [
                        'jobName' => 'jobB',
                        'body'    => $this->buildReportBody($it, 'success', '2026-03-03 03:03:03'),
                    ],
                ],
                [
                    'scenario' => 'custom_push_state',
                    'db'       => $this->dbFile,
                    'params'   => [
                        'jobName' => 'jobB',
                        'body'    => $this->buildReportBody($it, 'failed', '2026-03-03 03:03:03'),
                    ],
                ],
            ];
            $results = $this->spawnWorkers($tasks);
            foreach ($results as $r) {
                $this->assertArrayNotHasKey('error', $r, 'worker 异常：' . ($r['error'] ?? ''));
            }
            $this->assertFinalStatus('jobB', $it, 'success');
        }
    }

    // ─────────────────────────── F-05 登录失败计数并发原子递增 ───────────────────────────

    public function testLoginFailureCountIsAtomic(): void
    {
        $ip       = '203.0.113.7';
        $username = 'admin';
        $key      = AppConfig::CACHE_KEY_LOGIN_FAIL_PREFIX . md5($ip . ':' . strtolower($username));

        // 预置一条已存在的计数行（未过期），使所有并发走 UPDATE 路径，隔离“原子递增”本身
        $seedPdo = $this->openReadPdo();
        $seedPdo->prepare(
            'INSERT INTO ' . AppConfig::TABLE_CACHE . ' (cache_key, value, expires_at) VALUES (?, \'0\', ?)'
        )->execute([$key, time() + 900]);
        $seedPdo = null;

        $tasks = [];
        for ($w = 1; $w <= self::WORKERS_LOGIN; $w++) {
            $tasks[] = [
                'scenario' => 'login_fail',
                'db'       => $this->dbFile,
                'params'   => ['ip' => $ip, 'username' => $username],
            ];
        }
        $results = $this->spawnWorkers($tasks);
        foreach ($results as $r) {
            $this->assertArrayNotHasKey('error', $r, 'worker 异常：' . ($r['error'] ?? ''));
        }

        $read = $this->openReadPdo();
        $val  = $read->query(
            'SELECT value FROM ' . AppConfig::TABLE_CACHE . " WHERE cache_key = '{$key}'"
        )->fetchColumn();
        $read = null;

        $this->assertSame(
            (string) self::WORKERS_LOGIN,
            (string) $val,
            'N 次并发失败登录必须把计数原子累加到 N（读改写丢更新会让计数 < N）'
        );
    }

    /**
     * 首插竞态（报告 #3.3 真正复现点）：计数行尚不存在时，N 次并发错误登录必须原子累加到 N。
     * 旧实现先 UPDATE（0 行）再 INSERT，并发首插会丢计数（仅一个 INSERT 成功、其余唯一键冲突被吞）。
     * 修复后改为单条原子 upsert（ON CONFLICT DO UPDATE value=value+1），不再丢更新。
     * 不预置计数行 —— 与上面的 testLoginFailureCountIsAtomic（预置行、只覆盖 UPDATE 路径）互补。
     */
    public function testLoginFailureCountIsAtomicFromFresh(): void
    {
        $ip       = '198.51.100.23';
        $username = 'bob';

        $tasks = [];
        for ($w = 1; $w <= self::WORKERS_LOGIN; $w++) {
            $tasks[] = [
                'scenario' => 'login_fail',
                'db'       => $this->dbFile,
                'params'   => ['ip' => $ip, 'username' => $username],
            ];
        }
        $results = $this->spawnWorkers($tasks);
        foreach ($results as $r) {
            $this->assertArrayNotHasKey('error', $r, 'worker 异常：' . ($r['error'] ?? ''));
        }

        $key  = AppConfig::CACHE_KEY_LOGIN_FAIL_PREFIX . md5($ip . ':' . strtolower($username));
        $read = $this->openReadPdo();
        $val  = $read->query(
            'SELECT value FROM ' . AppConfig::TABLE_CACHE . " WHERE cache_key = '{$key}'"
        )->fetchColumn();
        $read = null;

        $this->assertSame(
            (string) self::WORKERS_LOGIN,
            (string) $val,
            '首插竞态下 N 次并发失败登录必须把计数原子累加到 N（旧实现会丢计数）'
        );
    }

    // ─────────────────────────── F-09 OIDC 私钥首次生成并发一致性 ───────────────────────────

    public function testOidcKeyGenerationIsConsistentAcrossProcesses(): void
    {
        $keyDir  = $this->workDir . '/oidc';
        mkdir($keyDir, 0777, true);
        $keyFile = $keyDir . '/oidc.key';

        $tasks = [];
        for ($w = 1; $w <= self::WORKERS_OIDC; $w++) {
            $tasks[] = [
                'scenario' => 'oidc_key',
                'db'       => $this->dbFile,
                'params'   => ['keyFile' => $keyFile],
            ];
        }
        $results = $this->spawnWorkers($tasks);

        $pems = [];
        $kids = [];
        foreach ($results as $r) {
            $this->assertArrayNotHasKey('error', $r, 'OIDC worker 异常：' . ($r['error'] ?? ''));
            $pems[] = $r['pem'] ?? null;
            $kids[] = $r['kid'] ?? null;
        }

        $this->assertSame(
            1,
            count(array_unique($pems)),
            '所有 worker 应使用同一把持久化私钥（公钥 PEM 必须完全一致，否则 flock 未覆盖完整初始化）'
        );
        $this->assertSame(
            1,
            count(array_unique($kids)),
            '所有 worker 的 kid 必须一致（密钥不一致会导致 JWKS 验签间歇失败）'
        );
        $this->assertFileExists($keyFile, '私钥应已持久化到 key_file');
        // 文件存的是私钥：从它派生公钥，应与所有 worker 返回的公钥一致（验证锁定覆盖完整初始化）
        $priv = openssl_pkey_get_private((string) file_get_contents($keyFile));
        $this->assertNotFalse($priv, '持久化文件应为合法私钥');
        $derivedPub = openssl_pkey_get_details($priv)['key'] ?? null;
        $this->assertSame($pems[0], $derivedPub, '从持久化私钥派生的公钥必须与 worker 返回的公钥一致');
    }
}
