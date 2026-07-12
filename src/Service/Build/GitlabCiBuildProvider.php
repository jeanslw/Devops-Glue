<?php
namespace App\Service\Build;

use GuzzleHttp\Client;
use App\Service\Logger;

class GitlabCiBuildProvider implements BuildProviderInterface
{
    private Client $http;
    private string $baseUrl;
    private ?Logger $logger;

    public function __construct(string $baseUrl, string $token, ?Logger $logger = null)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->logger  = $logger;
        $this->http    = new Client([
            'headers'     => ['PRIVATE-TOKEN' => $token],
            'timeout'     => 15,
            'http_errors' => false,
        ]);
    }

    public function getName(): string { return 'gitlab_ci'; }

    public function getPipelines(string $projectId, int $perPage = 20): array
    {
        $encoded = urlencode($projectId);
        $url = "{$this->baseUrl}/api/v4/projects/{$encoded}/pipelines?per_page={$perPage}&sort=desc";
        try {
            $resp = $this->http->get($url);
            $data = json_decode($resp->getBody(), true);
            if (!is_array($data)) return [];
            return array_map(fn($p) => [
                'id'         => $p['id'] ?? 0,
                'iid'        => $p['iid'] ?? 0,
                'status'     => $p['status'] ?? 'unknown',
                'ref'        => $p['ref'] ?? '',
                'sha'        => $p['sha'] ?? '',
                'web_url'    => $p['web_url'] ?? '',
                'created_at' => $p['created_at'] ?? '',
                'updated_at' => $p['updated_at'] ?? '',
            ], $data);
        } catch (\Exception $e) {
            $this->logger?->error('GitLab CI pipeline 查询失败', ['project' => $projectId, 'error' => $e->getMessage()]);
            return [];
        }
    }

    public function getJobs(string $projectId, int $pipelineId): array
    {
        $encoded = urlencode($projectId);
        $url = "{$this->baseUrl}/api/v4/projects/{$encoded}/pipelines/{$pipelineId}/jobs";
        try {
            $resp = $this->http->get($url);
            $data = json_decode($resp->getBody(), true);
            if (!is_array($data)) return [];
            return array_map(fn($j) => [
                'id'         => $j['id'] ?? 0,
                'name'       => $j['name'] ?? '',
                'stage'      => $j['stage'] ?? '',
                'status'     => $j['status'] ?? 'unknown',
                'runner'     => $j['runner']['description'] ?? '',
                'created_at' => $j['created_at'] ?? '',
                'duration'   => $j['duration'] ?? 0,
            ], $data);
        } catch (\Exception $e) {
            $this->logger?->error('GitLab CI job 查询失败', ['project' => $projectId, 'pipeline' => $pipelineId, 'error' => $e->getMessage()]);
            return [];
        }
    }

    public function getJobTrace(string $projectId, int $jobId): string
    {
        $encoded = urlencode($projectId);
        $url = "{$this->baseUrl}/api/v4/projects/{$encoded}/jobs/{$jobId}/trace";
        try {
            $resp = $this->http->get($url);
            $raw = (string) $resp->getBody();
            // 清洗 ANSI 转义码 + GitLab Runner 时间戳前缀
            $raw = preg_replace("/\e\[[0-9;]*[mK]/", '', $raw);
            $raw = preg_replace('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d+Z\s+\d+[A-Z]\s+/m', '', $raw);
            return $raw;
        } catch (\Exception $e) {
            $this->logger?->error('GitLab CI job trace 查询失败', ['project' => $projectId, 'job' => $jobId, 'error' => $e->getMessage()]);
            return '日志获取失败: ' . $e->getMessage();
        }
    }

    public function trigger(string $projectId, string $ref, array $variables = []): array
    {
        $encoded = urlencode($projectId);
        $url = "{$this->baseUrl}/api/v4/projects/{$encoded}/pipeline?ref=" . urlencode($ref);
        try {
            $body = [];
            if ($variables) {
                $body['variables'] = array_map(fn($k, $v) => ['key' => $k, 'value' => $v], array_keys($variables), $variables);
            }
            $resp = $this->http->post($url, ['json' => $body]);
            $data = json_decode($resp->getBody(), true);
            return [
                'success' => $resp->getStatusCode() < 400,
                'pipeline_id' => $data['id'] ?? 0,
                'iid'         => $data['iid'] ?? 0,
                'web_url'     => $data['web_url'] ?? '',
                'message'     => $resp->getStatusCode() < 400 ? 'ok' : ($data['message'] ?? '触发失败'),
            ];
        } catch (\Exception $e) {
            $this->logger?->error('GitLab CI trigger 失败', ['project' => $projectId, 'ref' => $ref, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => '触发失败: ' . $e->getMessage()];
        }
    }

    public function retry(string $projectId, int $pipelineId): array
    {
        $encoded = urlencode($projectId);
        $url = "{$this->baseUrl}/api/v4/projects/{$encoded}/pipelines/{$pipelineId}/retry";
        try {
            $resp = $this->http->post($url);
            $data = json_decode($resp->getBody(), true);
            return [
                'success' => $resp->getStatusCode() < 400,
                'message' => $resp->getStatusCode() < 400 ? 'retry 已触发' : ($data['message'] ?? 'retry 失败'),
            ];
        } catch (\Exception $e) {
            $this->logger?->error('GitLab CI retry 失败', ['project' => $projectId, 'pipeline' => $pipelineId, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'retry 失败: ' . $e->getMessage()];
        }
    }

    public function cancel(string $projectId, int $pipelineId): array
    {
        $encoded = urlencode($projectId);
        $url = "{$this->baseUrl}/api/v4/projects/{$encoded}/pipelines/{$pipelineId}/cancel";
        try {
            $resp = $this->http->post($url);
            $data = json_decode($resp->getBody(), true);
            return [
                'success' => $resp->getStatusCode() < 400,
                'message' => $resp->getStatusCode() < 400 ? 'cancel 已触发' : ($data['message'] ?? 'cancel 失败'),
            ];
        } catch (\Exception $e) {
            $this->logger?->error('GitLab CI cancel 失败', ['project' => $projectId, 'pipeline' => $pipelineId, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'cancel 失败: ' . $e->getMessage()];
        }
    }

    public function getVariables(string $projectId): array
    {
        $encoded = urlencode($projectId);
        $url = "{$this->baseUrl}/api/v4/projects/{$encoded}/variables?per_page=100";
        try {
            $resp = $this->http->get($url);
            $data = json_decode($resp->getBody(), true);
            if (!is_array($data)) return [];
            return array_map(fn($v) => [
                'key'       => $v['key'] ?? '',
                'value'     => '***',    // 脱敏
                'protected' => $v['protected'] ?? false,
                'masked'    => $v['masked'] ?? false,
                'variable_type' => $v['variable_type'] ?? 'env_var',
            ], $data);
        } catch (\Exception $e) {
            $this->logger?->error('GitLab CI variables 查询失败', ['project' => $projectId, 'error' => $e->getMessage()]);
            return [];
        }
    }

    public function setCommitStatus(string $projectId, string $sha, string $state, string $name, string $description, string $targetUrl = ''): array
    {
        $encoded = urlencode($projectId);
        $url = "{$this->baseUrl}/api/v4/projects/{$encoded}/statuses/{$sha}";
        try {
            $body = [
                'state'       => $state,
                'name'        => $name,
                'description' => $description,
            ];
            if ($targetUrl) $body['target_url'] = $targetUrl;

            $resp = $this->http->post($url, ['json' => $body]);
            $data = json_decode($resp->getBody(), true);
            return [
                'success' => $resp->getStatusCode() < 400,
                'message' => $resp->getStatusCode() < 400 ? 'status 已回写' : ($data['message'] ?? '回写失败'),
            ];
        } catch (\Exception $e) {
            $this->logger?->error('GitLab commit status 回写失败', ['project' => $projectId, 'sha' => $sha, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => '回写失败: ' . $e->getMessage()];
        }
    }
}
