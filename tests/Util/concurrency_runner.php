<?php
declare(strict_types=1);

/**
 * 并发回归测试子进程 worker（由 ConcurrencyRegressionTest 通过 proc_open 派生）。
 *
 * 流程：读取任务 JSON（argv[1]）→ 打开共享 SQLite 文件库 → 在 barrier 处等待父进程发令
 * → 执行指定场景的并发操作 → 把结果追加写入 resultFile（带 flock，避免多进程写交织）。
 *
 * 该脚本刻意保持“薄”：业务逻辑全部调用被测服务的真实公共方法，不内联任何实现，
 * 以便测试真正钉死修复后的并发语义。
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Config\AppConfig;
use App\Service\OAuthService;
use App\Service\AdminAuthService;
use App\Service\AdminUserRepository;
use App\Service\Settings;
use App\Service\Build\CustomPushBuildProvider;
use App\Service\I18nService;
use App\Service\OidcService;

function openSharedPdo(string $dbPath): \PDO
{
    $pdo = new \PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA busy_timeout=15000');
    return $pdo;
}

/** 子进程就绪后阻塞，直到父进程创建 go 信号，使所有 worker 近似同时开火。 */
function barrierWait(string $readyFile, string $goFile): void
{
    file_put_contents($readyFile, '1');
    $deadline = microtime(true) + 30;
    while (!file_exists($goFile)) {
        if (microtime(true) > $deadline) {
            break;
        }
        usleep(2000);
    }
}

function writeResult(string $resultFile, array $row): void
{
    $fh = fopen($resultFile, 'a');
    if ($fh === false) {
        return;
    }
    flock($fh, LOCK_EX);
    fwrite($fh, json_encode($row, JSON_UNESCAPED_UNICODE) . "\n");
    flock($fh, LOCK_UN);
    fclose($fh);
}

function run(array $task): array
{
    $scenario = $task['scenario'];
    $params   = $task['params'];

    switch ($scenario) {
        case 'oauth_redeem':
            // F-03：多连接并发消费同一 authorization code，应至多一个成功。
            // SQLite 下 consumeCode 用 BEGIN IMMEDIATE 立即取写锁，杜绝“先 SELECT 后 DELETE”对称死锁。
            $pdo = openSharedPdo($task['db']);
            $svc = new OAuthService($pdo, $params['clients']);
            $data = $svc->consumeCode($params['code'], $params['clientId'], $params['redirectUri']);
            return ['worker' => $task['worker'], 'redeemed' => ($data !== null)];

        case 'custom_push_state':
            // F-04：对 (job_name, pipeline_iid) 并发回报 success/failed，终态 success 不可被降级。
            $pdo = openSharedPdo($task['db']);
            $svc = new CustomPushBuildProvider([], $pdo, new I18nService(dirname(__DIR__, 2) . '/lang'));
            $res = $svc->report($params['jobName'], $params['body']);
            return [
                'worker'  => $task['worker'],
                'action'  => ($res['action'] ?? null),
                'success' => !empty($res['success']),
            ];

        case 'login_fail':
            // F-05：N 次并发错误登录，计数应原子累加到 N。
            $pdo = openSharedPdo($task['db']);
            $svc = new AdminAuthService($pdo, new AdminUserRepository($pdo), new Settings([]));
            $svc->recordLoginFailure($params['ip'], $params['username']);
            return ['worker' => $task['worker'], 'done' => true];

        case 'oidc_key':
            // F-09：多进程首次同时请求签名/JWKS，应全部使用同一把持久化私钥。
            $svc = new OidcService(['key_file' => $params['keyFile']]);
            $pem = $svc->publicKeyPem();
            return ['worker' => $task['worker'], 'kid' => $svc->keyId(), 'pem' => $pem];

        default:
            return ['worker' => $task['worker'], 'error' => 'unknown scenario: ' . $scenario];
    }
}

$arg = $argv[1] ?? null;
if ($arg === null) {
    fwrite(STDERR, "missing task file\n");
    exit(2);
}
$task = json_decode((string) file_get_contents($arg), true);
if (!is_array($task)) {
    fwrite(STDERR, "bad task json\n");
    exit(2);
}

barrierWait($task['readyFile'], $task['goFile']);

try {
    $result = run($task);
} catch (\Throwable $e) {
    $result = ['worker' => $task['worker'], 'error' => $e->getMessage()];
}

writeResult($task['resultFile'], $result);
exit(0);
