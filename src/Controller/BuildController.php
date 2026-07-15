<?php
namespace App\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Config\AppConfig;
use App\Service\Build\BuildProviderRegistry;
use App\Service\HarborService;
use App\Service\MappingManager;

class BuildController extends BaseController
{
    private BuildProviderRegistry $registry;
    private AppConfig $config;
    private MappingManager $mapping;
    private ?HarborService $harbor;

    public function __construct(BuildProviderRegistry $registry, AppConfig $config, MappingManager $mapping, ?HarborService $harbor = null)
    {
        $this->registry = $registry;
        $this->config   = $config;
        $this->mapping  = $mapping;
        $this->harbor   = $harbor;
    }

    private function resolve(string $projectPath): array
    {
        $r = $this->mapping->resolveProject($projectPath);
        return [$r['provider'], $r['projectId']];
    }

    public function jobsList(Request $request, Response $response): Response
    {
        $all = [];
        foreach ($this->mapping->activeMaps() as $m) {
            $all[] = [
                'job_name'     => $m['job_name'] ?? '',
                'ci_provider'  => $m['build_provider'] ?? 'jenkins',
                'project_id'   => $m['project_id'] ?? ($m['current_path'] ?? $m['job_name']),
                'current_path' => $m['current_path'] ?? '',
            ];
        }
        // ?format=raw → 纯 job 名数组
        if (($request->getQueryParams()['format'] ?? 'raw') === 'raw') {
            return $this->output($response, array_column($all, 'job_name'), $request);
        }
        return $this->output($response, $all, $request);
    }

