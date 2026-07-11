<?php
namespace App\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Config\AppConfig;
use App\Service\Build\BuildProviderRegistry;
use App\Service\HarborService;

class BuildController extends BaseController
{
    private BuildProviderRegistry $registry;
    private AppConfig $config;
    private ?HarborService $harbor;

    public function __construct(BuildProviderRegistry $registry, AppConfig $config, ?HarborService $harbor = null)
    {
        $this->registry = $registry;
        $this->config   = $config;
        $this->harbor   = $harbor;
    }

    private function resolve(string $projectPath): array
    {
        $maps = $this->config->getJobGitMap();
        $provider  = 'jenkins';
        $projectId = $projectPath;

        foreach ($maps as $m) {
            $job = $m['job_name'] ?? '';
            $cp  = $m['current_path'] ?? '';
            if ($job === $projectPath || $cp === $projectPath || $job === str_replace('-', '/', $projectPath)) {
                if (($m['status'] ?? 'active') === 'disabled') continue; // 禁用跳过
                if (!empty($m['build_provider'])) $provider = $m['build_provider'];
                if ($provider !== 'jenkins' && !empty($m['project_id'])) $projectId = (string) $m['project_id'];
                break;
            }
        }

        // GitLab CI 用数字 ID，Jenkins 用项目路径
        if ($provider !== 'jenkins' && !is_numeric($projectId)) {
            // 尝试 job_git_map 中 project_id 兜底
            foreach ($maps as $m) {
                if (($m['current_path'] ?? '') === $projectPath && !empty($m['project_id'])) {
                    $projectId = (string) $m['project_id'];
                    break;
                }
            }
        }

        return [$provider, $projectId];
    }

    /** GET /api/build/jobs/list — 全量 Job 列表（Jenkins + GitLab CI） */
    public function jobsList(Request $request, Response $response): Response
    {
        $all = [];
        $names = $this->registry->getRegisteredNames();

        // Jenkins
        if (in_array('jenkins', $names)) {
            try {
                $jenkins = $this->registry->create('jenkins');
                $maps = $this->config->getJobGitMap();
                $jenkinsJobs = [];
                foreach ($maps as $m) {
                    $bp = $m['build_provider'] ?? 'jenkins';
                    if ($bp === 'jenkins') {
                        $jenkinsJobs[] = [
                            'job_name'       => $m['job_name'] ?? '',
                            'ci_provider'    => 'jenkins',
                            'project_id'     => $m['project_id'] ?? ($m['current_path'] ?? $m['job_name']),
                            'current_path'   => $m['current_path'] ?? '',
                        ];
                    }
                }
                $all = array_merge($all, $jenkinsJobs);
            } catch (\Exception $e) {
                // Jenkins 不可用，跳过
            }
        }

        // GitLab CI（从 job_git_map 读取）
        if (in_array('gitlab_ci', $names)) {
            $maps = $this->config->getJobGitMap();
            foreach ($maps as $m) {
                if (($m['build_provider'] ?? 'jenkins') === 'gitlab_ci') {
                    $all[] = [
                        'job_name'       => $m['job_name'] ?? '',
                        'ci_provider'    => 'gitlab_ci',
                        'project_id'     => (string) ($m['project_id'] ?? ''),
                        'current_path'   => $m['current_path'] ?? '',
                    ];
                }
            }
        }

        return $this->output($response, $all, $request);
    }

    /** GET /api/build/config-mode — 公开，返回当前配置模式（不查 CI 系统） */
    public function configMode(Request $request, Response $response): Response
    {
        // 配置模式：.env BUILD_MODE 控制，默认 both
        $envMode = $_ENV['BUILD_MODE'] ?? 'both';
        $hasJenkins = in_array($envMode, ['jenkins', 'both']) && $this->registry->isRegistered('jenkins');
        $hasGitlab  = in_array($envMode, ['gitlab_ci', 'both']) && $this->registry->isRegistered('gitlab_ci');
        $mode = ($hasJenkins && $hasGitlab) ? 'both' : ($hasGitlab ? 'gitlab_ci' : 'jenkins');
        return $this->output($response, ['mode' => $mode, 'has_jenkins' => $hasJenkins, 'has_gitlab_ci' => $hasGitlab], $request);
    }

