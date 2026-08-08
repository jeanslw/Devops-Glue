<?php
namespace App\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Config\AppConfig;
use App\Service\Build\BuildProviderRegistry;
use App\Service\HarborService;
use App\Service\I18nService;
use App\Service\MappingManager;
use App\Service\Git\ProviderRegistry as GitProviderRegistry;

class BuildController extends BaseController
{
    private BuildProviderRegistry $registry;
    private AppConfig $config;
    private MappingManager $mapping;
    private ?HarborService $harbor;
    private ?GitProviderRegistry $gitRegistry;
    private \PDO $pdo;

    public function __construct(I18nService $i18n, BuildProviderRegistry $registry, AppConfig $config, MappingManager $mapping, \PDO $pdo, ?HarborService $harbor = null, ?GitProviderRegistry $gitRegistry = null)
    {
        parent::__construct($i18n);
        $this->registry    = $registry;
        $this->config      = $config;
        $this->mapping     = $mapping;
        $this->pdo         = $pdo;
        $this->harbor      = $harbor;
        $this->gitRegistry = $gitRegistry;
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
                'ci_provider'  => $m['build_provider'] ?? AppConfig::PROVIDER_JENKINS,
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
        $hasJenkins = $this->registry->isRegistered(AppConfig::PROVIDER_JENKINS);
        $hasGitlab  = $this->registry->isRegistered(AppConfig::PROVIDER_GITLAB_CI);
        $mode   = $this->config->getBuildMode();
        $source = $this->config->getBuildModeSource();
        return $this->output($response, ['mode' => $mode, 'source' => $source, 'has_jenkins' => $hasJenkins, 'has_gitlab_ci' => $hasGitlab], $request);
    }

    /** GET /api/build/{path}/pipelines — raw: 流水线数组, json/xml: 完整元数据 */
    public function pipelines(Request $request, Response $response, array $args): Response
    {
        $path = $args['path'] ?? '';
        [$provider, $projectId] = $this->resolve($path);

        if (!$this->registry->isRegistered($provider)) {
            return $this->jsonError($response, $this->__('build.provider_not_configured_registered', [
                '{provider}' => $provider,
                '{registered}' => implode(', ', $this->registry->getRegisteredNames()),
            ]), 400);
        }

        $p = $this->registry->create($provider);
        $data = $p->getPipelines($projectId);
        $format = $request->getQueryParams()['format'] ?? 'raw';

        // Jenkins 风格列表格式（list 参数优先级更高）
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

        // raw: 纯流水线数组
        if ($format === 'raw') {
            return $this->output($response, $data, $request);
        }

        // json/xml: 完整格式
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
            return $this->jsonError($response, $this->__('build.provider_not_configured', ['{provider}' => $provider]), 400);
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
            return $this->jsonError($response, $this->__('build.provider_not_configured', ['{provider}' => $provider]), 400);
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
            return $this->jsonError($response, $this->__('build.provider_not_configured', ['{provider}' => $provider]), 400);
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
        // ref 的自动映射交给 provider 处理（通过参数 _class 动态识别 Git 参数名）

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
            return $this->jsonError($response, $this->__('build.provider_not_configured', ['{provider}' => $provider]), 400);
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
            return $this->jsonError($response, $this->__('build.provider_not_configured', ['{provider}' => $provider]), 400);
        }

