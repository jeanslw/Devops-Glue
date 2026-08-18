<?php
namespace App\Service\Build;

use App\Service\GitService;
use App\Service\Logger;
use App\Config\AppConfig;

/**
 * 自定义推送式 CI（User Push 模式）Build Provider。
 *
 * Devops-Glue 不参与构建执行；用户 CI 在构建完成后一次性上报终态结果：
 *   POST /api/build/{path}/report — 上报构建结果（success/failed/aborted）+ 镜像 tag
 *
 * 原则：
 *   - 只存构建元数据（status/sha/ref/log_url），不存日志内容
 *   - 日志通过 log_url 代理拉取（每次请求重新拉取，不缓存）
 *   - pipeline_iid 由用户传入，(job_name, pipeline_iid) 唯一约束，重复上报按覆盖处理
 *   - 无 pending/running 中间态：report 直接写入终态
 */
class CustomPushBuildProvider implements BuildProviderInterface
{
    private string $name;

    /**
     * 供 report 识别的控制字段（不属于构建参数，不写入 variables_json）
     */
    private const REPORT_FIELDS = [
        'pipeline_iid', 'status', 'finished_at', 'started_at',
        'ref', 'sha', 'exit_code', 'log_url', 'web_url',
        'tag', 'harbor_repository',
    ];

    /**
     * @param array           $config 来自 settings.php 的 build.custom_providers[].config
     * @param \PDO            $pdo    数据库连接（用于 ci_custom_builds 表查询）
     * @param GitService|null $git    Git 服务（用于 getBranches 委托）
     * @param Logger|null     $logger
     */
    public function __construct(
        private array $config,
        private \PDO $pdo,
        private ?GitService $git = null,
        private ?Logger $logger = null
    ) {
        $this->name = $config['name'] ?? AppConfig::PROVIDER_CUSTOM_PUSH;
    }

    public function getName(): string
    {
        return $this->name;
    }

    // ── Pipeline 列表 ────────────────────────────────────────────

