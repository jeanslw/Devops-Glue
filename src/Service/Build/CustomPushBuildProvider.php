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

    /** 允许服务端代理拉取的日志主机白名单（可选；空数组 = 不启用白名单，走 IP 段校验） */
    private array $allowedLogHosts = [];

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
        $this->allowedLogHosts = array_values(array_map('strtolower', array_filter((array) ($config['log_url_allowed_hosts'] ?? []), 'is_string')));
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
                    'log_url'    => $r['log_url'] ?? '',
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

            // SSRF 防护：log_url 由用户 CI 上报、不可信，服务端拉取前先校验
            // 协议仅 http/https，且目标主机不得是回环 / 链路本地（含云元数据）/ 保留地址。
            // 私网段（10/8、172.16/12、192.168/16 等）有意放行：本系统为内网部署，CI 日志常落在内网。
            if (!$this->isSafeLogUrl((string) $logUrl)) {
                $this->logger?->error('custom_push 日志 URL 校验失败（疑似 SSRF）', ['project' => $projectId, 'job' => $jobId]);
                return '日志 URL 不合法（仅允许公网或内网 http/https 地址，禁止回环/链路本地地址），请直接访问：' . $logUrl;
            }

            // 代理拉取 log_url 内容（不缓存，每次重新拉取）。
            // 关闭重定向：防止 log_url 先 302 跳转到内网/元数据地址绕过上面的校验。
            $client = new \GuzzleHttp\Client([
                'timeout'         => 15,
                'connect_timeout' => 5,
                'http_errors'     => false,
                'allow_redirects' => false,
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

    /**
     * 校验 log_url 是否可被服务端安全拉取（防 SSRF）。
     *
     * 允许：http/https 协议；配置白名单时 host 须精确命中；目标主机解析后的所有 IP
     * 均为全局单播或私网地址。
     * 禁止：file/gopher 等协议；回环（127.0.0.0/8、::1）；链路本地（169.254.0.0/16
     * 含云元数据、fe80::/10）；未指定/保留段（0.0.0.0/8、240.0.0.0/4）；组播；
     * 无法解析的域名；白名单外的 host（白名单非空时）。
     */
    private function isSafeLogUrl(string $url): bool
    {
        $parts  = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host   = strtolower((string) ($parts['host'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return false;
        }

        // 白名单（可选）：内部 DevOps 场景下 CI 日志来源是已知的，白名单比公网/私网判断
        // 更简单可靠。配置后 host 必须精确命中，否则拒绝。
        if ($this->allowedLogHosts !== [] && !in_array($host, $this->allowedLogHosts, true)) {
            return false;
        }

        // host 本身是 IP 字面量时直接校验，避免依赖 DNS 反查
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $this->isSafeIp($host);
        }

        // 域名：同时取 A + AAAA 逐 IP 校验，防止把内网/回环地址藏在域名下，
        // 也避免只有 IPv6（AAAA）的合法日志服务器被误判为不安全。
        $ips = $this->resolveHostIps($host);
        if ($ips === []) {
            return false;
        }
        foreach ($ips as $ip) {
            if (!$this->isSafeIp($ip)) {
                return false;
            }
        }
        return true;
    }

    /**
     * 解析域名到 IPv4 + IPv6 地址列表。
     * A 记录用 gethostbynamel（走系统解析，兼顾 /etc/hosts）；AAAA 用 dns_get_record 单独取。
     */
    private function resolveHostIps(string $host): array
    {
        $ips = [];
        $v4  = @gethostbynamel($host);
        if (is_array($v4)) {
            $ips = array_merge($ips, $v4);
        }
        $aaaa = @dns_get_record($host, DNS_AAAA);
        if (is_array($aaaa)) {
            foreach ($aaaa as $r) {
                if (!empty($r['ipv6'])) {
                    $ips[] = $r['ipv6'];
                }
            }
        }
        return array_values(array_unique($ips));
    }

    /** 单个 IP 是否安全：排除回环/链路本地/未指定/保留/广播/组播（允许公网与私网单播） */
    private function isSafeIp(string $ip): bool
    {
        if (str_contains($ip, ':')) {
            return $this->isSafeIpv6($ip);
        }
        // IPv4：NO_RES_RANGE 拦截 0.0.0.0/8（未指定）、127.0.0.0/8（回环）、
        // 169.254.0.0/16（链路本地，含云元数据 169.254.169.254）、240.0.0.0/4（保留+广播）。
        // 公网与私网（10/8、172.16/12、192.168/16）均有意放行。
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        // 组播 224.0.0.0/4：NO_RES_RANGE 不覆盖，需单独拦（首位字节 224~239）
        $long = ip2long($ip);
        return $long !== false && ($long & 0xF0000000) !== 0xE0000000;
    }

    private function isSafeIpv6(string $ip): bool
    {
        $n = strtolower($ip);
        if (
            $n === '::' || $n === '::1'
            || str_starts_with($n, 'fe80:')   // 链路本地
            || str_starts_with($n, 'ff0')     // 组播
        ) {
            return false;
        }
        // IPv4-mapped（::ffff:127.0.0.1 等）→ 按内嵌 IPv4 再校验
        if (str_starts_with($n, '::ffff:')) {
            return $this->isSafeIp(substr($n, 7));
        }
        return true; // 公网 IPv6 或 fc00::/7 唯一本地（等价私网）放行
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
     * success 为不可逆终态：已 success 的记录拒绝被 failed/aborted 降级（防止与
     * ci_pipeline_tags 中已写入的 tag 产生矛盾）；failed/aborted → success 正常升级。
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

        // 终态单调性：success 不可逆。迟到/乱序的 failed/aborted 不得覆盖 success，
        // 否则 ci_custom_builds（status=failed）与 ci_pipeline_tags（tag 已写、status=success）自相矛盾。
        if (($existing['status'] ?? '') === 'success' && $status !== 'success') {
            return ['success' => false, 'message' => '该 pipeline 已是 success 终态，拒绝用 ' . $status . ' 覆盖'];
        }

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
