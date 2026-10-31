<?php

namespace App\Service\Build;

use GuzzleHttp\Client;
use App\Service\Logger;
use App\Service\I18nService;
use App\Config\AppConfig;

/**
 * GitHub Actions 构建 Provider（拉取式 CI）。
 *
 * 对接 GitHub REST API（api.github.com）。GitHub Actions 与 Gitea Actions 同源（workflow 文件
 * 驱动的 runner 模型），但有两处关键差异：
 *   1. base_url 是 api.github.com（API 主机），无 /api/v1 前缀，端点直接挂在 /repos/... 下；
 *   2. run/job 的 status（queued/in_progress/completed）与 conclusion（success/failure/…）分离，
 *      completed 时需取 conclusion 才是最终结果。
 *
 * projectId 约定为 `owner/repo`（与 job_git_map.job_name/current_path 一致）。
 */
class GithubActionsBuildProvider implements BuildProviderInterface
{
    private Client $http;
    private string $baseUrl;
    private ?Logger $logger;

    public function __construct(string $baseUrl, string $token, private I18nService $i18n, ?Logger $logger = null)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->logger  = $logger;
        $this->http    = new Client([
            'headers' => [
                'Authorization'        => 'token ' . $token,
                'Accept'               => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
            ],
            'timeout'         => 15,
            'connect_timeout' => 10,
            'http_errors'     => false,
        ]);
    }

    public function getName(): string
    {
        return AppConfig::PROVIDER_GITHUB_ACTIONS;
    }

    /**
     * 拆分 projectId（owner/repo）为 [owner, repo]，非法则返回 null
     * @return ?list<string>
     */
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

    /**
     * GitHub run/job 状态归一：completed 时结论在 conclusion 字段，其余状态直接用 status。
     *
     * @param array<string, mixed> $item
     */
    private function normalizeStatus(array $item): string
    {
        $status = strtolower(trim((string) ($item['status'] ?? '')));
        if ($status === 'completed') {
            return BuildStatus::normalize((string) ($item['conclusion'] ?? ''));
        }
        return BuildStatus::normalize($status);
    }

    /** @return list<array<string, mixed>> */
    public function getPipelines(string $projectId, int $perPage = 20): array
    {
        $repo = $this->splitRepo($projectId);
        if (!$repo) {
            return [];
        }
        [$owner, $repoName] = $repo;
        $url = "{$this->baseUrl}/repos/{$owner}/{$repoName}/actions/runs?per_page={$perPage}&page=1";
        try {
            $resp = $this->http->get($url);
            if ($resp->getStatusCode() >= 400) {
                $this->logger?->debug('GitHub Actions runs 端点不可用', ['project' => $projectId, 'status' => $resp->getStatusCode()]);
                return [];
            }
            $data = json_decode($resp->getBody(), true);
            if (!is_array($data)) {
                return [];
            }
            $runs = $data['workflow_runs'] ?? [];
            if (!is_array($runs)) {
                return [];
            }
            return array_values(array_map(fn($r) => [
                'id'         => $r['id'] ?? 0,
                'iid'        => $r['run_number'] ?? 0,
                'status'     => $this->normalizeStatus($r),
                'ref'        => $r['head_branch'] ?? '',
                'sha'        => $r['head_sha'] ?? '',
                // web_url 用 github.com（浏览器地址），不能用 api.github.com
                'web_url'    => $r['html_url'] ?? "https://github.com/{$owner}/{$repoName}/actions/runs/" . ($r['id'] ?? 0),
                'created_at' => $this->fmtTime($r['created_at'] ?? ''),
                'updated_at' => $this->fmtTime($r['updated_at'] ?? ''),
            ], $runs));
        } catch (\Exception $e) {
            $this->logger?->error('GitHub Actions runs 查询失败', ['project' => $projectId, 'error' => $e->getMessage()]);
            BuildProviderError::throwFor($e, 'GitHub Actions');
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

    /** job 耗时（秒）：completed_at - started_at，任一缺失或非法返回 0 */
    private function calcDuration(string $startedAt, string $stoppedAt): int
    {
        $start = strtotime($startedAt);
        $stop  = strtotime($stoppedAt);
        if ($start !== false && $stop !== false && $stop >= $start) {
            return $stop - $start;
        }
        return 0;
    }

    /**
     * 分页拉取 GitHub 列表端点（per_page=100），把多页拼接成完整列表。
     * 某页返回少于 per_page 条（或空）即停；4xx 视为端点不可用，返回已收集部分（首页 4xx 即空）。
     *
     * @param string|null $key 响应体中列表字段名；null 表示响应体本身就是列表（如 branches 端点）
     * @return list<array<string, mixed>>
     */
    private function fetchPaged(string $url, ?string $key = null): array
    {

        $all = [];
        $sep = str_contains($url, '?') ? '&' : '?';
        for ($page = 1; $page <= 100; $page++) {
            $resp = $this->http->get("{$url}{$sep}per_page=100&page={$page}");
            if ($resp->getStatusCode() >= 400) {
                break;
            }
            $data = json_decode($resp->getBody(), true);
            $list = $key !== null ? ($data[$key] ?? null) : $data;
            if (!is_array($list)) {
                break;
            }

            $items = array_values($list);
            if ($items === []) {
                break;
            }
            foreach ($items as $item) {
                $all[] = $item;
            }
            if (count($items) < 100) {
                break;
            }
        }
        return $all;
    }

    /** @return list<array<string, mixed>> */
    public function getJobs(string $projectId, int $pipelineId): array
    {
        $repo = $this->splitRepo($projectId);
        if (!$repo) {
            return [];
        }
        [$owner, $repoName] = $repo;
        $url = "{$this->baseUrl}/repos/{$owner}/{$repoName}/actions/runs/{$pipelineId}/jobs";
        try {
            $jobs = $this->fetchPaged($url, 'jobs');
            return array_map(fn($j) => [
                'id'         => $j['id'] ?? 0,
                'name'       => $j['name'] ?? '',
                'stage'      => '',
                'status'     => $this->normalizeStatus($j),
                'runner'     => $j['runner_name'] ?? '',
                'runner_id'  => $j['runner_id'] ?? null,
                'created_at' => $j['started_at'] ?? '',
                'duration'   => $this->calcDuration((string) ($j['started_at'] ?? ''), (string) ($j['completed_at'] ?? '')),
            ], $jobs);
        } catch (\Exception $e) {
            $this->logger?->error('GitHub Actions jobs 查询失败', ['project' => $projectId, 'pipeline' => $pipelineId, 'error' => $e->getMessage()]);
            BuildProviderError::throwFor($e, 'GitHub Actions');
        }
    }

    public function getJobTrace(string $projectId, int $jobId): string
    {
        $repo = $this->splitRepo($projectId);
        if (!$repo) {
            return '日志不可用';
        }
        [$owner, $repoName] = $repo;
        $url = "{$this->baseUrl}/repos/{$owner}/{$repoName}/actions/jobs/{$jobId}/logs";
        try {
            $resp = $this->http->get($url);
            if ($resp->getStatusCode() >= 400) {
                return '日志不可用（HTTP ' . $resp->getStatusCode() . '）';
            }
            $raw = (string) $resp->getBody();
            return (string) preg_replace("/\e\[[0-9;]*[mK]/", '', $raw);
        } catch (\Exception $e) {
            $this->logger?->error('GitHub Actions 日志查询失败', ['project' => $projectId, 'job' => $jobId, 'error' => $e->getMessage()]);
            BuildProviderError::throwFor($e, 'GitHub Actions');
        }
    }

    /**
     * @param array<string, mixed> $variables
     * @return array<string, mixed>
     */
    public function trigger(string $projectId, string $ref, array $variables = []): array
    {
        $repo = $this->splitRepo($projectId);
        if (!$repo) {
            return ['success' => false, 'message' => $this->i18n->trans('git.repo_path_invalid')];
        }
        [$owner, $repoName] = $repo;

        $workflow = trim((string) ($variables['workflow'] ?? ''));
        unset($variables['workflow']);
        if ($workflow === '') {
            $workflows = $this->getWorkflows($projectId);
            return [
                'success'   => false,
                'message'   => $this->i18n->trans('build.workflow_required', ['name' => 'GitHub Actions']),
                'workflows' => $workflows,
            ];
        }
        $url = "{$this->baseUrl}/repos/{$owner}/{$repoName}/actions/workflows/" . rawurlencode($workflow) . '/dispatches';
        try {
            $ref = trim($ref) ?: 'main';

            $ref = preg_replace('#^refs/(heads|tags)/#', '', $ref);
            $body = ['ref' => $ref];
            if ($variables) {
                $body['inputs'] = $variables;
            }
            $resp = $this->http->post($url, ['json' => $body]);

            return [
                'success' => $resp->getStatusCode() < 400,
                'run_id'  => null,
                'web_url' => "https://github.com/{$owner}/{$repoName}/actions",
                'message' => $resp->getStatusCode() < 400 ? 'workflow 已触发' : '触发失败（HTTP ' . $resp->getStatusCode() . '）',
            ];
        } catch (\Exception $e) {
            $this->logger?->error('GitHub Actions 触发失败', ['project' => $projectId, 'ref' => $ref, 'error' => $e->getMessage()]);
            BuildProviderError::throwFor($e, 'GitHub Actions');
        }
    }

    /** @return array<string, mixed> */
    public function retry(string $projectId, int $pipelineId): array
    {
        $repo = $this->splitRepo($projectId);
        if (!$repo) {
            return ['success' => false, 'message' => $this->i18n->trans('git.repo_path_invalid')];
        }
        [$owner, $repoName] = $repo;
        $url = "{$this->baseUrl}/repos/{$owner}/{$repoName}/actions/runs/{$pipelineId}/rerun";
        try {
            $resp = $this->http->post($url);
            $data = json_decode($resp->getBody(), true);
            return [
                'success' => $resp->getStatusCode() < 400,
                'message' => $resp->getStatusCode() < 400 ? 'rerun 已触发' : ($data['message'] ?? 'rerun 失败'),
            ];
        } catch (\Exception $e) {
            $this->logger?->error('GitHub Actions rerun 失败', ['project' => $projectId, 'run' => $pipelineId, 'error' => $e->getMessage()]);
            BuildProviderError::throwFor($e, 'GitHub Actions');
        }
    }

    /** @return array<string, mixed> */
    public function cancel(string $projectId, int $pipelineId): array
    {
        $repo = $this->splitRepo($projectId);
        if (!$repo) {
            return ['success' => false, 'message' => $this->i18n->trans('git.repo_path_invalid')];
        }
        [$owner, $repoName] = $repo;
        $url = "{$this->baseUrl}/repos/{$owner}/{$repoName}/actions/runs/{$pipelineId}/cancel";
        try {
            $resp = $this->http->post($url);
            $data = json_decode($resp->getBody(), true);
            return [
                'success' => $resp->getStatusCode() < 400,
                'message' => $resp->getStatusCode() < 400 ? 'cancel 已触发' : ($data['message'] ?? 'cancel 失败'),
            ];
        } catch (\Exception $e) {
            $this->logger?->error('GitHub Actions cancel 失败', ['project' => $projectId, 'run' => $pipelineId, 'error' => $e->getMessage()]);
            BuildProviderError::throwFor($e, 'GitHub Actions');
        }
    }

    /** @return list<array<string, mixed>> */
    public function getVariables(string $projectId): array
    {
        $repo = $this->splitRepo($projectId);
        if (!$repo) {
            return [];
        }
        [$owner, $repoName] = $repo;
        $url = "{$this->baseUrl}/repos/{$owner}/{$repoName}/actions/variables?per_page=100&page=1";
        try {
            $resp = $this->http->get($url);
            if ($resp->getStatusCode() >= 400) {
                return [];
            }
            $data = json_decode($resp->getBody(), true);
            if (!is_array($data)) {
                return [];
            }
            $vars = [];
            foreach (($data['variables'] ?? []) as $v) {
                $name = (string) ($v['name'] ?? '');
                if ($name === '') {
                    continue;
                }
                $vars[] = [
                    'key'   => $name,
                    'value' => '***',
                    'type'  => 'string',
                ];
            }
            return $vars;
        } catch (\Exception $e) {
            $this->logger?->error('GitHub Actions variables 查询失败', ['project' => $projectId, 'error' => $e->getMessage()]);
            BuildProviderError::throwFor($e, 'GitHub Actions');
        }
    }

    /**
     * 仓库可用 workflow 列表（id=文件名 dispatch 用；name=展示名；path=文件路径；state=active/disabled）
     * @return list<array<string, mixed>>
     */
    public function getWorkflows(string $projectId): array
    {
        $repo = $this->splitRepo($projectId);
        if (!$repo) {
            return [];
        }
        [$owner, $repoName] = $repo;
        $url = "{$this->baseUrl}/repos/{$owner}/{$repoName}/actions/workflows";
        try {
            $workflows = $this->fetchPaged($url, 'workflows');
            return array_map(fn($w) => [
                'id'    => basename((string) ($w['path'] ?? '')),
                'name'  => $w['name'] ?? '',
                'path'  => $w['path'] ?? '',
                'state' => $w['state'] ?? '',
            ], $workflows);
        } catch (\Exception $e) {
            $this->logger?->error('GitHub Actions workflows 查询失败', ['project' => $projectId, 'error' => $e->getMessage()]);
            BuildProviderError::throwFor($e, 'GitHub Actions');
        }
    }

    /**
     * repo 级 self-hosted runner 状态：online/offline + busy + labels。
     * GitHub 托管 runner（GitHub-hosted）不在该端点返回，属预期。
     * @return list<array<string, mixed>>
     */
    public function getRunners(string $projectId): array
    {
        $repo = $this->splitRepo($projectId);
        if (!$repo) {
            return [];
        }
        [$owner, $repoName] = $repo;
        $url = "{$this->baseUrl}/repos/{$owner}/{$repoName}/actions/runners?per_page=100&page=1";
        try {
            $resp = $this->http->get($url);
            if ($resp->getStatusCode() >= 400) {
                return [];
            }
            $data = json_decode($resp->getBody(), true);
            if (!is_array($data)) {
                return [];
            }
            return array_values(array_map(fn($r) => [
                'id'       => $r['id'] ?? 0,
                'name'     => $r['name'] ?? '',
                'status'   => $r['status'] ?? 'unknown',
                'busy'     => (bool) ($r['busy'] ?? false),
                'labels'   => array_column((array) ($r['labels'] ?? []), 'name'),
                'os'       => $r['os'] ?? '',
                'arch'     => '',
                'version'  => '',
                'disabled' => false,
            ], $data['runners'] ?? []));
        } catch (\Exception $e) {
            $this->logger?->error('GitHub Actions runners 查询失败', ['project' => $projectId, 'error' => $e->getMessage()]);
            BuildProviderError::throwFor($e, 'GitHub Actions');
        }
    }

    /** @return array<int, string> */
    public function getBranches(string $projectId): array
    {
        $repo = $this->splitRepo($projectId);
        if (!$repo) {
            return [];
        }
        [$owner, $repoName] = $repo;
        $url = "{$this->baseUrl}/repos/{$owner}/{$repoName}/branches";
        try {
            $branches = $this->fetchPaged($url);
            return array_values(array_filter(array_map(fn($b) => $b['name'] ?? '', $branches), fn($n) => $n !== ''));
        } catch (\Exception $e) {
            $this->logger?->warning('GitHub 分支查询失败', ['project' => $projectId, 'error' => $e->getMessage()]);
            BuildProviderError::throwFor($e, 'GitHub Actions');
        }
    }

    /** @return array<string, mixed> */
    public function setCommitStatus(string $projectId, string $sha, string $state, string $name, string $description, string $targetUrl = ''): array
    {
        $repo = $this->splitRepo($projectId);
        if (!$repo) {
            return ['success' => false, 'message' => $this->i18n->trans('git.repo_path_invalid')];
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
        $url = "{$this->baseUrl}/repos/{$owner}/{$repoName}/statuses/{$sha}";
        try {
            $resp = $this->http->post($url, ['json' => $body]);
            $data = json_decode($resp->getBody(), true);
            return [
                'success' => $resp->getStatusCode() < 400,
                'message' => $resp->getStatusCode() < 400 ? 'status 已回写' : ($data['message'] ?? '回写失败'),
            ];
        } catch (\Exception $e) {
            $this->logger?->error('GitHub commit status 回写失败', ['project' => $projectId, 'sha' => $sha, 'error' => $e->getMessage()]);
            BuildProviderError::throwFor($e, 'GitHub Actions');
        }
    }
}