    public function getPipelines(string $projectId, int $perPage = 20): array
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT id, pipeline_iid, ref, sha, status, log_url, web_url,
                        triggered_at, started_at, finished_at
                 FROM ' . AppConfig::TABLE_CUSTOM_BUILDS . '
                 WHERE job_name = ? ORDER BY pipeline_iid DESC LIMIT ?'
            );
            // LIMIT 必须按整数绑定：execute([...]) 默认全按字符串，MySQL 对 `LIMIT '20'` 报语法错误
            $stmt->bindValue(1, $projectId, \PDO::PARAM_STR);
            $stmt->bindValue(2, $perPage, \PDO::PARAM_INT);
            $stmt->execute();
            return array_map(function (array $r): array {
                return [
                    'id'         => (int) $r['id'],
                    'iid'        => (int) $r['pipeline_iid'],
                    'status'     => $r['status'] ?? 'unknown',
                    'ref'        => $r['ref'] ?? '',
                    'sha'        => $r['sha'] ?? '',
                    'web_url'    => $r['web_url'] ?? '',
                    'created_at' => $r['triggered_at'] ?? '',
                    // 完成时间：构建列表按此展示；未完成（无 finished_at）时留空，不回落 triggered_at
                    'updated_at' => $r['finished_at'] ?? '',
                ];
            }, $stmt->fetchAll());
        } catch (\Exception $e) {
            $this->logger?->error('custom_push pipelines 查询失败', ['project' => $projectId, 'error' => $e->getMessage()]);
            return [];
        }
    }

    // ── Job 列表（单 job 模型） ──────────────────────────────────

    public function getJobs(string $projectId, int $pipelineId): array
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT status, started_at, finished_at
                 FROM ' . AppConfig::TABLE_CUSTOM_BUILDS . '
                 WHERE id = ? AND job_name = ?'
            );
            $stmt->execute([$pipelineId, $projectId]);
            $r = $stmt->fetch() ?: [];
            return [[
                'id'         => $pipelineId,
                'name'       => 'build',
                'stage'      => 'build',
                'status'     => strtolower($r['status'] ?? 'unknown'),
                'runner'     => $this->name,
                'created_at' => $r['started_at'] ?? '',
                'duration'   => 0,
            ]];
        } catch (\Exception $e) {
            $this->logger?->error('custom_push jobs 查询失败', ['project' => $projectId, 'pipeline' => $pipelineId, 'error' => $e->getMessage()]);
            return [];
        }
    }

    // ── 日志（代理拉取，不存内容） ────────────────────────────────

    public function getJobTrace(string $projectId, int $jobId): string
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT log_url FROM ' . AppConfig::TABLE_CUSTOM_BUILDS . '
                 WHERE id = ? AND job_name = ?'
            );
            $stmt->execute([$jobId, $projectId]);
            $logUrl = $stmt->fetchColumn();

            if (empty($logUrl)) {
                return '无构建日志';
            }

            // 代理拉取 log_url 内容（不缓存，每次重新拉取）
            $client = new \GuzzleHttp\Client([
                'timeout'         => 15,
                'connect_timeout' => 5,
                'http_errors'     => false,
            ]);
            $resp = $client->get($logUrl);
            $status = $resp->getStatusCode();
            if ($status >= 200 && $status < 300) {
                return (string) $resp->getBody();
            }
            return '日志拉取失败 (HTTP ' . $status . ')，请直接访问：' . $logUrl;
        } catch (\Exception $e) {
            $this->logger?->error('custom_push 日志拉取失败', ['project' => $projectId, 'job' => $jobId, 'error' => $e->getMessage()]);
            return '日志拉取失败: ' . $e->getMessage() . '。请直接访问用户 CI 日志页面';
        }
    }

    // ── 上报（用户 CI 一次性回写终态结果） ─────────────────────────

    /**
     * custom_push 不再通过 trigger 创建 pending 记录。
     * 用户 CI 在构建完成后通过 POST /api/build/{path}/report 一次性上报终态结果。
     */
    public function trigger(string $projectId, string $ref, array $variables = []): array
    {
        return ['success' => false, 'message' => 'custom_push 不支持主动触发，请通过 POST /api/build/{path}/report 上报构建结果'];
    }

    /**
     * 一次性上报构建终态结果（success/failed/aborted），无 pending/running 中间态。
     *
     * $body 由 BuildController::report 透传，包含：
     *   pipeline_iid（必填）、status（必填，终态）、finished_at（必填）、
     *   started_at / ref / sha / exit_code / log_url / web_url（可选），
     *   以及用户自定义构建参数（排除 REPORT_FIELDS 后写入 variables_json）。
     *
     * (job_name, pipeline_iid) 冲突时按覆盖（UPDATE）处理，保住原自增 id。
     */
    public function report(string $jobName, array $body): array
    {
        $pipelineIid = (int) ($body['pipeline_iid'] ?? 0);
        $status      = trim((string) ($body['status'] ?? ''));
        $finishedAt  = trim((string) ($body['finished_at'] ?? ''));

        if ($pipelineIid <= 0) {
            return ['success' => false, 'message' => '缺少 pipeline_iid 参数'];
        }
        if (!in_array($status, ['success', 'failed', 'aborted'], true)) {
            return ['success' => false, 'message' => 'status 仅允许: success / failed / aborted'];
        }
        if ($finishedAt === '') {
            return ['success' => false, 'message' => '缺少 finished_at 参数'];
        }

        $existing = $this->findByIid($jobName, $pipelineIid);

        // 可选字段：本次未提供则保留已有值。覆盖只作用于本次上报携带的字段，
        // 避免重复上报把首次写入的 log_url / web_url / ref / sha 等清空。
        $ref       = array_key_exists('ref', $body)       ? (string) $body['ref']        : (string) ($existing['ref'] ?? '');
        $sha       = array_key_exists('sha', $body)       ? (string) $body['sha']        : (string) ($existing['sha'] ?? '');
        $logUrl    = array_key_exists('log_url', $body)   ? (string) $body['log_url']    : (string) ($existing['log_url'] ?? '');
        $webUrl    = array_key_exists('web_url', $body)   ? (string) $body['web_url']    : (string) ($existing['web_url'] ?? '');
        $exitCode  = array_key_exists('exit_code', $body) ? $body['exit_code']           : ($existing['exit_code'] ?? null);
        $startedAt = !empty($body['started_at'])          ? (string) $body['started_at'] : ($existing['started_at'] ?? null);
        // 构建参数 = 排除控制字段后的剩余 body 字段；未带自定义变量则保留原有 variables_json
        $buildVars = array_diff_key($body, array_flip(self::REPORT_FIELDS));
        $varsJson  = !empty($buildVars) ? json_encode($buildVars, JSON_UNESCAPED_UNICODE) : ($existing['variables_json'] ?? null);

        try {
            if ($existing) {
                // 覆盖走 UPDATE 保住原自增 id（避免 REPLACE INTO 删行重插导致 id 漂移）
                $stmt = $this->pdo->prepare(
                    'UPDATE ' . AppConfig::TABLE_CUSTOM_BUILDS . '
                     SET ref = ?, sha = ?, variables_json = ?, status = ?, exit_code = ?,
                         log_url = ?, web_url = ?, started_at = ?, finished_at = ?
                     WHERE job_name = ? AND pipeline_iid = ?'
                );
                $stmt->execute([$ref, $sha, $varsJson, $status, $exitCode, $logUrl, $webUrl, $startedAt, $finishedAt, $jobName, $pipelineIid]);

                $record = $this->findByIid($jobName, $pipelineIid);
                return [
                    'success'      => true,
                    'pipeline_id'  => (int) ($record['id'] ?? 0),
                    'pipeline_iid' => $pipelineIid,
                    'action'       => 'updated',
                ];
            }

            $sql = \App\Service\Database::sqlUpsert(
                AppConfig::TABLE_CUSTOM_BUILDS,
                'job_name, pipeline_iid, ref, sha, variables_json, status, exit_code, log_url, web_url, triggered_at, started_at, finished_at',
                '?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?'
            );
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                $jobName, $pipelineIid, $ref, $sha, $varsJson, $status, $exitCode, $logUrl, $webUrl,
                date('Y-m-d H:i:s'), $startedAt, $finishedAt,
            ]);

            return [
                'success'      => true,
                'pipeline_id'  => (int) $this->pdo->lastInsertId(),
                'pipeline_iid' => $pipelineIid,
                'action'       => 'created',
            ];
        } catch (\Exception $e) {
            $this->logger?->error('custom_push report 失败', ['project' => $jobName, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => '写入构建记录失败: ' . $e->getMessage()];
        }
    }

    /**
     * 查询指定 pipeline_iid 的记录
     */
    public function findByIid(string $jobName, int $pipelineIid): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ' . AppConfig::TABLE_CUSTOM_BUILDS . '
             WHERE job_name = ? AND pipeline_iid = ?'
        );
        $stmt->execute([$jobName, $pipelineIid]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    // ── retry / cancel ───────────────────────────────────────────

    public function retry(string $projectId, int $pipelineId): array
    {
        return ['success' => false, 'message' => 'custom_push 不支持 Devops-Glue 主动重试，请在用户 CI 重新触发'];
    }

    public function cancel(string $projectId, int $pipelineId): array
    {
        return ['success' => false, 'message' => 'custom_push 不支持 Devops-Glue 主动取消，请到用户 CI 后台手动中止'];
    }

    // ── 构建参数 ──────────────────────────────────────────────────

    public function getVariables(string $projectId): array
    {
        $vars   = $this->config['variables'] ?? [];
        $result = [];
        foreach ($vars as $key => $def) {
            if (!is_array($def)) {
                $result[] = ['key' => $key, 'type' => 'string', 'defaultValue' => (string) $def];
                continue;
            }
            $result[] = [
                'key'          => $key,
                'type'         => $def['type'] ?? 'string',
                'defaultValue' => $def['default'] ?? '',
                'description'  => $def['description'] ?? '',
                'choices'      => $def['choices'] ?? null,
            ];
        }
        return $result;
    }

    // ── 分支（委托 GitService） ───────────────────────────────────

    public function getBranches(string $projectId): array
    {
        if (!$this->git) {
            return [];
        }
        try {
            return $this->git->getBranchesForJob($projectId);
        } catch (\Exception $e) {
            $this->logger?->warning('custom_push 分支查询失败', ['project' => $projectId, 'error' => $e->getMessage()]);
            return [];
        }
    }

    // ── commit status ────────────────────────────────────────────

    public function setCommitStatus(string $projectId, string $sha, string $state, string $name, string $description, string $targetUrl = ''): array
    {
        return ['success' => false, 'message' => 'custom_push 不支持 commit status 回写'];
    }
}