    /** GET /api/build/{path}/pipelines */
    public function pipelines(Request $request, Response $response, array $args): Response
    {
        $path = $args['path'] ?? '';
        [$provider, $projectId] = $this->resolve($path);

        if (!$this->registry->isRegistered($provider)) {
            return $this->jsonError($response, "Build 系统 '{$provider}' 未配置（已注册: " . implode(', ', $this->registry->getRegisteredNames()) . "）", 400);
        }

        $p = $this->registry->create($provider);
        $data = $p->getPipelines($projectId);

        // Jenkins 风格列表格式
        $listFormat = $request->getQueryParams()['list'] ?? '';
        if (in_array($listFormat, ['id', 'build', 'time', 'success'], true)) {
            $filtered = $data;
            // success / build / time 只返回成功的
            if (in_array($listFormat, ['success', 'build', 'time'], true)) {
                $filtered = array_values(array_filter($data, fn($p) => ($p['status'] ?? '') === 'success'));
            }
            $iidKey = 'iid'; // GitLab CI 用 iid，Jenkins 用 id
            $idx = fn($p) => $p[$iidKey] ?? $p['id'] ?? 0;
            $result = match ($listFormat) {
                'id'      => array_map($idx, $filtered),                                           // [11,10,9]
                'success' => array_map($idx, $filtered),                                           // [2] (仅成功)
                'build'   => array_map(fn($p) => '#' . $idx($p), $filtered),                       // ["#11","#10","#9"]
                'time'    => array_map(fn($p) => '#' . $idx($p) . ' [' . ($p['created_at'] ?? '') . ']', $filtered),
                default   => $filtered,
            };
            return $this->output($response, $result, $request);
        }

        return $this->output($response, [
            'build_provider' => $provider,
            'project_id'     => $projectId,
            'pipelines'      => $data,
        ], $request);
    }

    /** GET /api/build/{path}/pipelines/{id} */
    public function pipelineDetail(Request $request, Response $response, array $args): Response
    {
        $path = $args['path'] ?? '';
        $pipelineId = (int) ($args['id'] ?? 0);
        [$provider, $projectId] = $this->resolve($path);

        if (!$this->registry->isRegistered($provider)) {
            return $this->jsonError($response, "Build 系统 '{$provider}' 未配置", 400);
        }

        $p  = $this->registry->create($provider);
        $jobs = $p->getJobs($projectId, $pipelineId);
        return $this->output($response, [
            'build_provider' => $provider,
            'project_id'     => $projectId,
            'pipeline_id'    => $pipelineId,
            'jobs'           => $jobs,
        ], $request);
    }

    /** GET /api/build/{path}/logs/{id} — 统一日志入口（Jenkins/GitLab CI） */
    public function logs(Request $request, Response $response, array $args): Response
    {
        return $this->jobTrace($request, $response, $args);
    }

    /** GET /api/build/{path}/jobs/{id}/trace */
    public function jobTrace(Request $request, Response $response, array $args): Response
    {
        $path  = $args['path'] ?? '';
        $jobId = (int) ($args['id'] ?? 0);
        [$provider, $projectId] = $this->resolve($path);

        if (!$this->registry->isRegistered($provider)) {
            return $this->jsonError($response, "Build 系统 '{$provider}' 未配置", 400);
        }

        $p     = $this->registry->create($provider);
        $trace = $p->getJobTrace($projectId, $jobId);
        return $this->output($response, $trace, $request, true);
    }

    /** POST /api/build/{path}/trigger */
    public function trigger(Request $request, Response $response, array $args): Response
    {
        $path = $args['path'] ?? '';
        $body = $request->getParsedBody() ?? [];
        [$provider, $projectId] = $this->resolve($path);

        if (!$this->registry->isRegistered($provider)) {
            return $this->jsonError($response, "Build 系统 '{$provider}' 未配置", 400);
        }

        $ref       = $body['ref'] ?? 'main';
        $variables = $body['variables'] ?? [];

        $p      = $this->registry->create($provider);
        $result = $p->trigger($projectId, $ref, $variables);
        return $this->output($response, [
            'build_provider' => $provider,
            'project_id'     => $projectId,
        ] + $result, $request);
    }

