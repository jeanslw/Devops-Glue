<?php

/**
 * 异步 Git commit status 回写队列消费 CLI
 *
 * 用法：
 *   php cli/writeback-worker.php          # 消费一批（默认最多 5 条）
 *   php cli/writeback-worker.php --all    # 循环消费到空（单次运行内）
 *
 * 由 docker-compose worker 容器的 supervisord 定时拉起（见 supervisord-worker.conf）。
 * CLI 内用 ci_cache 租约锁保证多实例同时触发时只有一个真正执行。
 *
 * 退出码：0 = 完成（含无任务/已有实例）；1 = 致命错误。
 */

require __DIR__ . '/bootstrap.php';

use App\Service\Database;
use App\Service\Git\ProviderRegistry as GitProviderRegistry;
use App\Service\SecurityCheckRepository;
use App\Service\WritebackQueueRepository;
use DI\ContainerBuilder;

$failed = false;
$lockName = 'writeback-worker';
$lockToken = null;
try {
    // DB 必须先建立 PDO：tryAcquireLock 走 Database::lock() → buildComponents()，
    // 若 self::$pdo 为 null 会抛 "Database not bootstrapped"。
    // 注意：Database::init() 只设置 config，不会创建 PDO；getPdo() 才会 connect+bootstrap。
    Database::getPdo();

    // 锁 TTL 300s，每批最多 5 条 × 单条 Git HTTP 15s = 75s，远小于 TTL，
    // 避免长批次跑到锁过期后第二个 worker 并发进入（见审查 H3）。
    $lockToken = Database::tryAcquireLock($lockName, 300);
    if ($lockToken === null) {
        echo "跳过：已有另一实例正在处理回写队列\n";
        exit(0);
    }

    $builder = new ContainerBuilder();
    $builder->addDefinitions(__DIR__ . '/../config/container.php');
    $container = $builder->build();

    /** @var WritebackQueueRepository $queue */
    $queue = $container->get(WritebackQueueRepository::class);
    /** @var GitProviderRegistry $gitRegistry */
    $gitRegistry = $container->get(GitProviderRegistry::class);
    /** @var SecurityCheckRepository $securityChecks */
    $securityChecks = $container->get(SecurityCheckRepository::class);

    // 恢复上次 worker 崩溃遗留的 processing 任务
    $requeued = $queue->requeueStale(600);
    if ($requeued > 0) {
        echo "重置僵死任务: {$requeued}\n";
    }

    $processed = 0;
    $loop = in_array('--all', $argv ?? [], true);
    do {
        $jobs = $queue->claimDue(5);
        if ($jobs === []) {
            break;
        }
        foreach ($jobs as $job) {
            $id = (string)$job['id'];
            // 原子领取：只有仍为 pending/failed 且 next_retry_at 到期的任务才能转 processing。
            // 防止锁失效时两个 worker 取到同一批（审查 H2）。
            if (!$queue->markProcessingIfPending($id)) {
                continue;
            }
            $writebackStatus = 'failed';
            $message = '';
            try {
                $provider = $gitRegistry->create((string)$job['git_platform']);
                $result = $provider->setCommitStatus(
                    (string)$job['repo'],
                    (string)$job['sha'],
                    (string)$job['state'],
                    (string)$job['context'],
                    (string)$job['description'],
                    (string)$job['target_url']
                );
                $writebackStatus = !empty($result['success']) ? 'success' : 'failed';
                $message = (string)$result['message'];
            } catch (\Throwable $e) {
                $message = $e->getMessage();
            }

            // 审计写失败必须让队列保留重试：Git 状态已成功但本地审计失败时，
            // 不能 markDone，否则审计永久丢失。
            $auditError = '';
            try {
                $securityChecks->recordStrict([
                    'project' => (string)($job['project_path'] ?? ''),
                    'sha' => (string)$job['sha'],
                    'state' => (string)$job['state'],
                    'context' => (string)$job['context'],
                    'description' => (string)$job['description'],
                    'check_type' => (string)(($job['check_type'] ?? '') ?: ($job['context'] ?? '')),
                    'tag' => (string)($job['tag'] ?? ''),
                    'writeback_status' => $writebackStatus,
                    'writeback_message' => $message,
                    'pipeline_id' => (string)($job['pipeline_id'] ?? ''),
                ]);
            } catch (\Throwable $e) {
                $auditError = $e->getMessage();
                \App\Helper\Log::exception($e);
                $writebackStatus = 'failed';
                $message = ($message !== '' ? $message . '; ' : '') . 'audit write failed: ' . $auditError;
            }

            if ($writebackStatus === 'success') {
                $queue->markDone($id);
            } else {
                $queue->markFailed($id, $message !== '' ? $message : 'write-back failed');
            }
            $processed++;
            echo "任务 #{$id} {$job['git_platform']} {$job['state']} → {$writebackStatus}\n";
        }
    } while ($loop);

    echo "处理完成: {$processed} 条\n";
} catch (\Throwable $e) {
    fwrite(STDERR, '回写 worker 致命错误: ' . $e->getMessage() . "\n");
    \App\Helper\Log::exception($e);
    $failed = true;
} finally {
    if ($lockToken !== null) {
        Database::releaseLock($lockName, $lockToken);
    }
}

exit($failed ? 1 : 0);
