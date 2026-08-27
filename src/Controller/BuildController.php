<?php
namespace App\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Config\AppConfig;
use App\Service\Build\BuildProviderRegistry;
use App\Service\HarborService;
use App\Service\I18nService;
use App\Service\MappingManager;
use App\Service\PipelineTagService;
use App\Service\Git\ProviderRegistry as GitProviderRegistry;

class BuildController extends BaseController
{
    private BuildProviderRegistry $registry;
    private AppConfig $config;
    private MappingManager $mapping;
    private PipelineTagService $pipelineTags;
    private ?HarborService $harbor;
    private ?GitProviderRegistry $gitRegistry;
    private \PDO $pdo;

    public function __construct(I18nService $i18n, BuildProviderRegistry $registry, AppConfig $config, MappingManager $mapping, \PDO $pdo, PipelineTagService $pipelineTags, ?HarborService $harbor = null, ?GitProviderRegistry $gitRegistry = null)
    {
        parent::__construct($i18n);
        $this->registry     = $registry;
        $this->config       = $config;
        $this->mapping      = $mapping;
        $this->pdo          = $pdo;
        $this->pipelineTags = $pipelineTags;
        $this->harbor       = $harbor;
        $this->gitRegistry  = $gitRegistry;
    }

    private function resolve(string $projectPath): array
    {
        // resolveProject 已按 provider 归一化 projectId：
        //   jenkins → job 路径；gitlab_ci → 数字 project_id；
        //   custom_push → job_name（current_path 兜底），推 job_name/current_path 归一到同一条记录。
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

        // 自定义 Build Provider 状态（custom_push 等）
        $customProviders = [];
        foreach ($this->config->getCustomBuildProviders() as $p) {
            $name = $p['name'] ?? '';
            if ($name && $this->registry->isRegistered($name)) {
                $customProviders[] = $name;
            }
        }

        return $this->output($response, [
            'mode'                  => $mode,
            'source'                => $source,
            'has_jenkins'           => $hasJenkins,
            'has_gitlab_ci'         => $hasGitlab,
            'has_custom_push'       => !empty($customProviders),
            'custom_push_enabled'   => $this->config->getCustomPushEnabled(),
            'custom_providers'      => $customProviders,
        ], $request);
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
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_TRIGGER)) {
            return $resp;
        }

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
        if ($p instanceof \App\Service\Build\CustomPushBuildProvider) {
            return $this->jsonError($response, 'custom_push 不支持主动触发，请通过 POST /api/build/{path}/report 上报构建结果', 400);
        }
        $result = $p->trigger($projectId, $ref, $vars);
        return $this->output($response, [
            'build_provider' => $provider,
            'project_id'     => $projectId,
        ] + $result, $request);
    }

    /** POST /api/build/{path}/pipelines/{id}/retry */
    public function retry(Request $request, Response $response, array $args): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_TRIGGER)) {
            return $resp;
        }

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
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_TRIGGER)) {
            return $resp;
        }

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
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_TRIGGER)) {
            return $resp;
        }

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

        // SHA 必须为合法十六进制（7~64 位）。合法 SHA 本身是 URL 安全字符，无需编码，git 一定认；
        // 校验可同时挡住含 /、..、%2F 的非法输入注入 git 回写路径。
        if (!preg_match('/^[0-9a-fA-F]{7,64}$/', $sha)) {
            return $this->jsonError($response, 'build.commit_status_invalid_sha', 400);
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

        // ── 记录到 ci_security_checks 表（审计追踪，含回写结果）──
        $writebackStatus = ($result['success'] ?? false) ? 'success' : 'failed';
        $this->recordSecurityCheck($path, $sha, $state, $context, $description, $checkType, $tag, $writebackStatus, $result['message'] ?? '');

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
    private function recordSecurityCheck(string $project, string $sha, string $state, string $context, string $description, string $checkType, string $tag, string $writebackStatus = '', string $writebackMessage = ''): void
    {
        try {
            $pdo = $this->pdo;
            $sql = \App\Service\Database::sqlUpsert(
                AppConfig::TABLE_SECURITY_CHECKS,
                'project, sha, check_type, state, context, description, tag, writeback_status, writeback_message, created_at',
                '?, ?, ?, ?, ?, ?, ?, ?, ?, ' . \App\Service\Database::sqlNow()
            );
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$project, $sha, $checkType, $state, $context, $description, $tag, $writebackStatus, $writebackMessage]);
        } catch (\Exception $e) {
            // 静默失败，不影响主流程，但记录日志便于排查（例如列缺失导致 INSERT 失败）
            \App\Helper\Log::exception($e);
        }
    }

    /** POST /api/build/{path}/scan-sync（CI 流水线回写端点，不限制 BUILD_MODE） */
    public function scanSync(Request $request, Response $response, array $args): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_TRIGGER)) {
            return $resp;
        }

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
        } catch (\Exception $e) {
            \App\Helper\Log::exception($e);
        }

        // 4. Harbor 扫描 + commit status 回写（通过 Git Provider，跨所有平台）
        $vulnCount = 0;
        // scanState 只表达「Harbor 扫描结果」，与 commit status 回写是否成功解耦。
        // 二者不可再共用一个变量：回写失败（如 Gitee 公开版不支持 commit status API）
        // 不等于扫描失败，CI 按 scan_state 判断漏洞时不能把回写失败误读成「镜像有毒」。
        $scanState      = 'unknown';
        $writebackState = 'unknown';
        $result         = ['success' => false, 'message' => ''];

        // commit status 通过 Git 平台回写（不依赖 CI 系统）
        $canWriteBack = $sha && $gitPlatform && $this->gitRegistry
            && $this->gitRegistry->isRegistered($gitPlatform);

        if ($canWriteBack) {
            if (!$this->harbor) {
                $scanState      = 'pending';
                $writebackState = 'pending';
                $result         = ['success' => false, 'message' => 'Harbor 未配置'];
            } else {
                try {
                    $scan = $this->harbor->getScanReport($harborProject, $harborRepoName, $tag);
                    if (isset($scan['error']) || isset($scan['code'])) {
                        $scanState = 'pending';
                        $result    = ['success' => false, 'message' => $scan['message'] ?? '扫描功能未启用'];
                    } else {
                        // getScanReport 签名返回 array（永不为 null），?? [] 是不可达的死代码
                        $vulns = $scan['vulnerabilities'] ?? $scan;
                        $vulnCount = is_array($vulns) ? count($vulns) : 0;
                        $scanState = $vulnCount > 0 ? 'failed' : 'success';
                    }
                    // 回写状态默认跟随扫描结果；仅当回写自身失败时才降为 error，绝不反改 scanState
                    $writebackState = $scanState;
                    $desc  = "#{$iid} → {$tag} · " . ($vulnCount > 0 ? "{$vulnCount} vulns" : ($scanState === 'pending' ? '扫描未启用' : 'clean'));
                    $harborUrl = $this->config->getHarborConfig()['url'] ?? '';
                    // 通过 Git Provider 回写 commit status（所有平台统一接口）
                    $gitProvider = $this->gitRegistry->create($gitPlatform);
                    // 三级回退：project_id（数字）→ current_path（真实路径）→ Jenkins 短名
                    $gitRepo = $gitProjectId ? (string) $gitProjectId
                        : (!empty($gitCurrentPath) ? $gitCurrentPath : $path);
                    $result = $gitProvider->setCommitStatus($gitRepo, $sha, $writebackState, 'harbor-scan', $desc, $harborUrl);
                } catch (\Exception $e) {
                    // 扫描/回写过程抛异常：仅标记回写为 error，scanState 保持现状（unknown/pending/failed/success），
                    // 不能让回写失败污染扫描结果。原始异常进服务端日志，不外泄给调用方。
                    \App\Helper\Log::exception($e);
                    $writebackState = 'error';
                    $result = ['success' => false, 'message' => '回写失败：' . $e->getMessage()];
                }
            }
        }

        // 5. 记录 pipeline → tag 映射（含扫描状态）
        if (!empty($iid)) {
            $this->recordPipelineTag($path, (int)$iid, $tag, $harborRepo, $scanState);
        }

        // 6. 记录回写结果到 ci_security_checks（审计追踪）
        $writebackStatus = !$canWriteBack ? 'skipped' : (($result['success'] ?? false) ? 'success' : 'failed');
        $this->recordSecurityCheck($path, $sha, $scanState, 'harbor-scan', $desc ?? '', 'harbor-scan', $tag, $writebackStatus, $result['message'] ?? '');

        return $this->output($response, [
            'build_provider'       => $provider,
            'sha'                  => $sha ?: 'N/A',
            'tag'                  => $tag,
            'harbor_repository'    => $harborRepo,
            'vulnerability_count'  => $vulnCount,
            'scan_state'           => $scanState,
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

    /**
     * POST /api/build/{path}/report
     *
     * 自定义推送式 CI（custom_push）一次性上报构建终态结果。
     * Devops-Glue 不参与构建执行、不存日志内容（只存 log_url 指针）。
     *
     * Body:
     *   pipeline_iid       - 构建编号（必填，用户 CI 传入）
     *   status             - success/failed/aborted（必填，终态，无 pending/running）
     *   finished_at        - 构建完成时间（必填）
     *   started_at         - 开始时间（可选）
     *   ref                - 分支（可选）
     *   sha                - commit SHA（可选）
     *   exit_code          - 退出码（可选）
     *   log_url            - 日志 URL（可选，仅指针）
     *   web_url            - 用户 CI 构建页面（可选）
     *   tag                - 镜像 tag（可选；status=success 时写入 ci_pipeline_tags）
     *   harbor_repository  - Harbor 仓库（由 job_git_map 决定，body 不覆盖）
     *   其余字段           - 作为自定义构建参数写入 variables_json
     */
    public function report(Request $request, Response $response, array $args): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_TRIGGER)) {
            return $resp;
        }

        $path        = $args['path'] ?? '';
        $body        = $request->getParsedBody() ?? [];
        $pipelineIid = (int) ($body['pipeline_iid'] ?? 0);
        $status      = trim((string) ($body['status'] ?? ''));
        $finishedAt  = trim((string) ($body['finished_at'] ?? ''));
        $tag         = trim((string) ($body['tag'] ?? ''));

        if ($pipelineIid <= 0) {
            return $this->jsonError($response, '缺少 pipeline_iid 参数', 400);
        }
        if ($status === '') {
            return $this->jsonError($response, '缺少 status 参数（构建结果）', 400);
        }
        if (!in_array($status, ['success', 'failed', 'aborted'], true)) {
            return $this->jsonError($response, '无效的 status 值，允许: success / failed / aborted（custom_push 直接上报终态，无 pending/running）', 400);
        }
        if ($finishedAt === '') {
            return $this->jsonError($response, '缺少 finished_at 参数（构建完成时间必填）', 400);
        }
        if ($status === 'success' && $tag === '') {
            return $this->jsonError($response, 'status=success 时必须上报 tag（镜像标签，供部署层交付）', 400);
        }

        // 先确认构建源是 custom_push，再做专属校验（避免对 Jenkins/GitLabCI 做无谓的 Harbor 请求）
        [$provider, $projectId] = $this->resolve($path);
        if (!$this->registry->isRegistered($provider)) {
            return $this->jsonError($response, $this->__('build.provider_not_configured', ['{provider}' => $provider]), 400);
        }

        $p = $this->registry->create($provider);
        if (!$p instanceof \App\Service\Build\CustomPushBuildProvider) {
            return $this->jsonError($response, 'report 仅支持 custom_push 构建源', 400);
        }

        // success 时从 job_git_map 解析 harbor_repository（映射表是唯一来源，body 不覆盖）。
        // ci_pipeline_tags 是最终部署依据，harbor_repository 与 tag 都必须真实存在于 Harbor，否则拒绝。
        $harborRepo = '';
        if ($status === 'success') {
            foreach ($this->config->getJobGitMap() as $m) {
                $job = $m['job_name'] ?? '';
                $cp  = $m['current_path'] ?? '';
                if ($job === $path || $cp === $path) {
                    $harborRepo = trim((string) ($m['harbor_repository'] ?? ''));
                    break;
                }
            }
            if ($harborRepo === '') {
                return $this->jsonError($response, 'status=success 时 job_git_map 未配置 harbor_repository，无法落 ci_pipeline_tags', 400);
            }
            // 格式校验：harbor_repository 必须是 project/repo 两段式，拒绝带 registry 主机/URL 的写法
            if (!$this->isValidHarborRepo($harborRepo)) {
                return $this->jsonError($response, 'job_git_map 中 harbor_repository 必须是 project/repo 两段式（如 mycode/runner-ci），不要携带 registry 地址或 URL', 400);
            }
            // 仓库 + tag 存在性校验：手动上报不可信，写错/乱传直接拒绝
            if (($harborErr = $this->verifyHarborTag($harborRepo, $tag)) !== null) {
                return $this->jsonError($response, $harborErr, 400);
            }
        }

        $result = $p->report($projectId, $body);

        // status=success 且带 tag：同步写入 ci_pipeline_tags（部署系统以 ci_pipeline_tags 为交付依据）
        if (!empty($result['success']) && $status === 'success') {
            $this->recordPipelineTag($projectId, $pipelineIid, $tag, $harborRepo, 'success', $finishedAt);
        }

        return $this->output($response, [
            'build_provider' => $provider,
            'pipeline_iid'   => $pipelineIid,
        ] + $result, $request);
    }

    private function loadPipelineTags(): array
    {
        try {
            // 先以 Harbor 为准清理过期 tag（不可达/不可校验时安全跳过，不误删）；受后台开关控制
            if ($this->config->getStaleTagCleanupEnabled()) {
                $this->pipelineTags->cleanupStaleTags();
            }

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
            return $result;
        } catch (\Exception $e) {
            return [];
        }
    }

    private function isValidHarborRepo(string $repo): bool
    {
        // project/repo 两段式：两段均以字母数字开头，仅含 [A-Za-z0-9._-]，无 scheme/主机/端口
        return (bool) preg_match('#^[A-Za-z0-9][A-Za-z0-9._-]*/[A-Za-z0-9][A-Za-z0-9._-]*$#', $repo);
    }

    /**
     * 校验 Harbor 仓库与 tag 是否真实存在。
     *
     * 返回 null 表示仓库存在且 tag 在其中；否则返回错误消息（Harbor 未配置 / 不可达 /
     * 仓库不存在 / tag 不存在）。分两步：先 getRepositories 判仓库（Harbor v2 对不存在的
     * repo 返回空数组而非 404，故用 project 下仓库列表判断），再 getTags 判 tag。
     *
     * 仅用于 custom_push 手动上报（report）：手动传值不可信，可能写错/乱传，必须校验
     * 仓库与 tag 的真实性，保证 ci_pipeline_tags 落的是真实仓库 + 真实 tag。
     * Jenkins/GitLabCI 走 scan-sync，镜像 push 不进 Harbor 构建即失败，仓库与 tag 天然真实。
     */
    private function verifyHarborTag(string $repo, string $tag): ?string
    {
        if (!$this->harbor) {
            return 'Harbor 未配置，无法校验仓库与 tag 的真实性';
        }
        $parts = explode('/', $repo, 2);
        if (count($parts) !== 2) {
            return 'harbor_repository 格式错误（应为 project/repo 两段式）';
        }
        [$project, $repoName] = $parts;

        // 1. 仓库存在性：先列 project 下的仓库，确认 repo 存在（Harbor v2 对不存在的 repo 返回空数组，须用列表判断）
        try {
            $repos = $this->harbor->getRepositories($project);
        } catch (\Throwable $e) {
            return 'Harbor 不可达（' . $e->getMessage() . '），无法校验仓库与 tag';
        }
        if (!is_array($repos) || isset($repos['error'])) {
            if (!is_array($repos)) {
                return 'Harbor 返回数据异常（非预期的仓库列表格式），无法校验仓库与 tag';
            }
            return '查询 Harbor 项目仓库失败：' . $this->harborErrorMessage($repos) . '（项目 ' . $project . '）';
        }
        // v2 返回去前缀短名（runner-ci），v1 可能返回完整名（mycode/runner-ci），两者都兼容
        if (!in_array($repoName, $repos, true) && !in_array($repo, $repos, true)) {
            return 'Harbor 仓库不存在：' . $repo . '（请核对 job_git_map 的 harbor_repository）';
        }

        // 2. tag 存在性：仓库存在后，确认 tag 确在其中
        try {
            $tags = $this->harbor->getTags($project, $repoName);
        } catch (\Throwable $e) {
            return 'Harbor 不可达（' . $e->getMessage() . '），无法校验仓库与 tag';
        }
        if (!is_array($tags) || isset($tags['error'])) {
            if (!is_array($tags)) {
                return 'Harbor 返回数据异常（非预期的 tag 列表格式），无法校验仓库与 tag';
            }
            return '查询 Harbor 仓库 tag 失败：' . $this->harborErrorMessage($tags) . '（仓库 ' . $repo . '）';
        }
        if (!in_array($tag, $tags, true)) {
            return 'Harbor 仓库 ' . $repo . ' 中不存在 tag「' . $tag . '」：该 tag 的镜像尚未 push 到 Harbor，或 tag 名拼写有误';
        }
        return null;
    }

    /**
     * 把 HarborService 返回的错误数组翻译成对用户明确的错误描述。
     * request() 出错时返回 ['error' => string]；HTTP 4xx 附带 ['http_code' => int]，
     * 网络错误（重试耗尽）只有 error 无 http_code，据此区分「不可达 / 认证失败 / 资源不存在」。
     */
    private function harborErrorMessage(array $err): string
    {
        $httpCode = $err['http_code'] ?? 0;
        $msg      = $err['error'] ?? '未知错误';
        if ($httpCode === 0) {
            // HarborService v1 路径返回的业务错误（如「项目 xxx 不存在」）直接透传，并非网络错误
            if (str_contains($msg, '不存在')) {
                return $msg;
            }
            return 'Harbor 不可达（' . $msg . '）';
        }
        if ($httpCode === 404) {
            return '资源不存在（HTTP 404）';
        }
        if ($httpCode === 401 || $httpCode === 403) {
            return 'Harbor 认证失败（HTTP ' . $httpCode . '），请检查 .env 中 Harbor 账号的权限';
        }
        if ($httpCode >= 500) {
            return 'Harbor 服务端异常（HTTP ' . $httpCode . '）';
        }
        return 'Harbor 返回 HTTP ' . $httpCode . '（' . $msg . '）';
    }

    private function recordPipelineTag(string $path, int $pipelineIid, string $tag, string $harborRepo = '', string $status = '', ?string $createdAt = null): void
    {
        // 基础输入校验：ci_pipeline_tags 是最终部署依据，关键字段必须真实非空
        if (empty($path) || $pipelineIid <= 0 || empty($tag) || empty($harborRepo)) return;
        if (mb_strlen($tag) > 255 || mb_strlen($path) > 255) return;
        try {
            $pdo = $this->pdo;
            if ($createdAt !== null && $createdAt !== '') {
                // custom_push 回写：created_at = 构建完成时间（部署系统依赖此时间判断构建时机）
                $sql = \App\Service\Database::sqlUpsert(
                    AppConfig::TABLE_PIPELINE_TAGS,
                    'project, pipeline_iid, tag, harbor_repository, status, created_at',
                    '?, ?, ?, ?, ?, ?'
                );
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$path, $pipelineIid, $tag, $harborRepo, $status, $createdAt]);
            } else {
                // 其他调用方（scan-sync / commit-status）：不传时间，走 DB 默认 NOW()
                $sql = \App\Service\Database::sqlUpsert(AppConfig::TABLE_PIPELINE_TAGS, 'project, pipeline_iid, tag, harbor_repository, status', '?, ?, ?, ?, ?');
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$path, $pipelineIid, $tag, $harborRepo, $status]);
            }
        } catch (\Exception $e) {
            // 静默失败
        }
    }
}