    /** POST /api/build/{path}/pipelines/{id}/retry */
    public function retry(Request $request, Response $response, array $args): Response
    {
        $path       = $args['path'] ?? '';
        $pipelineId = (int) ($args['id'] ?? 0);
        [$provider, $projectId] = $this->resolve($path);

        if (!$this->registry->isRegistered($provider)) {
            return $this->jsonError($response, "Build 系统 '{$provider}' 未配置", 400);
        }

        $p      = $this->registry->create($provider);
        $result = $p->retry($projectId, $pipelineId);
        return $this->output($response, [
            'build_provider' => $provider,
            'project_id'     => $projectId,
        ] + $result, $request);
    }

    /** POST /api/build/{path}/pipelines/{id}/cancel */
    public function cancel(Request $request, Response $response, array $args): Response
    {
        $path       = $args['path'] ?? '';
        $pipelineId = (int) ($args['id'] ?? 0);
        [$provider, $projectId] = $this->resolve($path);

        if (!$this->registry->isRegistered($provider)) {
            return $this->jsonError($response, "Build 系统 '{$provider}' 未配置", 400);
        }

        $p      = $this->registry->create($provider);
        $result = $p->cancel($projectId, $pipelineId);
        return $this->output($response, [
            'build_provider' => $provider,
            'project_id'     => $projectId,
        ] + $result, $request);
    }

    /** GET /api/build/{path}/variables */
    public function variables(Request $request, Response $response, array $args): Response
    {
        $path = $args['path'] ?? '';
        [$provider, $projectId] = $this->resolve($path);

        if (!$this->registry->isRegistered($provider)) {
            return $this->jsonError($response, "Build 系统 '{$provider}' 未配置", 400);
        }

        $p   = $this->registry->create($provider);
        $vars = $p->getVariables($projectId);
        return $this->output($response, [
            'build_provider' => $provider,
            'project_id'     => $projectId,
            'variables'      => $vars,
        ], $request);
    }