        $p      = $this->registry->create($provider);
        $result = $p->cancel($projectId, $pipelineId);
        return $this->output($response, [
            'build_provider' => $provider,
            'project_id'     => $projectId,
        ] + $result, $request);
    }

    /** GET /api/build/{path}/variables — raw: 参数名数组, json/xml: 完整元数据 */
    public function variables(Request $request, Response $response, array $args): Response
    {
        $path = $args['path'] ?? '';
        [$provider, $projectId] = $this->resolve($path);

        if (!$this->registry->isRegistered($provider)) {
            return $this->jsonError($response, $this->__('build.provider_not_configured', ['{provider}' => $provider]), 400);
        }

        $p    = $this->registry->create($provider);
        $vars = $p->getVariables($projectId);
        $format = $request->getQueryParams()['format'] ?? 'raw';

        // raw: 纯参数名数组 ["branches", "zone"]
        if ($format === 'raw') {
            $names = array_column($vars, 'key');
            return $this->output($response, $names, $request);
        }

        // json/xml: 完整格式
        return $this->output($response, [
            'build_provider' => $provider,
            'project_id'     => $projectId,
            'variables'      => $vars,
        ], $request);
    }

    /** GET /api/build/{path}/branches — raw: 分支名数组, json/xml: 完整元数据 */
    public function branches(Request $request, Response $response, array $args): Response
    {
        $path = $args['path'] ?? '';
        [$provider, $projectId] = $this->resolve($path);

        if (!$this->registry->isRegistered($provider)) {
            return $this->jsonError($response, $this->__('build.provider_not_configured', ['{provider}' => $provider]), 400);
        }

        $p        = $this->registry->create($provider);
        $branches = $p->getBranches($projectId);
        $format   = $request->getQueryParams()['format'] ?? 'raw';

        // raw: 纯分支名数组（兼容 Rundeck Option Model Provider）
        if ($format === 'raw') {
            return $this->output($response, $branches, $request);
        }

        // json/xml: 完整格式
        return $this->output($response, [
            'build_provider' => $provider,
            'project_id'     => $projectId,
            'branches'       => $branches,
        ], $request);
    }

    /**
     * POST /api/build/{path}/commit-status
     *
     * 通用安全扫描回写端点 —— 类似 GitHub Actions Security Check。
     * CI pipeline 中任何安全扫描步骤（SAST、密钥扫描、依赖漏洞、IaC 审计等）
     * 都可以通过此端点将结果以 commit status 形式回写到 Git 平台。
     *
     * Body:
     *   sha          - commit SHA（必填）
     *   state        - success / failed / pending / error（必填）
     *   context      - 检查名称，如 "sast" / "secret-scan" / "sca" / "iac-audit"（必填）
     *   description  - 简短描述（必填）
     *   target_url   - 详情链接（可选，如 SonarQube 报告地址）
     *   check_type   - 检查类型标签（可选，用于数据库记录分类）
     *   tag          - 关联的镜像 tag（可选）
     *
     * 响应:
     *   { sha, state, context, description, commit_status: {success, message}, check_type }
     */
    public function commitStatus(Request $request, Response $response, array $args): Response
    {
        $path = $args['path'] ?? '';
        $body = $request->getParsedBody() ?? [];

        // ── 参数校验 ──
        $sha         = trim($body['sha'] ?? '');
        $state       = trim($body['state'] ?? '');
        $context     = trim($body['context'] ?? '');
        $description = trim($body['description'] ?? '');
        $targetUrl   = trim($body['target_url'] ?? '');
        $checkType   = trim($body['check_type'] ?? $context); // 默认等于 context
        $tag         = trim($body['tag'] ?? '');

        if (empty($sha) || empty($state) || empty($context) || empty($description)) {
            return $this->jsonError($response, $this->__('build.commit_status_missing_fields'), 400);
        }

        $validStates = ['pending', 'success', 'failed', 'error'];
        if (!in_array($state, $validStates, true)) {
            return $this->jsonError($response, $this->__('build.commit_status_invalid_state', ['states' => implode(', ', $validStates)]), 400);
        }

        // ── 查找 git_platform ──
        $maps = $this->config->getJobGitMap();
        $gitPlatform    = '';
        $gitProjectId   = null;
        $gitCurrentPath = '';
        foreach ($maps as $m) {
            $job = $m['job_name'] ?? '';
            $cp  = $m['current_path'] ?? '';
            if ($job === $path || $cp === $path) {
                $gitPlatform    = $m['git_platform'] ?? '';
                $gitProjectId   = $m['project_id'] ?? null;
                $gitCurrentPath = $cp;
                break;
            }
        }

        if (empty($gitPlatform)) {
            return $this->jsonError($response, $this->__('build.commit_status_no_git_platform', ['path' => $path]), 400);
        }

        if (!$this->gitRegistry || !$this->gitRegistry->isRegistered($gitPlatform)) {
            return $this->jsonError($response, $this->__('build.git_platform_not_available', ['platform' => $gitPlatform]), 400);
        }

        // ── 通过 Git Provider 回写 commit status ──
        try {
            $gitProvider = $this->gitRegistry->create($gitPlatform);
            // 三级回退：project_id（数字）→ current_path（真实路径）→ Jenkins 短名
            $gitRepo = $gitProjectId ? (string) $gitProjectId
                : (!empty($gitCurrentPath) ? $gitCurrentPath : $path);
            $result = $gitProvider->setCommitStatus($gitRepo, $sha, $state, $context, $description, $targetUrl);
        } catch (\Exception $e) {
            $result = ['success' => false, 'message' => $e->getMessage()];
        }

        // ── 记录到数据库（可选，用于审计）──
        if ($tag) {
            $this->recordPipelineTag($path, 0, $tag, '', $state);
        }

        // ── 同时记录到 ci_security_checks 表（审计追踪）──
        $this->recordSecurityCheck($path, $sha, $state, $context, $description, $checkType, $tag);

        return $this->output($response, [
            'project'       => $path,
            'sha'           => $sha,
            'state'         => $state,
            'context'       => $context,
            'description'   => $description,
            'check_type'    => $checkType,
            'git_platform'  => $gitPlatform,
            'commit_status' => $result,
        ], $request);
    }

    /**
     * 记录安全扫描结果到 ci_security_checks 表
     */
    private function recordSecurityCheck(string $project, string $sha, string $state, string $context, string $description, string $checkType, string $tag): void
    {
        try {
            $pdo = $this->pdo;
            $sql = \App\Service\Database::sqlUpsert(
                AppConfig::TABLE_SECURITY_CHECKS,
                'project, sha, check_type, state, context, description, tag, created_at',
                '?, ?, ?, ?, ?, ?, ?, ' . \App\Service\Database::sqlNow()
            );
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$project, $sha, $checkType, $state, $context, $description, $tag]);
        } catch (\Exception $e) {
            // 静默失败，不影响主流程
        }
    }

    /** POST /api/build/{path}/scan-sync（公共端点，不限制 BUILD_MODE） */
    public function scanSync(Request $request, Response $response, array $args): Response
    {
        $path = $args['path'] ?? '';
        $body   = $request->getParsedBody() ?? [];
        $tag    = $body['tag'] ?? null;

        // 1. 获取 job_git_map 中的映射信息（不依赖 CI 系统）
        $maps = $this->config->getJobGitMap();
        $harborRepo  = '';
        $provider    = AppConfig::PROVIDER_JENKINS;
        $gitPlatform = '';
        $gitProjectId  = null;
        $gitCurrentPath = '';
        foreach ($maps as $m) {
            $job = $m['job_name'] ?? '';
            $cp  = $m['current_path'] ?? '';
            if ($job === $path || $cp === $path) {
                $harborRepo     = $m['harbor_repository'] ?? '';
                $provider       = $m['build_provider'] ?? AppConfig::PROVIDER_JENKINS;
                $gitPlatform    = $m['git_platform'] ?? '';
                $gitProjectId   = $m['project_id'] ?? null;
                $gitCurrentPath = $cp;
                break;
            }
        }

        if (empty($harborRepo)) {
            return $this->jsonError($response, $this->__('build.no_harbor_repo', ['path' => $path]), 400);
        }

        // 2. 解析 Harbor 仓库信息
        $parts = explode('/', $harborRepo, 2);
        if (count($parts) !== 2) {
            return $this->jsonError($response, $this->__('build.harbor_repo_format_error', ['repo' => $harborRepo]), 400);
        }
        [$harborProject, $harborRepoName] = $parts;

        // 获取 Harbor 当前所有 tag（用于校验和兜底取最新）
        $harborTags = null;  // null=未请求, array=tag列表, ['error'=>...]=Harbor不可达
        if ($this->harbor) {
            $harborTags = $this->harbor->getTags($harborProject, $harborRepoName);
        }

        // tag 不传则取 Harbor 最新
        if (!$tag && is_array($harborTags) && !isset($harborTags['error']) && !empty($harborTags)) {
            $tag = $harborTags[0];
        }
        if (!$tag) {
            return $this->jsonError($response, $this->__('build.no_tag_available'), 400);
        }

        // 校验 tag 在 Harbor 中确实存在（防止写入已删除的 tag）
        if ($this->harbor && is_array($harborTags) && !isset($harborTags['error'])) {
            // Harbor 可达：严格校验 tag 是否存在
            if (!in_array($tag, $harborTags)) {
                return $this->jsonError($response, $this->__('build.tag_not_found', ['tag' => $tag, 'repo' => $harborRepo]), 400);
            }
        }
        // Harbor 不可达时降级放行：CI 刚 push 完 tag，大概率存在；读取侧 cleanupStaleTags 兜底清理

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

        // 4. Harbor 扫描 + commit status 回写（通过 Git Provider，跨所有平台）
        $vulnCount = 0;
        $state     = 'unknown';
        $result    = ['success' => false, 'message' => ''];

        // commit status 通过 Git 平台回写（不依赖 CI 系统）
        $canWriteBack = $sha && $gitPlatform && $this->gitRegistry
            && $this->gitRegistry->isRegistered($gitPlatform);

        if ($canWriteBack) {
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
                    // 通过 Git Provider 回写 commit status（所有平台统一接口）
                    $gitProvider = $this->gitRegistry->create($gitPlatform);
                    // 三级回退：project_id（数字）→ current_path（真实路径）→ Jenkins 短名
                    $gitRepo = $gitProjectId ? (string) $gitProjectId
                        : (!empty($gitCurrentPath) ? $gitCurrentPath : $path);
                    $result = $gitProvider->setCommitStatus($gitRepo, $sha, $state, 'harbor-scan', $desc, $harborUrl);
                } catch (\Exception $e) {
                    $state  = 'pending';
                    $result = ['success' => false, 'message' => $e->getMessage()];
                }
            }
        }

        // 5. 记录 pipeline → tag 映射（含扫描状态）
        if (!empty($iid)) {
            $this->recordPipelineTag($path, (int)$iid, $tag, $harborRepo, $state);
        }

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
        $status  = is_array($tagInfo) ? ($tagInfo['status'] ?? '') : '';

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
            'status'         => $status,
            'harbor_repository' => $harbor,
            'all'            => $pipeline ? null : $entry,
        ], $request);
    }

    // ── pipeline → tag 映射持久化（SQLite） ──

    private function loadPipelineTags(): array
    {
        try {
            $pdo = $this->pdo;
            $rows = $pdo->query("SELECT project, pipeline_iid, tag, harbor_repository, status, created_at FROM " . AppConfig::TABLE_PIPELINE_TAGS . " ORDER BY created_at DESC")->fetchAll();
            $result = [];
            foreach ($rows as $r) {
                $result[$r['project']][(string) $r['pipeline_iid']] = [
                    'tag'    => $r['tag'],
                    'harbor' => $r['harbor_repository'] ?? '',
                    'status' => $r['status'] ?? '',
                ];
            }
            // 清理 Harbor 中已不存在的 tag 记录
            $this->cleanupStaleTags($result);
            return $result;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * 按 harbor_repository 检查已有 tag 是否仍存在于 Harbor，删除已过期的记录
     * 如果 Harbor 不可达则跳过清理（避免误删有效数据）
     */
    private function cleanupStaleTags(array &$tagGroups): void
    {
        if (!$this->harbor) return;

        $repoCache = [];  // harbor_repo => [tag1, tag2, ...] 或 null（Harbor不可达）
        $staleKeys = [];  // [['project' => ..., 'pipeline_iid' => ...], ...]

        foreach ($tagGroups as $project => $entries) {
            foreach ($entries as $pipelineIid => $info) {
                $harborRepo = $info['harbor'] ?? '';
                $tag        = $info['tag'] ?? '';
                if (empty($harborRepo) || empty($tag)) continue;

                // 按仓库缓存 Harbor tag 列表，避免重复请求
                if (!array_key_exists($harborRepo, $repoCache)) {
                    $parts = explode('/', $harborRepo, 2);
                    if (count($parts) === 2) {
                        $tags = $this->harbor->getTags($parts[0], $parts[1]);
                        // Harbor 不可达时置 null，跳过该仓库的清理
                        $repoCache[$harborRepo] = isset($tags['error']) ? null : $tags;
                    } else {
                        $repoCache[$harborRepo] = [];
                    }
                }

                $validTags = $repoCache[$harborRepo];
                // null 表示 Harbor 不可达，跳过检查
                if ($validTags !== null && !in_array($tag, $validTags)) {
                    $staleKeys[] = ['project' => $project, 'pipeline_iid' => (int) $pipelineIid];
                }
            }
        }

        if (!empty($staleKeys)) {
            try {
                $pdo  = $this->pdo;
                $stmt = $pdo->prepare("DELETE FROM " . AppConfig::TABLE_PIPELINE_TAGS . " WHERE project = ? AND pipeline_iid = ?");
                foreach ($staleKeys as $key) {
                    $stmt->execute([$key['project'], $key['pipeline_iid']]);
                    unset($tagGroups[$key['project']][(string) $key['pipeline_iid']]);
                }
            } catch (\Exception $e) {}
        }
    }

    private function recordPipelineTag(string $path, int $pipelineIid, string $tag, string $harborRepo = '', string $status = ''): void
    {
        // 基础输入校验
        if (empty($path) || $pipelineIid <= 0 || empty($tag)) return;
        if (mb_strlen($tag) > 255 || mb_strlen($path) > 255) return;
        try {
            $pdo = $this->pdo;
            $sql   = \App\Service\Database::sqlUpsert(AppConfig::TABLE_PIPELINE_TAGS, 'project, pipeline_iid, tag, harbor_repository, status', '?, ?, ?, ?, ?');
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$path, $pipelineIid, $tag, $harborRepo, $status]);
        } catch (\Exception $e) {
            // 静默失败
        }
    }
}