    public function configMode(Request $request, Response $response): Response
    {
        $hasJenkins = $this->mapping->hasJenkins() && $this->registry->isRegistered('jenkins');
        $hasGitlab  = $this->mapping->hasGitlabCi() && $this->registry->isRegistered('gitlab_ci');
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
                'id'      => array_map($idx, $filtered),
                'success' => array_map($idx, $filtered),
                'build'   => array_map(fn($p) => '#' . $idx($p), $filtered),
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

        // ?format=raw → Jenkins 风格 ["SUCCESS"] / ["failed"]
        if (($request->getQueryParams()['format'] ?? 'raw') === 'raw') {
            $statuses = array_map(fn($j) => $j['status'] ?? 'unknown', $jobs);
            return $this->output($response, $statuses, $request);
        }

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

    /** POST /api/build/{path}/trigger（兼容 GET Query String 触发） */
    public function trigger(Request $request, Response $response, array $args): Response
    {
        $path = $args['path'] ?? '';
        $body = $request->getParsedBody() ?? [];
        $qs   = $request->getQueryParams();
        [$provider, $projectId] = $this->resolve($path);

        if (!$this->registry->isRegistered($provider)) {
            return $this->jsonError($response, "Build 系统 '{$provider}' 未配置", 400);
        }

        // POST JSON body 优先，GET query string 兜底（兼容旧版 Jenkins 调用方式）
        $ref  = $body['ref'] ?? $qs['ref'] ?? '';
        // 合并：POST body 根级 + variables 嵌套 + Query String，全部当参数
        $vars = $body['variables'] ?? [];
        foreach ($body as $k => $v) {
            if (!in_array($k, ['ref','variables','format','token']) && !isset($vars[$k])) $vars[$k] = $v;
        }
        foreach ($qs as $k => $v) {
            if (!in_array($k, ['format','token','ref']) && !isset($vars[$k])) $vars[$k] = $v;
        }
        if (empty($vars) && !empty($ref)) $vars['branches'] = $ref;

        $p      = $this->registry->create($provider);
        $result = $p->trigger($projectId, $ref, $vars);
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

        $simple = [];
        foreach ($vars as $v) { $simple[$v['key']] = $v['options'] ?? []; }

        // ?format=json 返回完整格式，默认/raw 返回简单格式
        if (($request->getQueryParams()['format'] ?? 'raw') === 'json') {
            return $this->output($response, [
                'build_provider' => $provider,
                'project_id'     => $projectId,
                'variables'      => $vars,
            ], $request);
        }

        return $this->output($response, $simple, $request);
    }

    /** POST /api/build/{path}/scan-sync（公共端点，不限制 BUILD_MODE） */
    public function scanSync(Request $request, Response $response, array $args): Response
    {
        $path = $args['path'] ?? '';
        $body   = $request->getParsedBody() ?? [];
        $tag    = $body['tag'] ?? null;

        // 1. 获取 job_git_map 中的 harbor 映射（不依赖 CI 系统）
        $maps = $this->config->getJobGitMap();
        $harborRepo = '';
        $provider   = 'jenkins';
        foreach ($maps as $m) {
            $job = $m['job_name'] ?? '';
            $cp  = $m['current_path'] ?? '';
            if ($job === $path || $cp === $path) {
                $harborRepo = $m['harbor_repository'] ?? '';
                $provider   = $m['build_provider'] ?? 'jenkins';
                break;
            }
        }

        if (empty($harborRepo)) {
            return $this->jsonError($response, "项目 '{$path}' 未配置 harbor_repository", 400);
        }

        // 2. 解析 Harbor 仓库信息
        $parts = explode('/', $harborRepo, 2);
        if (count($parts) !== 2) {
            return $this->jsonError($response, "harbor_repository 格式错误: {$harborRepo}", 400);
        }
        [$harborProject, $harborRepoName] = $parts;

        // tag 不传则取 Harbor 最新
        if (!$tag && $this->harbor) {
            $tags = $this->harbor->getTags($harborProject, $harborRepoName);
            if (!empty($tags)) $tag = $tags[0];
        }
        if (!$tag) {
            return $this->jsonError($response, '缺少 tag 参数且 Harbor 无可用 tag', 400);
        }

        // 3. 尝试获取 pipeline info（仅用于 commit status）
        $sha  = '';
        $iid  = 0;
        try {
            $projectId = $path;
            if ($this->registry->isRegistered($provider)) {
                $p = $this->registry->create($provider);
                $pipelines = $p->getPipelines($projectId, 1);
                if (!empty($pipelines)) {
                    $sha = $pipelines[0]['sha'] ?? '';
                    $iid = (int) ($pipelines[0]['iid'] ?? 0);
                }
            }
        } catch (\Exception $e) {}

        // 4. Harbor 扫描 + commit status 回写（仅 GitLab CI + sha 有效时）
        $vulnCount = 0;
        $state     = 'unknown';
        $result    = ['success' => false, 'message' => ''];

        if ($provider === 'gitlab_ci' && $sha) {
            if (!$this->harbor) {
                $state  = 'pending';
                $result = ['success' => false, 'message' => 'Harbor 未配置'];
            } else {
                try {
                    $scan = $this->harbor->getScanReport($harborProject, $harborRepoName, $tag);
                    if (isset($scan['error']) || isset($scan['code'])) {
                        $state  = 'pending';
                        $result = ['success' => false, 'message' => $scan['message'] ?? '扫描功能未启用'];
                    } else {
                        $vulns = $scan['vulnerabilities'] ?? $scan ?? [];
                        $vulnCount = is_array($vulns) ? count($vulns) : 0;
                        $state = $vulnCount > 0 ? 'failed' : 'success';
                    }
                    $desc  = "#{$iid} → {$tag} · " . ($vulnCount > 0 ? "{$vulnCount} vulns" : ($state === 'pending' ? '扫描未启用' : 'clean'));
                    $harborUrl = $this->config->getHarborConfig()['url'] ?? '';
                    $p = $this->registry->create($provider);
                    $result = $p->setCommitStatus($projectId, $sha, $state, 'harbor-scan', $desc, $harborUrl);
                } catch (\Exception $e) {
                    $state  = 'pending';
                    $result = ['success' => false, 'message' => $e->getMessage()];
                }
            }
        }

        // 5. 记录 pipeline → tag 映射
        $this->recordPipelineTag($path, $iid ?: time(), $tag, $harborRepo);

        return $this->output($response, [
            'build_provider'       => $provider,
            'sha'                  => $sha ?: 'N/A',
            'tag'                  => $tag,
            'harbor_repository'    => $harborRepo,
            'vulnerability_count'  => $vulnCount,
            'scan_state'           => $state,
            'commit_status'        => $result,
        ], $request);
    }

    /** GET /api/build/{path}/tag?pipeline=10 — 查 pipeline 对应的 tag */
    public function tagQuery(Request $request, Response $response, array $args): Response
    {
        $path     = $args['path'] ?? '';
        $pipeline = $request->getQueryParams()['pipeline'] ?? '';

        $tags = $this->loadPipelineTags();
        $entry  = $tags[$path] ?? [];
        $tagInfo = $pipeline ? ($entry[$pipeline] ?? null) : null;
        $tag     = is_array($tagInfo) ? ($tagInfo['tag'] ?? '') : $tagInfo;
        $harbor  = is_array($tagInfo) ? ($tagInfo['harbor'] ?? '') : '';

        if ($pipeline && !$tag) {
            if (($request->getQueryParams()['format'] ?? 'raw') === 'raw') {
                return $this->output($response, [], $request);
            }
            return $this->output($response, [
                'build_provider' => $this->mapping->resolveProject($path)['provider'],
                'project_id'     => $this->mapping->resolveProject($path)['projectId'],
                'pipeline'       => $pipeline,
                'tag'            => null,
                'all'            => null,
            ], $request);
        }

        // ?format=raw → 仓库:tag
        if ($pipeline && ($request->getQueryParams()['format'] ?? 'raw') === 'raw') {
            return $this->output($response, $harbor ? [$harbor . ':' . $tag] : [$tag], $request);
        }

        [$provider, $projectId] = $this->resolve($path);
        return $this->output($response, [
            'build_provider' => $provider,
            'project_id'     => $projectId,
            'pipeline'       => $pipeline ?: null,
            'tag'            => $tag,
            'harbor_repository' => $harbor,
            'all'            => $pipeline ? null : $entry,
        ], $request);
    }

    // ── pipeline → tag 映射持久化（SQLite） ──

    private function loadPipelineTags(): array
    {
        try {
            $pdo = \App\Service\Database::getPdo();
            $rows = $pdo->query("SELECT project, pipeline_iid, tag, harbor_repository, created_at FROM ci_pipeline_tags ORDER BY created_at DESC")->fetchAll();
            $result = [];
            foreach ($rows as $r) {
                $result[$r['project']][(string) $r['pipeline_iid']] = [
                    'tag'    => $r['tag'],
                    'harbor' => $r['harbor_repository'] ?? '',
                ];
            }
            return $result;
        } catch (\Exception $e) {
            return [];
        }
    }

    private function recordPipelineTag(string $path, int $pipelineIid, string $tag, string $harborRepo = ''): void
    {
        try {
            $pdo = \App\Service\Database::getPdo();
            $sql   = \App\Service\Database::sqlUpsert('ci_pipeline_tags', 'project, pipeline_iid, tag, harbor_repository', '?, ?, ?, ?');
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$path, $pipelineIid, $tag, $harborRepo]);

            // 回填 job_git_map 的 harbor_repository（如果为空）
            if (!empty($harborRepo)) {
                $pdo->prepare("UPDATE ci_job_git_map SET harbor_repository=? WHERE (job_name=? OR current_path=?) AND (harbor_repository IS NULL OR harbor_repository='')")
                    ->execute([$harborRepo, $path, $path]);
            }
        } catch (\Exception $e) {
            // 静默失败
        }
    }
}