    /** POST /api/build/{path}/scan-sync */
    public function scanSync(Request $request, Response $response, array $args): Response
    {
        $path = $args['path'] ?? '';
        [$provider, $projectId] = $this->resolve($path);

        if (!$this->registry->isRegistered($provider)) {
            return $this->jsonError($response, "Build 系统 '{$provider}' 未配置", 400);
        }

        if ($provider !== 'gitlab_ci') {
            return $this->jsonError($response, "scan-sync 仅支持 gitlab_ci，当前: {$provider}", 400);
        }

        if (!$this->harbor) {
            return $this->jsonError($response, 'Harbor 未配置', 500);
        }

        // 1. 获取 job_git_map 中的 harbor 映射
        $maps = $this->config->getJobGitMap();
        $harborRepo = '';
        $sha = '';
        foreach ($maps as $m) {
            $job = $m['job_name'] ?? '';
            $cp  = $m['current_path'] ?? '';
            if ($job === $path || $cp === $path) {
                $harborRepo = $m['harbor_repository'] ?? '';
                if (empty($sha) && !empty($m['git_remote'])) {
                    // 从 git_remote 无法获取 sha，需要从 pipeline 拿
                }
                break;
            }
        }

        if (empty($harborRepo)) {
            return $this->jsonError($response, "项目 '{$path}' 未配置 harbor_repository", 400);
        }

        // 2. 获取最新 pipeline
        $p = $this->registry->create($provider);
        $pipelines = $p->getPipelines($projectId, 1);
        if (empty($pipelines)) {
            return $this->jsonError($response, '没有找到 pipeline', 404);
        }
        $latestPipeline  = $pipelines[0];
        $sha  = $latestPipeline['sha'] ?? '';
        $iid  = $latestPipeline['iid'] ?? 0;
        if (empty($sha)) {
            return $this->jsonError($response, 'pipeline 缺少 sha', 400);
        }

        // 3. 查 Harbor 扫描报告
        $parts = explode('/', $harborRepo, 2);
        if (count($parts) !== 2) {
            return $this->jsonError($response, "harbor_repository 格式错误: {$harborRepo}", 400);
        }
        [$harborProject, $harborRepoName] = $parts;

        // 支持手动传 tag（POST body），不传则取最新
        $body   = $request->getParsedBody() ?? [];
        $searchTag = $body['tag'] ?? null;

        try {
            if ($searchTag) {
                $tag = $searchTag;
            } else {
                $tags = $this->harbor->getTags($harborProject, $harborRepoName);
                if (empty($tags)) {
                    return $this->jsonError($response, "Harbor 仓库 {$harborRepo} 没有 tag", 404);
                }
                $tag = $tags[0];
            }

            // 获取扫描报告
            $scan = $this->harbor->getScanReport($harborProject, $harborRepoName, $tag);

            // 4. 判断扫描结果
            $vulns = $scan['vulnerabilities'] ?? $scan ?? [];
            $vulnCount = is_array($vulns) ? count($vulns) : 0;
            $state = $vulnCount > 0 ? 'failed' : 'success';
            $desc  = "#{$iid} → {$tag} · " . ($vulnCount > 0 ? "{$vulnCount} vulns" : 'clean');

            // 5. 回写 GitLab commit status
            $harborUrl = $this->config->getHarborConfig()['url'] ?? '';
            $result = $p->setCommitStatus($projectId, $sha, $state, 'harbor-scan', $desc, $harborUrl);

            // 6. 记录 pipeline → tag 映射
            if ($iid > 0) {
                $this->recordPipelineTag($path, (int) $iid, $tag);
            }

            return $this->output($response, [
                'build_provider'    => $provider,
                'sha'               => $sha,
                'tag'               => $tag,
                'harbor_repository' => $harborRepo,
                'vulnerability_count' => $vulnCount,
                'scan_state'        => $state,
                'commit_status'     => $result,
            ], $request);

        } catch (\Exception $e) {
            return $this->jsonError($response, '扫描同步失败: ' . $e->getMessage(), 500);
        }
    }

    /** GET /api/build/{path}/tag?pipeline=10 — 查 pipeline 对应的 tag */
    public function tagQuery(Request $request, Response $response, array $args): Response
    {
        $path     = $args['path'] ?? '';
        $pipeline = $request->getQueryParams()['pipeline'] ?? '';

        $tags = $this->loadPipelineTags();
        $entry = $tags[$path] ?? [];
        $tag  = $pipeline ? ($entry[$pipeline] ?? null) : null;

        if ($pipeline && !$tag) {
            return $this->jsonError($response, "未找到 {$path} pipeline #{$pipeline} 的 tag 映射", 404);
        }

        [$provider, $projectId] = $this->resolve($path);
        return $this->output($response, [
            'build_provider' => $provider,
            'project_id'     => $projectId,
            'pipeline'       => $pipeline ?: null,
            'tag'            => $tag,
            'all'            => $pipeline ? null : $entry,
        ], $request);
    }

    // ── pipeline → tag 映射持久化（SQLite） ──

    private function loadPipelineTags(): array
    {
        try {
            $pdo = \App\Service\Database::getPdo();
            $rows = $pdo->query("SELECT project, pipeline_iid, tag, created_at FROM pipeline_tags ORDER BY created_at DESC")->fetchAll();
            $result = [];
            foreach ($rows as $r) {
                $result[$r['project']][(string) $r['pipeline_iid']] = $r['tag'];
            }
            return $result;
        } catch (\Exception $e) {
            return [];
        }
    }

    private function recordPipelineTag(string $path, int $pipelineIid, string $tag): void
    {
        try {
            $pdo = \App\Service\Database::getPdo();
            $stmt = $pdo->prepare("INSERT OR REPLACE INTO pipeline_tags (project, pipeline_iid, tag) VALUES (?, ?, ?)");
            $stmt->execute([$path, $pipelineIid, $tag]);
        } catch (\Exception $e) {
            // 静默失败，不影响主流程
        }
    }
}
