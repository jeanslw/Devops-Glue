<?php

namespace App\Service\Build;

use App\Service\GitService;
use App\Service\Logger;
use App\Service\I18nService;
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
     * 允许服务端代理拉取的日志主机白名单（可选；空数组 = 不启用白名单，走 IP 段校验）
     * @var list<string>
     */
    private array $allowedLogHosts = [];

    /**
     * 供 report 识别的控制字段（不属于构建参数，不写入 variables_json）
     */
    private const REPORT_FIELDS = [
        'pipeline_iid', 'status', 'finished_at', 'started_at',
        'ref', 'sha', 'exit_code', 'log_url', 'web_url',
        'tag', 'registry_repository',
    ];

    /**
     * @param array<string, mixed> $config 来自 settings.php 的 build.custom_providers[].config
     * @param \PDO            $pdo    数据库连接（用于 ci_custom_builds 表查询）
     * @param GitService|null $git    Git 服务（用于 getBranches 委托）
     * @param Logger|null     $logger
     */
    public function __construct(
        private array $config,
        private \PDO $pdo,
        private I18nService $i18n,
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

    /** @return list<array<string, mixed>> */
    public function getPipelines(string $projectId, int $perPage = 20): array
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT id, pipeline_iid, ref, sha, status, log_url, web_url,
                        triggered_at, started_at, finished_at
                 FROM ' . AppConfig::TABLE_CUSTOM_BUILDS . '
                 WHERE job_name = ? ORDER BY pipeline_iid DESC LIMIT ?'
            );

            $stmt->bindValue(1, $projectId, \PDO::PARAM_STR);
            $stmt->bindValue(2, $perPage, \PDO::PARAM_INT);
            $stmt->execute();
            return array_values(array_map(function (array $r): array {
                return [
                    'id'         => (int) $r['id'],
                    'iid'        => (int) $r['pipeline_iid'],
                    'status'     => BuildStatus::normalize((string) ($r['status'] ?? '')),
                    'ref'        => $r['ref'] ?? '',
                    'sha'        => $r['sha'] ?? '',
                    'web_url'    => $r['web_url'] ?? '',
                    'log_url'    => $r['log_url'] ?? '',
                    'created_at' => $r['triggered_at'] ?? '',

                    'updated_at' => $r['finished_at'] ?? '',
                ];
            }, $stmt->fetchAll()));
        } catch (\Exception $e) {
            $this->logger?->error('custom_push pipelines 查询失败', ['project' => $projectId, 'error' => $e->getMessage()]);
            BuildProviderError::throwFor($e, '自定义推送');
        }
    }

    // ── Job 列表（单 job 模型） ──────────────────────────────────

    /** @return list<array<string, mixed>> */
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
                'status'     => BuildStatus::normalize((string) ($r['status'] ?? '')),
                'runner'     => $this->name,
                'created_at' => $r['started_at'] ?? '',
                'duration'   => 0,
            ]];
        } catch (\Exception $e) {
            $this->logger?->error('custom_push jobs 查询失败', ['project' => $projectId, 'pipeline' => $pipelineId, 'error' => $e->getMessage()]);
            BuildProviderError::throwFor($e, '自定义推送');
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
                return $this->i18n->trans('build.no_build_log');
            }




            // 安全性由下方 resolveAndValidate 一次性解析 + 校验保证，并直接用于 curl pinning，避免二次解析的 rebinding 窗口



            // 解析 + 校验一次性完成；同一份已验证 IP 直接用于 curl pinning，杜绝「校验解析」与
            // 「实际 pin 的解析」不是同一份 IP 导致的 DNS rebinding（二次解析窗口）。
            $parts  = parse_url((string)$logUrl);
            $scheme = strtolower((string)($parts['scheme'] ?? 'http'));
            $host   = strtolower((string)($parts['host'] ?? ''));
            $port   = isset($parts['port']) ? (int)$parts['port'] : ($scheme === 'https' ? 443 : 80);
            if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
                return $this->i18n->trans('build.log_url_invalid', ['url' => (string)$logUrl]);
            }
            if ($this->allowedLogHosts !== [] && !in_array($host, $this->allowedLogHosts, true)) {
                $this->logger?->error('custom_push 日志 URL 校验失败（疑似 SSRF）', ['project' => $projectId, 'job' => $jobId]);
                return $this->i18n->trans('build.log_url_invalid', ['url' => (string)$logUrl]);
            }
            $safeIps = $this->resolveAndValidate($host);
            if ($safeIps === null || $safeIps === []) {
                $this->logger?->error('custom_push 日志 URL 校验失败（疑似 SSRF）', ['project' => $projectId, 'job' => $jobId]);
                return $this->i18n->trans('build.log_url_invalid', ['url' => (string)$logUrl]);
            }
            // IPv6 必须加方括号，否则 CURLOPT_RESOLVE 格式非法（libcurl 要求 [ipv6]）
            $resolve = [];
            foreach ($safeIps as $ip) {
                $resolve[] = $host . ':' . $port . ':' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip);
            }
            $client = new \GuzzleHttp\Client([
                'timeout'         => 15,
                'connect_timeout' => 5,
                'http_errors'     => false,
                'allow_redirects' => false,
                'curl'            => ['resolve' => $resolve],
                'stream'          => true, // 流式读取并按上限截断，避免超大响应占满内存
            ]);
            $resp = $client->get((string)$logUrl);
            $status = $resp->getStatusCode();
            if ($status >= 200 && $status < 300) {
                $stream = $resp->getBody();
                $max    = 1_000_000;
                $body   = '';
                $read   = 0;
                while ($read <= $max) {
                    $chunk = $stream->read(8192);
                    if ($chunk === '') {
                        break;
                    }
                    $body .= $chunk;
                    $read += strlen($chunk);
                }
                if ($read > $max) {
                    // 超过上限：截断并标记（流式读取，不会把超过上限的部分读入内存）
                    $body = substr($body, 0, $max) . "\n" . $this->i18n->trans('build.log_truncated');
                }
                return $body;
            }
            return $this->i18n->trans('build.log_fetch_failed', ['code' => (string)$status, 'url' => (string)$logUrl]);
        } catch (\Exception $e) {
            $this->logger?->error('custom_push 日志拉取失败', ['project' => $projectId, 'job' => $jobId, 'error' => $e->getMessage()]);
            BuildProviderError::throwFor($e, '自定义推送');
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
    // @phpstan-ignore-next-line method.unused
    private function isSafeLogUrl(string $url): bool
    {
        $parts  = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host   = strtolower((string) ($parts['host'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return false;
        }



        if ($this->allowedLogHosts !== [] && !in_array($host, $this->allowedLogHosts, true)) {
            return false;
        }


        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $this->isSafeIp($host);
        }



        // 复用 resolveAndValidate：解析 + 校验一次完成，保证与 getJobTrace 用同一份已验证 IP，
        // 避免二次解析的 rebinding 窗口。
        return $this->resolveAndValidate($host) !== null;
    }

    /**
     * 解析域名并逐个校验 IP；全部安全返回已验证 IP 列表，任一不安全或解析失败返回 null。
     * getJobTrace 解析 + 校验一次性完成，杜绝二次解析的 DNS rebinding 窗口。
     *
     * @return list<string>|null
     */
    private function resolveAndValidate(string $host): ?array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $this->isSafeIp($host) ? [$host] : null;
        }
        $ips = $this->resolveHostIps($host);
        if ($ips === []) {
            return null;
        }
        foreach ($ips as $ip) {
            if (!$this->isSafeIp($ip)) {
                return null;
            }
        }
        return $ips;
    }

    /**
     * 解析域名到 IPv4 + IPv6 地址列表。
     * A 记录用 gethostbynamel（走系统解析，兼顾 /etc/hosts）；AAAA 用 dns_get_record 单独取。
     * @return list<string>
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



        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        $long = ip2long($ip);
        return $long !== false && ($long & 0xF0000000) !== 0xE0000000;
    }

    private function isSafeIpv6(string $ip): bool
    {
        $bin = @inet_pton($ip);
        if ($bin === false || strlen($bin) !== 16) {
            return false; // 无法解析为合法 IPv6 的地址一律拒绝（避免解析失败 fall-through 放行）
        }
        // 未指定 ::
        if ($bin === str_repeat("\x00", 16)) {
            return false;
        }
        // 回环 ::1
        if ($bin === str_repeat("\x00", 15) . "\x01") {
            return false;
        }
        $b0 = ord($bin[0]);
        $b1 = ord($bin[1]);
        // 链路本地 fe80::/10（含云元数据地址段）
        if ($b0 === 0xFE && ($b1 & 0xC0) === 0x80) {
            return false;
        }
        // 唯一本地地址 fd00::/8（私网，SSRF 范畴内按不可信处理）
        if ($b0 === 0xFD) {
            return false;
        }
        // 组播 ff00::/8
        if ($b0 === 0xFF) {
            return false;
        }
        // 嵌入 IPv4 的地址：取出 IPv4 部分复用 isSafeIp 判定
        $embedded = null;
        if (substr($bin, 0, 12) === "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff") {
            $embedded = substr($bin, 12, 4);
        } elseif (substr($bin, 0, 4) === "\x00\x64\xff\x9b") {
            $embedded = substr($bin, 12, 4);
        } elseif (substr($bin, 0, 2) === "\x20\x02") {
            $embedded = substr($bin, 2, 4);
        }
        if ($embedded !== null) {
            $unpacked = unpack('N', $embedded);
            if ($unpacked === false) {
                return false;
            }
            $v4 = long2ip($unpacked[1]);
            return is_string($v4) ? $this->isSafeIp($v4) : false;
        }
        return true; // 其余为全局公网 IPv6 单播
    }

    // ── 上报（用户 CI 一次性回写终态结果） ─────────────────────────

    /**
     * custom_push 不再通过 trigger 创建 pending 记录。
     * 用户 CI 在构建完成后通过 POST /api/build/{path}/report 一次性上报终态结果。
     * @param array<string, mixed> $variables
     * @return array<string, mixed>
     */
    public function trigger(string $projectId, string $ref, array $variables = []): array
    {
        return ['success' => false, 'message' => $this->i18n->trans('build.custom_push_no_trigger')];
    }

    /**
     * 一次性上报构建终态结果（success/failed/aborted），没有 pending/running 中间态。
     *
     * $body 由 BuildReportController::report 透传，包含：
     *   pipeline_iid（必填）、status（必填，终态）、finished_at（必填）、
     *   started_at / ref / sha / exit_code / log_url / web_url（可选），
     *   以及用户自定义构建参数（排除 REPORT_FIELDS 后写入 variables_json）。
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function report(string $jobName, array $body): array
    {
        $pipelineIid = (int) ($body['pipeline_iid'] ?? 0);
        $status      = trim((string) ($body['status'] ?? ''));
        $finishedAt  = trim((string) ($body['finished_at'] ?? ''));

        if ($pipelineIid <= 0) {
            return ['success' => false, 'message' => $this->i18n->trans('build.report_missing_pipeline_iid')];
        }
        if (!in_array($status, ['success', 'failed', 'aborted'], true)) {
            return ['success' => false, 'message' => $this->i18n->trans('build.custom_push_invalid_status')];
        }
        if ($finishedAt === '') {
            return ['success' => false, 'message' => $this->i18n->trans('build.custom_push_missing_finished_at')];
        }

        $existing = $this->findByIid($jobName, $pipelineIid);

        // 状态机约束：success 不可逆，其余状态允许互转；
        if ($existing && !BuildStatusMachine::canTransition((string) ($existing['status'] ?? ''), $status)) {
            return ['success' => false, 'message' => $this->i18n->trans('build.custom_push_terminal_conflict', ['current' => (string) ($existing['status'] ?? ''), 'incoming' => $status])];
        }
        if ($existing && !empty($existing['finished_at']) && BuildStatusMachine::isStale($finishedAt, (string) $existing['finished_at'])) {
            return [
                'success'      => true,
                'pipeline_id'  => (int) ($existing['id'] ?? 0),
                'pipeline_iid' => $pipelineIid,
                'action'       => 'ignored_stale',
            ];
        }



        $ref       = array_key_exists('ref', $body)       ? (string) $body['ref']        : (string) ($existing['ref'] ?? '');
        $sha       = array_key_exists('sha', $body)       ? (string) $body['sha']        : (string) ($existing['sha'] ?? '');
        $logUrl    = array_key_exists('log_url', $body)   ? (string) $body['log_url']    : (string) ($existing['log_url'] ?? '');
        $webUrl    = array_key_exists('web_url', $body)   ? (string) $body['web_url']    : (string) ($existing['web_url'] ?? '');
        $exitCode  = array_key_exists('exit_code', $body) ? $body['exit_code']           : ($existing['exit_code'] ?? null);
        $startedAt = !empty($body['started_at'])          ? (string) $body['started_at'] : ($existing['started_at'] ?? null);

        $buildVars = array_diff_key($body, array_flip(self::REPORT_FIELDS));
        $varsJson  = !empty($buildVars) ? json_encode($buildVars, JSON_UNESCAPED_UNICODE) : ($existing['variables_json'] ?? null);

        try {
            if ($existing) {
                // 并发防护：终态 success 不可逆。UPDATE 增加 status != 'success' 条件，
                // 若并发已被置为 success，受影响行数为 0，视为幂等忽略，避免重复写制品 / 通知。
                $stmt = $this->pdo->prepare(
                    'UPDATE ' . AppConfig::TABLE_CUSTOM_BUILDS . '
                     SET ref = ?, sha = ?, variables_json = ?, status = ?, exit_code = ?,
                         log_url = ?, web_url = ?, started_at = ?, finished_at = ?
                     WHERE job_name = ? AND pipeline_iid = ? AND status != ?
                       AND (finished_at IS NULL OR finished_at <= ?)'
                );
                $stmt->execute([$ref, $sha, $varsJson, $status, $exitCode, $logUrl, $webUrl, $startedAt, $finishedAt, $jobName, $pipelineIid, 'success', $finishedAt]);
                if ($stmt->rowCount() === 0) {
                    $record = $this->findByIid($jobName, $pipelineIid);
                    return [
                        'success'      => true,
                        'pipeline_id'  => (int) ($record['id'] ?? 0),
                        'pipeline_iid' => $pipelineIid,
                        'action'       => 'ignored_terminal',
                    ];
                }
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
            BuildProviderError::throwFor($e, '自定义推送');
        }
    }

    /**
     * 查询指定 pipeline_iid 的记录
     * @return array<string, mixed>|null
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

    /** @return array<string, mixed> */
    public function retry(string $projectId, int $pipelineId): array
    {
        return ['success' => false, 'message' => $this->i18n->trans('build.custom_push_no_retry')];
    }

    /** @return array<string, mixed> */
    public function cancel(string $projectId, int $pipelineId): array
    {
        return ['success' => false, 'message' => $this->i18n->trans('build.custom_push_no_cancel')];
    }

    // ── 构建参数 ──────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
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

    /** @return array<int, string> */
    public function getBranches(string $projectId): array
    {
        if (!$this->git) {
            return [];
        }
        try {
            return $this->git->getBranchesForJob($projectId);
        } catch (\Exception $e) {
            $this->logger?->warning('custom_push 分支查询失败', ['project' => $projectId, 'error' => $e->getMessage()]);
            BuildProviderError::throwFor($e, '自定义推送');
        }
    }

    // ── commit status ────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    public function getRunners(string $projectId): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    public function setCommitStatus(string $projectId, string $sha, string $state, string $name, string $description, string $targetUrl = ''): array
    {
        return ['success' => false, 'message' => $this->i18n->trans('build.custom_push_no_commit_status')];
    }
}
