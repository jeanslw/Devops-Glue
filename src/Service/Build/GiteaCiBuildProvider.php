<?php

namespace App\Service\Build;

use GuzzleHttp\Client;
use App\Service\Logger;
use App\Config\AppConfig;

/**
 * Gitea Actions 构建 Provider（拉取式 CI）。
 *
 * 对接 Gitea Actions API（Gitea 1.21+ 逐步开放）。Gitea Actions 的流水线 API
 * 较 GitLab 更晚进入稳定 `/api/v1`，不同版本端点可用性不一，因此每个方法都
 * 独立 try/catch，接口不可用/老版本时优雅降级（返回空或明确文案），绝不抛错中断。
 *
 * projectId 约定为 `owner/repo`（与 job_git_map.job_name/current_path 一致）。
 */
class GiteaCiBuildProvider implements BuildProviderInterface
{
    private Client $http;
    private string $baseUrl;
    private ?Logger $logger;

    public function __construct(string $baseUrl, string $token, ?Logger $logger = null)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->logger  = $logger;
        $this->http    = new Client([
            'headers'     => ['Authorization' => 'token ' . $token],
            'timeout'     => 15,
            'http_errors' => false,
        ]);
    }

    public function getName(): string
    {
        return AppConfig::PROVIDER_GITEA_CI;
    }

    /** 拆分 projectId（owner/repo）为 [owner, repo]，非法则返回 null */
    private function splitRepo(string $projectId): ?array
    {
        $parts = explode('/', $projectId, 2);
        $owner = trim($parts[0]);
        $repo  = trim($parts[1] ?? '');
        if ($owner === '' || $repo === '') {
            return null;
        }
        return [$owner, $repo];
    }

    public function getPipelines(string $projectId, int $perPage = 20): array
    {
        $repo = $this->splitRepo($projectId);
        if (!$repo) {
            return [];
        }
        [$owner, $repoName] = $repo;
        $url = "{$this->baseUrl}/api/v1/repos/{$owner}/{$repoName}/actions/runs?limit={$perPage}&page=1";
        try {
            $resp = $this->http->get($url);
            // 404 / 405：Gitea 未启用 Actions 或该版本无此端点 → 优雅降级
            if ($resp->getStatusCode() >= 400) {
                $this->logger?->debug('Gitea Actions runs 端点不可用', ['project' => $projectId, 'status' => $resp->getStatusCode()]);
                return [];
            }
            $data = json_decode($resp->getBody(), true);
            if (!is_array($data)) {
                return [];
            }
            return array_map(fn($r) => [
                'id'         => $r['id'] ?? 0,
                'iid'        => $r['run_number'] ?? 0,
                'status'     => BuildStatus::normalize((string) ($r['status'] ?? '')),
                'ref'        => $r['head_branch'] ?? '',
                'sha'        => $r['head_sha'] ?? '',
                'web_url'    => "{$this->baseUrl}/{$owner}/{$repoName}/actions/runs/" . ($r['id'] ?? 0),
                'created_at' => $this->fmtTime($r['created_at'] ?? ''),
                'updated_at' => $this->fmtTime($r['updated_at'] ?? ''),
            ], $data);
        } catch (\Exception $e) {
            $this->logger?->error('Gitea Actions runs 查询失败', ['project' => $projectId, 'error' => $e->getMessage()]);
            return [];
        }
    }

    private function fmtTime(string $iso): string
    {
        if (empty($iso)) {
            return '';
        }
        $ts = strtotime($iso);
        return $ts ? date('Y-m-d H:i:s', $ts) : $iso;
    }

    /** job 耗时（秒）：stopped_at - started_at，任一缺失或非法返回 0 */
    private function calcDuration(string $startedAt, string $stoppedAt): int
    {
        $start = strtotime($startedAt);
        $stop  = strtotime($stoppedAt);
        if ($start !== false && $stop !== false && $stop >= $start) {
            return $stop - $start;
        }
        return 0;
    }

    public function getJobs(string $projectId, int $pipelineId): array
    {
        $repo = $this->splitRepo($projectId);
        if (!$repo) {
            return [];
        }
        [$owner, $repoName] = $repo;
        $url = "{$this->baseUrl}/api/v1/repos/{$owner}/{$repoName}/actions/runs/{$pipelineId}/jobs";
        try {
            $resp = $this->http->get($url);
            if ($resp->getStatusCode() >= 400) {
                return [];
            }
            $data = json_decode($resp->getBody(), true);
            if (!is_array($data)) {
                return [];
            }
            return array_map(fn($j) => [
                'id'         => $j['id'] ?? 0,
                'name'       => $j['name'] ?? ($j['display_title'] ?? ''),
                'stage'      => '',
                'status'     => BuildStatus::normalize((string) ($j['status'] ?? '')),
                'runner'     => $j['runner_name'] ?? '',
                'runner_id'  => $j['runner_id'] ?? null,
                'created_at' => $j['started_at'] ?? '',
                'duration'   => $this->calcDuration((string) ($j['started_at'] ?? ''), (string) ($j['stopped_at'] ?? '')),
            ], $data);
        } catch (\Exception $e) {
            $this->logger?->error('Gitea Actions jobs 查询失败', ['project' => $projectId, 'pipeline' => $pipelineId, 'error' => $e->getMessage()]);
            return [];
        }
    }

    public function getJobTrace(string $projectId, int $jobId): string
    {
        $repo = $this->splitRepo($projectId);
        if (!$repo) {
            return '日志不可用';
        }
        [$owner, $repoName] = $repo;
        $url = "{$this->baseUrl}/api/v1/repos/{$owner}/{$repoName}/actions/jobs/{$jobId}/logs";
        try {
            $resp = $this->http->get($url);
            if ($resp->getStatusCode() >= 400) {
                return '日志不可用（HTTP ' . $resp->getStatusCode() . '）';
            }
            $raw = (string) $resp->getBody();
            return preg_replace("/\e\[[0-9;]*[mK]/", '', $raw);
        } catch (\Exception $e) {
            $this->logger?->error('Gitea Actions 日志查询失败', ['project' => $projectId, 'job' => $jobId, 'error' => $e->getMessage()]);
            return '日志获取失败: ' . $e->getMessage();
        }
    }

    public function trigger(string $projectId, string $ref, array $variables = []): array
    {
        $repo = $this->splitRepo($projectId);
        if (!$repo) {
            return ['success' => false, 'message' => '仓库路径格式错误'];
        }
        [$owner, $repoName] = $repo;
        // Gitea Actions 触发依赖 workflow 文件（dispatch 型 workflow），需显式传入 workflow 文件名
        $workflow = trim((string) ($variables['workflow'] ?? ''));
        unset($variables['workflow']);
        if ($workflow === '') {
            // 未指定 workflow 时自动发现仓库可用 workflow，返回列表供调用方选择
            $workflows = $this->getWorkflows($projectId);
            return [
                'success'   => false,
                'message'   => 'Gitea Actions 主动触发需指定 workflow 文件名，可用列表见 workflows 字段',
                'workflows' => $workflows,
            ];
        }
        $url = "{$this->baseUrl}/api/v1/repos/{$owner}/{$repoName}/actions/workflows/" . rawurlencode($workflow) . '/dispatches?return_run_details=true';
        try {
            // 裸分支名补全为完整 ref；已是 refs/ 开头则原样保留
            $ref = trim($ref) ?: 'main';
            if (!str_starts_with($ref, 'refs/')) {
                $ref = 'refs/heads/' . $ref;
            }
            $body = ['ref' => $ref];
            if ($variables) {
                $body['inputs'] = $variables;
            }
            $resp = $this->http->post($url, ['json' => $body]);
            $data = json_decode($resp->getBody(), true);
            $ok = $resp->getStatusCode() < 400;
            return [
                'success' => $ok,
                'run_id'  => $ok ? ($data['id'] ?? null) : null,
                'web_url' => $ok ? ($data['html_url'] ?? '') : '',
                'message' => $ok ? 'workflow 已触发' : ($data['message'] ?? '触发失败'),
            ];
        } catch (\Exception $e) {
            $this->logger?->error('Gitea Actions 触发失败', ['project' => $projectId, 'ref' => $ref, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => '触发失败: ' . $e->getMessage()];
        }
    }

    public function retry(string $projectId, int $pipelineId): array
    {
        $repo = $this->splitRepo($projectId);
        if (!$repo) {
            return ['success' => false, 'message' => '仓库路径格式错误'];
        }
        [$owner, $repoName] = $repo;
        $url = "{$this->baseUrl}/api/v1/repos/{$owner}/{$repoName}/actions/runs/{$pipelineId}/rerun";
        try {
            $resp = $this->http->post($url);
            $data = json_decode($resp->getBody(), true);
            return [
                'success' => $resp->getStatusCode() < 400,
                'message' => $resp->getStatusCode() < 400 ? 'rerun 已触发' : ($data['message'] ?? 'rerun 失败'),
            ];
        } catch (\Exception $e) {
            $this->logger?->error('Gitea Actions rerun 失败', ['project' => $projectId, 'run' => $pipelineId, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'rerun 失败: ' . $e->getMessage()];
        }
    }

    public function cancel(string $projectId, int $pipelineId): array
    {
        $repo = $this->splitRepo($projectId);
        if (!$repo) {
            return ['success' => false, 'message' => '仓库路径格式错误'];
        }
        [$owner, $repoName] = $repo;
        $url = "{$this->baseUrl}/api/v1/repos/{$owner}/{$repoName}/actions/runs/{$pipelineId}/cancel";
        try {
            $resp = $this->http->post($url);
            $data = json_decode($resp->getBody(), true);
            return [
                'success' => $resp->getStatusCode() < 400,
                'message' => $resp->getStatusCode() < 400 ? 'cancel 已触发' : ($data['message'] ?? 'cancel 失败'),
            ];
        } catch (\Exception $e) {
            $this->logger?->error('Gitea Actions cancel 失败', ['project' => $projectId, 'run' => $pipelineId, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'cancel 失败: ' . $e->getMessage()];
        }
    }

    public function getVariables(string $projectId): array
    {
        $repo = $this->splitRepo($projectId);
        if (!$repo) {
            return [];
        }
        [$owner, $repoName] = $repo;
        $url = "{$this->baseUrl}/api/v1/repos/{$owner}/{$repoName}/actions/variables?limit=100&page=1";
        try {
            $resp = $this->http->get($url);
            if ($resp->getStatusCode() >= 400) {
                return []; // Actions variables 端点不可用（老版本）→ 优雅降级
            }
            $data = json_decode($resp->getBody(), true);
            if (!is_array($data)) {
                return [];
            }
            $vars = [];
            foreach ($data as $v) {
                $name = (string) ($v['name'] ?? '');
                if ($name === '') {
                    continue;
                }
                $vars[] = [
                    'key'   => $name,
                    'value' => '***', // 脱敏，对齐 GitLab provider
                    'type'  => 'string',
                ];
            }
            return $vars;
        } catch (\Exception $e) {
            $this->logger?->error('Gitea Actions variables 查询失败', ['project' => $projectId, 'error' => $e->getMessage()]);
            return [];
        }
    }

    /** 仓库可用 workflow 列表（id=文件名 dispatch 用；name=展示名；path=文件路径；state=active/disabled） */
    public function getWorkflows(string $projectId): array
    {
        $repo = $this->splitRepo($projectId);
        if (!$repo) {
            return [];
        }
        [$owner, $repoName] = $repo;
        $url = "{$this->baseUrl}/api/v1/repos/{$owner}/{$repoName}/actions/workflows";
        try {
            $resp = $this->http->get($url);
            if ($resp->getStatusCode() >= 400) {
                return [];
            }
            $data = json_decode($resp->getBody(), true);
            if (!is_array($data)) {
                return [];
            }
            return array_map(fn($w) => [
                'id'    => $w['id'] ?? '',
                'name'  => $w['name'] ?? '',
                'path'  => $w['path'] ?? '',
                'state' => $w['state'] ?? '',
            ], $data['workflows'] ?? []);
        } catch (\Exception $e) {
            $this->logger?->error('Gitea Actions workflows 查询失败', ['project' => $projectId, 'error' => $e->getMessage()]);
            return [];
        }
    }

    /** repo 级 runner 状态（Gitea 1.21+）：online/offline + busy + labels；last_seen 不暴露 */
    public function getRunners(string $projectId): array
    {
        $repo = $this->splitRepo($projectId);
        if (!$repo) {
            return [];
        }
        [$owner, $repoName] = $repo;
        $url = "{$this->baseUrl}/api/v1/repos/{$owner}/{$repoName}/actions/runners?page=1&limit=100";
        try {
            $resp = $this->http->get($url);
            if ($resp->getStatusCode() >= 400) {
                return [];
            }
            $data = json_decode($resp->getBody(), true);
            if (!is_array($data)) {
                return [];
            }
            return array_map(fn($r) => [
                'id'       => $r['id'] ?? 0,
                'name'     => $r['name'] ?? '',
                'status'   => $r['status'] ?? 'unknown',
                'busy'     => (bool) ($r['busy'] ?? false),
                'labels'   => array_column((array) ($r['labels'] ?? []), 'name'),
                'os'       => $r['os'] ?? '',
                'arch'     => $r['arch'] ?? '',
                'version'  => $r['version'] ?? '',
                'disabled' => (bool) ($r['disabled'] ?? false),
            ], $data['runners'] ?? []);
        } catch (\Exception $e) {
            $this->logger?->error('Gitea Actions runners 查询失败', ['project' => $projectId, 'error' => $e->getMessage()]);
            return [];
        }
    }

    public function getBranches(string $projectId): array
    {
        $repo = $this->splitRepo($projectId);
        if (!$repo) {
            return [];
        }
        [$owner, $repoName] = $repo;
        $url = "{$this->baseUrl}/api/v1/repos/{$owner}/{$repoName}/branches?limit=100&page=1";
        try {
            $resp = $this->http->get($url);
            if ($resp->getStatusCode() >= 400) {
                return [];
            }
            $data = json_decode($resp->getBody(), true);
            if (!is_array($data)) {
                return [];
            }
            return array_values(array_filter(array_map(fn($b) => $b['name'] ?? '', $data), fn($n) => $n !== ''));
        } catch (\Exception $e) {
            $this->logger?->warning('Gitea 分支查询失败', ['project' => $projectId, 'error' => $e->getMessage()]);
            return [];
        }
    }

    public function setCommitStatus(string $projectId, string $sha, string $state, string $name, string $description, string $targetUrl = ''): array
    {
        $repo = $this->splitRepo($projectId);
        if (!$repo) {
            return ['success' => false, 'message' => '仓库路径格式错误'];
        }
        [$owner, $repoName] = $repo;
        $body = [
            'state'       => $state,
            'context'     => $name,
            'description' => $description,
        ];
        if ($targetUrl) {
            $body['target_url'] = $targetUrl;
        }
        $url = "{$this->baseUrl}/api/v1/repos/{$owner}/{$repoName}/statuses/{$sha}";
        try {
            $resp = $this->http->post($url, ['json' => $body]);
            $data = json_decode($resp->getBody(), true);
            return [
                'success' => $resp->getStatusCode() < 400,
                'message' => $resp->getStatusCode() < 400 ? 'status 已回写' : ($data['message'] ?? '回写失败'),
            ];
        } catch (\Exception $e) {
            $this->logger?->error('Gitea commit status 回写失败', ['project' => $projectId, 'sha' => $sha, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => '回写失败: ' . $e->getMessage()];
        }
    }
}
