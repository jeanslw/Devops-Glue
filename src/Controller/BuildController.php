<?php

namespace App\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Config\AppConfig;
use App\Service\AppSettingRepository;
use App\Service\Build\BuildProviderRegistry;
use App\Service\I18nService;
use App\Service\MappingManager;
use App\Service\PipelineTagService;
use App\Service\PipelineArtifactService;
use App\Service\PipelineIdentity;
use App\Service\OperationLogRepository;
use App\Service\Registry\RegistryProviderInterface;
use App\Service\RegistrySyncService;
use App\Service\Settings;
use App\Service\Git\ProviderRegistry as GitProviderRegistry;
use App\Helper\ClientIp;

class BuildController extends BaseController
{
    private BuildProviderRegistry $registry;
    private Settings $config;
    private MappingManager $mapping;
    private AppSettingRepository $appSettings;
    private PipelineTagService $pipelineTags;
    private PipelineArtifactService $artifacts;
    private ?RegistryProviderInterface $registryProvider;
    private ?GitProviderRegistry $gitRegistry;
    private ?RegistrySyncService $registrySync;
    private \PDO $pdo;
    private ?OperationLogRepository $operationLog = null;

    public function __construct(I18nService $i18n, BuildProviderRegistry $registry, Settings $config, MappingManager $mapping, AppSettingRepository $appSettings, \PDO $pdo, PipelineTagService $pipelineTags, PipelineArtifactService $artifacts, ?RegistryProviderInterface $registryProvider = null, ?GitProviderRegistry $gitRegistry = null, ?RegistrySyncService $registrySync = null)
    {
        parent::__construct($i18n);
        $this->registry     = $registry;
        $this->config       = $config;
        $this->mapping      = $mapping;
        $this->appSettings  = $appSettings;
        $this->pdo          = $pdo;
        $this->pipelineTags = $pipelineTags;
        $this->artifacts    = $artifacts;
        $this->registryProvider = $registryProvider;
        $this->gitRegistry  = $gitRegistry;
        $this->registrySync = $registrySync;
    }

    private function opLog(): OperationLogRepository
    {
        if ($this->operationLog === null) {
            $this->operationLog = new OperationLogRepository($this->pdo);
        }
        return $this->operationLog;
    }

    /** 客户端 IP（与 AdminController 一致：TRUSTED_PROXY_HOPS 门控） */
    private function clientIp(Request $request): string
    {
        return ClientIp::resolve($request->getServerParams(), $this->config->getTrustedProxyHops());
    }

    /** 操作人类型：API token（服务账号）与后台登录用户区分 */
    private function operatorType(): string
    {
        return $this->currentRole === AppConfig::ROLE_API_TOKEN ? 'api_token' : 'admin';
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resolve(string $projectPath): array
    {
        $r = $this->mapping->resolveProject($projectPath);
        return [$r['provider'], $r['projectId']];
    }

    public function jobsList(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_BUILD_RECORDS_PULL)) {
            return $resp;
        }

        $all = [];
        foreach ($this->mapping->activeMaps() as $m) {
            $all[] = [
                'job_name'         => $m['job_name'] ?? '',
                'ci_provider'      => (string) ($m['build_provider'] ?? ''),
                'project_id'       => $m['project_id'] ?? ($m['current_path'] ?? $m['job_name']),
                'current_path'     => $m['current_path'] ?? '',
                'registry_repository' => $m['registry_repository'] ?? '',
                'git_platform'     => $m['git_platform'] ?? '',
            ];
        }
        if (($request->getQueryParams()['format'] ?? 'raw') === 'raw') {
            return $this->output($response, array_column($all, 'job_name'), $request);
        }
        return $this->output($response, $all, $request);
    }

    public function projectsList(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_BUILD_RECORDS_PULL)) {
            return $resp;
        }

        $maps = array_values(array_filter(
            $this->mapping->allMaps(),
            fn($m) => ($m['status'] ?? AppConfig::STATUS_ACTIVE) === AppConfig::STATUS_ACTIVE
        ));

        $latest = [];
        try {
            $rows = $this->pdo->query(
                'SELECT t.project_key AS project, t.pipeline_iid, t.tag, t.created_at'
                . ' FROM ' . AppConfig::TABLE_PIPELINE_ARTIFACTS . ' t'
                . ' INNER JOIN (SELECT project_key, MAX(created_at) AS max_created_at'
                . ' FROM ' . AppConfig::TABLE_PIPELINE_ARTIFACTS . ' GROUP BY project_key) m'
                . ' ON t.project_key = m.project_key AND t.created_at = m.max_created_at'
                . ' ORDER BY t.created_at DESC, t.pipeline_iid DESC'
            )->fetchAll();
            foreach ($rows as $r) {
                if (!isset($latest[$r['project']])) {
                    $latest[$r['project']] = [
                        'tag'          => $r['tag'] ?? '',
                        'pipeline_iid' => $r['pipeline_iid'] ?? '',
                        'created_at'   => $r['created_at'] ?? '',
                    ];
                }
            }
        } catch (\Exception $e) {
            $latest = [];
        }

        $result = [];
        foreach ($maps as $m) {
            $job = $m['job_name'] ?? '';
            $cp  = $m['current_path'] ?? '';
            $lt  = null;
            foreach (array_unique([$job, $cp]) as $k) {
                if ($k === '' || !isset($latest[$k])) {
                    continue;
                }
                if ($lt === null || $latest[$k]['created_at'] > $lt['created_at']) {
                    $lt = $latest[$k];
                }
            }
            $result[] = [
                'job_name'          => $job,
                'build_provider'    => (string) ($m['build_provider'] ?? ''),
                'current_path'      => $cp,
                'registry_repository' => $m['registry_repository'] ?? '',
                'git_platform'      => $m['git_platform'] ?? '',
                'status'            => $m['status'] ?? AppConfig::STATUS_ACTIVE,
                'latest_tag'        => $lt['tag'] ?? '',
                'latest_pipeline'   => $lt['pipeline_iid'] ?? '',
                'tag_time'          => $lt['created_at'] ?? '',
            ];
        }
        return $this->output($response, $result, $request);
    }

    /**
     * GET /api/build/{path}/tags — 项目 tag 列表（分页），keys = job_name + current_path
     *
     * @param array<string,string> $args
     */
    public function tagsList(Request $request, Response $response, array $args): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_BUILD_RECORDS_PULL)) {
            return $resp;
        }

        $path     = $args['path'] ?? '';
        $page     = max(1, (int) ($request->getQueryParams()['page'] ?? 1));
        $pageSize = (int) ($request->getQueryParams()['page_size'] ?? 50);
        if ($pageSize < 1 || $pageSize > 200) {
            $pageSize = 50;
        }

        $keys = [$path];
        foreach ($this->mapping->allMaps() as $m) {
            $job = $m['job_name'] ?? '';
            $cp  = $m['current_path'] ?? '';
            if ($job === $path || $cp === $path) {
                foreach (['job_name', 'current_path'] as $f) {
                    if (!empty($m[$f]) && $m[$f] !== $path) {
                        $keys[] = $m[$f];
                    }
                }
            }
        }
        $keys = array_values(array_unique($keys));

        try {
            $pdo = $this->pdo;
            $ph  = implode(',', array_fill(0, count($keys), '?'));

            $stmt = $pdo->prepare('SELECT COUNT(*) AS c FROM ' . AppConfig::TABLE_PIPELINE_ARTIFACTS . ' WHERE project_key IN (' . $ph . ')');
            $stmt->execute($keys);
            $total = (int) $stmt->fetch()['c'];

            $totalPages = $total ? max(1, (int) ceil($total / $pageSize)) : 1;
            if ($page > $totalPages) {
                $page = $totalPages;
            }
            $offset = ($page - 1) * $pageSize;

            $stmt = $pdo->prepare(
                'SELECT tag, pipeline_iid, created_at FROM ' . AppConfig::TABLE_PIPELINE_ARTIFACTS .
                ' WHERE project_key IN (' . $ph . ') ORDER BY created_at DESC LIMIT ? OFFSET ?'
            );
            foreach ($keys as $i => $k) {
                $stmt->bindValue($i + 1, $k, \PDO::PARAM_STR);
            }
            $stmt->bindValue(count($keys) + 1, $pageSize, \PDO::PARAM_INT);
            $stmt->bindValue(count($keys) + 2, $offset, \PDO::PARAM_INT);
            $stmt->execute();
            $items = array_map(fn($r) => [
                'tag'          => $r['tag'] ?? '',
                'pipeline_iid' => $r['pipeline_iid'] ?? '',
                'created_at'   => $r['created_at'] ?? '',
            ], $stmt->fetchAll());

            return $this->output($response, [
                'items'       => $items,
                'total'       => $total,
                'page'        => $page,
                'page_size'   => $pageSize,
                'total_pages' => $totalPages,
            ], $request);
        } catch (\Exception $e) {
            return $this->output($response, [
                'items'       => [],
                'total'       => 0,
                'page'        => $page,
                'page_size'   => $pageSize,
                'total_pages' => 1,
            ], $request);
        }
    }

    public function configMode(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_BUILD_RECORDS_PULL)) {
            return $resp;
        }

        $hasJenkins = $this->registry->isRegistered(AppConfig::PROVIDER_JENKINS);
        $hasGitlab  = $this->registry->isRegistered(AppConfig::PROVIDER_GITLAB_CI);
        $hasGitea   = $this->registry->isRegistered(AppConfig::PROVIDER_GITEA_CI);
        $hasGithub  = $this->registry->isRegistered(AppConfig::PROVIDER_GITHUB_ACTIONS);
        $modes  = $this->appSettings->getBuildModes();
        $source = $this->appSettings->getBuildModeSource();

        $customProviders = [];
        foreach ($this->config->getCustomBuildProviders() as $p) {
            $name = (string) ($p['name'] ?? '');
            if ($name !== '' && $this->registry->isRegistered($name)) {
                $customProviders[] = $name;
            }
        }

        return $this->output($response, [
            'mode'                  => $this->appSettings->getBuildMode(),
            'modes'                 => $modes,
            'source'                => $source,
            'has_jenkins'           => $hasJenkins,
            'has_gitlab_ci'         => $hasGitlab,
            'has_gitea_ci'          => $hasGitea,
            'has_github_actions'    => $hasGithub,
            'has_custom_push'       => !empty($customProviders),
            'custom_push_enabled'   => $this->appSettings->getCustomPushEnabled(),
            'custom_providers'      => $customProviders,
        ], $request);
    }

    /**
     * GET /api/build/{path}/pipelines — raw: 流水线数组, json/xml: 完整元数据
     *
     * @param array<string,string> $args
     */
    public function pipelines(Request $request, Response $response, array $args): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_BUILD_RECORDS_PULL)) {
            return $resp;
        }

        $path = $args['path'] ?? '';
        [$provider, $projectId] = $this->resolve($path);

        if (!$this->registry->isRegistered($provider)) {
            return $this->jsonError($response, $this->__('build.provider_not_configured_registered', [
                '{provider}' => $provider,
                '{registered}' => implode(', ', $this->registry->getRegisteredNames()),
            ]), 400);
        }

        $p = $this->registry->create($provider);
        $perPage = (int) ($request->getQueryParams()['per_page'] ?? 0);
        $data = $p->getPipelines($projectId, $perPage > 0 ? $perPage : 20);
        $data = $this->attachPullTags($path, $data);
        $format = $request->getQueryParams()['format'] ?? 'raw';

        $listFormat = $request->getQueryParams()['list'] ?? '';
        if (in_array($listFormat, ['id', 'build', 'time', 'success'], true)) {
            $filtered = $data;
            if (in_array($listFormat, ['success', 'build', 'time'], true)) {
                $filtered = array_values(array_filter($data, fn($p) => ($p['status'] ?? '') === 'success'));
            }
            $iidKey = 'iid';
            $idx = fn($p) => $p[$iidKey] ?? $p['id'] ?? 0;
            $result = match ($listFormat) {
                'id'      => array_map($idx, $filtered),
                'success' => array_map($idx, $filtered),
                'build'   => array_map(fn($p) => '#' . $idx($p), $filtered),
                'time'    => array_map(fn($p) => '#' . $idx($p) . ' [' . ($p['created_at'] ?? '') . ']', $filtered),
            };
            return $this->output($response, $result, $request);
        }

        if ($format === 'raw') {
            return $this->output($response, $data, $request);
        }

        return $this->output($response, [
            'build_provider' => $provider,
            'project_id'     => $projectId,
            'pipelines'      => $data,
        ], $request);
    }

    /**
     * GET /api/build/{path}/pipelines/{id}
     *
     * @param array<string,string> $args
     */
    public function pipelineDetail(Request $request, Response $response, array $args): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_BUILD_RECORDS_PULL)) {
            return $resp;
        }

        $path = $args['path'] ?? '';
        $pipelineId = (int) ($args['id'] ?? 0);
        [$provider, $projectId] = $this->resolve($path);

        if (!$this->registry->isRegistered($provider)) {
            return $this->jsonError($response, $this->__('build.provider_not_configured', ['{provider}' => $provider]), 400);
        }

        $p  = $this->registry->create($provider);
        $jobs = $p->getJobs($projectId, $pipelineId);

        foreach ($jobs as $i => $j) {
            $jobId = (int) ($j['id'] ?? 0);
            $jobs[$i]['log_url'] = $jobId > 0 ? "/api/build/{$path}/logs/{$jobId}" : null;
        }

        if (($request->getQueryParams()['format'] ?? '') === 'raw') {
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

    /**
     * GET /api/build/{path}/logs/{id} — 统一日志入口（Jenkins/GitLab CI）
     *
     * @param array<string,string> $args
     */
    public function logs(Request $request, Response $response, array $args): Response
    {
        return $this->jobTrace($request, $response, $args);
    }

    /**
     * GET /api/build/{path}/jobs/{id}/trace
     *
     * @param array<string,string> $args
     */
    public function jobTrace(Request $request, Response $response, array $args): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_BUILD_RECORDS_PULL)) {
            return $resp;
        }

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

    /**
     * GET /api/build/{path}/pipelines/{id}/logs — 输入 run id 直接返回该 run 下全部 job 日志（多 job 拼接）
     *
     * @param array<string,string> $args
     */
    public function pipelineLogs(Request $request, Response $response, array $args): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_BUILD_RECORDS_PULL)) {
            return $resp;
        }

        $path       = $args['path'] ?? '';
        $pipelineId = (int) ($args['id'] ?? 0);
        [$provider, $projectId] = $this->resolve($path);

        if (!$this->registry->isRegistered($provider)) {
            return $this->jsonError($response, $this->__('build.provider_not_configured', ['{provider}' => $provider]), 400);
        }

        $p    = $this->registry->create($provider);
        $jobs = $p->getJobs($projectId, $pipelineId);

        $chunks = [];
        foreach ($jobs as $j) {
            $jobId = (int) ($j['id'] ?? 0);
            if ($jobId <= 0) {
                continue;
            }
            $name     = $j['name'] ?? ('job ' . $jobId);
            $trace    = $p->getJobTrace($projectId, $jobId);
            $chunks[] = "===== Job #{$jobId} {$name} =====\n" . $trace;
        }

        $text = $chunks ? implode("\n\n", $chunks) : '日志不可用';
        return $this->output($response, $text, $request, true);
    }

    /**
     * POST /api/build/{path}/trigger（兼容 GET Query String 触发）
     *
     * @param array<string,string> $args
     */
    public function trigger(Request $request, Response $response, array $args): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_TRIGGER)) {
            return $resp;
        }

        $path = $args['path'] ?? '';
        $parsedBody = $request->getParsedBody();
        $body       = is_array($parsedBody) ? $parsedBody : [];
        $qs   = $request->getQueryParams();
        [$provider, $projectId] = $this->resolve($path);

        if (!$this->registry->isRegistered($provider)) {
            return $this->jsonError($response, $this->__('build.provider_not_configured', ['{provider}' => $provider]), 400);
        }

        $ref  = $body['ref'] ?? $qs['ref'] ?? '';
        $vars = $body['variables'] ?? [];
        foreach ($body as $k => $v) {
            if (!in_array($k, ['ref','variables','format','token']) && !isset($vars[$k])) {
                $vars[$k] = $v;
            }
        }
        foreach ($qs as $k => $v) {
            if (!in_array($k, ['format','token','ref']) && !isset($vars[$k])) {
                $vars[$k] = $v;
            }
        }

        $p      = $this->registry->create($provider);
        if ($p instanceof \App\Service\Build\CustomPushBuildProvider) {
            return $this->jsonError($response, 'custom_push 不支持主动触发，请通过 POST /api/build/{path}/report 上报构建结果', 400);
        }
        $result = $p->trigger($projectId, $ref, $vars);
        $this->opLog()->record($this->currentUser, 'build_trigger', $path, ['provider' => $provider, 'ref' => $ref], $this->clientIp($request), 'success', $this->operatorType());
        return $this->output($response, [
            'build_provider' => $provider,
            'project_id'     => $projectId,
        ] + $result, $request);
    }

    /**
     * POST /api/build/{path}/pipelines/{id}/retry
     *
     * @param array<string,string> $args
     */
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
        $this->opLog()->record($this->currentUser, 'build_retry', $path, ['provider' => $provider, 'pipeline_id' => $pipelineId], $this->clientIp($request), 'success', $this->operatorType());
        return $this->output($response, [
            'build_provider' => $provider,
            'project_id'     => $projectId,
        ] + $result, $request);
    }

    /**
     * POST /api/build/{path}/pipelines/{id}/cancel
     *
     * @param array<string,string> $args
     */
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
        $this->opLog()->record($this->currentUser, 'build_cancel', $path, ['provider' => $provider, 'pipeline_id' => $pipelineId], $this->clientIp($request), 'success', $this->operatorType());
        return $this->output($response, [
            'build_provider' => $provider,
            'project_id'     => $projectId,
        ] + $result, $request);
    }

    /**
     * GET /api/build/{path}/variables — raw: 参数名数组, json/xml: 完整元数据
     *
     * @param array<string,string> $args
     */
    public function variables(Request $request, Response $response, array $args): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_BUILD_RECORDS_PULL)) {
            return $resp;
        }

        $path = $args['path'] ?? '';
        [$provider, $projectId] = $this->resolve($path);

        if (!$this->registry->isRegistered($provider)) {
            return $this->jsonError($response, $this->__('build.provider_not_configured', ['{provider}' => $provider]), 400);
        }

        $p    = $this->registry->create($provider);
        $vars = $p->getVariables($projectId);
        $format = $request->getQueryParams()['format'] ?? 'raw';

        if ($format === 'raw') {
            $names = array_column($vars, 'key');
            return $this->output($response, $names, $request);
        }

        return $this->output($response, [
            'build_provider' => $provider,
            'project_id'     => $projectId,
            'variables'      => $vars,
        ], $request);
    }

    /**
     * GET /api/build/{path}/branches — raw: 分支名数组, json/xml: 完整元数据
     *
     * @param array<string,string> $args
     */
    public function branches(Request $request, Response $response, array $args): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_BUILD_RECORDS_PULL)) {
            return $resp;
        }

        $path = $args['path'] ?? '';
        [$provider, $projectId] = $this->resolve($path);

        if (!$this->registry->isRegistered($provider)) {
            return $this->jsonError($response, $this->__('build.provider_not_configured', ['{provider}' => $provider]), 400);
        }

        $p        = $this->registry->create($provider);
        $branches = $p->getBranches($projectId);
        $format   = $request->getQueryParams()['format'] ?? 'raw';

        if ($format === 'raw') {
            return $this->output($response, $branches, $request);
        }

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
     *
     * @param array<string,string> $args
     */
    public function commitStatus(Request $request, Response $response, array $args): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_TRIGGER)) {
            return $resp;
        }

        $path = $args['path'] ?? '';
        $parsedBody = $request->getParsedBody();
        $body       = is_array($parsedBody) ? $parsedBody : [];


        $sha         = trim($body['sha'] ?? '');
        $state       = trim($body['state'] ?? '');
        $context     = trim($body['context'] ?? '');
        $description = trim($body['description'] ?? '');
        $targetUrl   = trim($body['target_url'] ?? '');
        $checkType   = trim($body['check_type'] ?? $context);
        $tag         = trim($body['tag'] ?? '');

        if (empty($sha) || empty($state) || empty($context) || empty($description)) {
            return $this->jsonError($response, $this->__('build.commit_status_missing_fields'), 400);
        }

        $validStates = ['pending', 'success', 'failed', 'error'];
        if (!in_array($state, $validStates, true)) {
            return $this->jsonError($response, $this->__('build.commit_status_invalid_state', ['states' => implode(', ', $validStates)]), 400);
        }

        if (!preg_match('/^[0-9a-fA-F]{7,64}$/', $sha)) {
            return $this->jsonError($response, 'build.commit_status_invalid_sha', 400);
        }

        $row            = $this->mapping->resolveMapRow($path) ?? [];
        $gitPlatform    = (string) ($row['git_platform'] ?? '');
        $gitProjectId   = $row['project_id'] ?? null;
        $gitCurrentPath = (string) ($row['current_path'] ?? '');

        if (empty($gitPlatform)) {
            return $this->jsonError($response, $this->__('build.commit_status_no_git_platform', ['path' => $path]), 400);
        }

        if (!$this->gitRegistry || !$this->gitRegistry->isRegistered($gitPlatform)) {
            return $this->jsonError($response, $this->__('build.git_platform_not_available', ['platform' => $gitPlatform]), 400);
        }

        try {
            $gitProvider = $this->gitRegistry->create($gitPlatform);
            $gitRepo = $gitProjectId ? (string) $gitProjectId
                : (!empty($gitCurrentPath) ? $gitCurrentPath : $path);
            $result = $gitProvider->setCommitStatus($gitRepo, $sha, $state, $context, $description, $targetUrl);
        } catch (\Exception $e) {
            if ($e instanceof \RuntimeException) {
                throw $e;
            }
            $result = ['success' => false, 'message' => $e->getMessage()];
        }

        $writebackStatus = $result['success'] ? 'success' : 'failed';
        $this->recordSecurityCheck($path, $sha, $state, $context, $description, $checkType, $tag, $writebackStatus, $result['message']);

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
     * POST /api/build/{path}/scan-sync — Harbor 存量入口，等同 registry-sync 并附加扫描
     *
     * @param array<string,string> $args
     */
    public function scanSync(Request $request, Response $response, array $args): Response
    {
        return $this->runRegistrySync($request, $response, $args, true, 'harbor-scan');
    }

    /**
     * POST /api/build/{path}/registry-sync — 制品回写基础端点，不依赖扫描能力
     *
     * @param array<string,string> $args
     */
    public function registrySync(Request $request, Response $response, array $args): Response
    {
        return $this->runRegistrySync($request, $response, $args, false, 'registry-sync');
    }

    /**
     * @param array<string,string> $args
     */
    private function runRegistrySync(Request $request, Response $response, array $args, bool $withScan, string $checkType): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_TRIGGER)) {
            return $resp;
        }
        if ($this->registrySync === null) {
            return $this->jsonError($response, $this->__('build.registry_not_configured'), 503);
        }

        $parsedBody = $request->getParsedBody();
        $body       = is_array($parsedBody) ? $parsedBody : [];
        $result     = $this->registrySync->sync($args['path'] ?? '', $body, $withScan, $checkType);

        if (isset($result['error'])) {
            return $this->jsonError($response, $result['error'], (int) ($result['http_status'] ?? 400));
        }

        $sha = (string) ($result['sha'] ?? '');
        return $this->output($response, [
            'build_provider'       => (string) ($result['build_provider'] ?? ''),
            'sha'                  => $sha !== '' ? $sha : 'N/A',
            'tag'                  => (string) ($result['tag'] ?? ''),
            'registry_repository'  => (string) ($result['registry_repository'] ?? ''),
            'vulnerability_count'  => (int) ($result['vulnerability_count'] ?? 0),
            'scan_state'           => (string) ($result['scan_state'] ?? 'unknown'),
            'commit_status'        => $result['commit_status'] ?? ['success' => false, 'message' => ''],
        ], $request);
    }

    /**
     * GET /api/build/{path}/tag?pipeline=10 — 查 pipeline 对应的 tag
     *
     * @param array<string,string> $args
     */
    public function tagQuery(Request $request, Response $response, array $args): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_BUILD_RECORDS_PULL)) {
            return $resp;
        }

        $path     = $args['path'] ?? '';
        $pipeline = $request->getQueryParams()['pipeline'] ?? '';

        $tags  = $this->loadPipelineTags();
        $entry = $tags[$path] ?? [];

        foreach ($this->mapping->allMaps() as $m) {
            $job = $m['job_name'] ?? '';
            $cp  = $m['current_path'] ?? '';
            if ($job === $path || $cp === $path) {
                foreach (['job_name', 'current_path'] as $f) {
                    $alias = $m[$f] ?? '';
                    if ($alias !== '' && $alias !== $path) {
                        $entry = ($tags[$alias] ?? []) + $entry;
                    }
                }
            }
        }
        $tagInfo = $pipeline ? ($entry[$pipeline] ?? null) : null;
        $tag     = is_array($tagInfo) ? ($tagInfo['tag'] ?? '') : $tagInfo;
        $repo    = is_array($tagInfo) ? ($tagInfo['registry'] ?? '') : '';
        $status  = is_array($tagInfo) ? ($tagInfo['status'] ?? '') : '';

        if ($pipeline && !$tag) {
            if (($request->getQueryParams()['format'] ?? 'raw') === 'raw') {
                return $this->output($response, [], $request);
            }

            $resolved = $this->mapping->resolveProject($path);
            return $this->output($response, [
                'build_provider' => $resolved['provider'],
                'project_id'     => $resolved['projectId'],
                'pipeline'       => $pipeline,
                'tag'            => null,
                'all'            => null,
            ], $request);
        }

        if ($pipeline && ($request->getQueryParams()['format'] ?? 'raw') === 'raw') {
            return $this->output($response, $repo ? [$repo . ':' . $tag] : [$tag], $request);
        }

        [$provider, $projectId] = $this->resolve($path);
        return $this->output($response, [
            'build_provider' => $provider,
            'project_id'     => $projectId,
            'pipeline'       => $pipeline ?: null,
            'tag'            => $tag,
            'status'         => $status,
            'registry_repository' => $repo,
            'all'            => $pipeline ? null : $entry,
        ], $request);
    }

    /**
     * 拉取式记录镜像 Tag 列的数据源投影。
     *
     * 仅当 ci_security_checks 中存在回写审计记录（check_type in harbor-scan/registry-sync）且
     * writeback_status='success' 时，才认为该 commit 的镜像 tag 已推送成功，并把对应 tag 关联到 pipeline 行上。
     * 判断依据是回写成功（推送成功的审计事实），而非 ci_pipeline_artifacts（最终产物）：
     * 产物有记录并不代表推送成功，不能用产物倒推。
     *
     * @param array<int, array<string,mixed>> $pipelines
     *
     * @return array<int, array<string,mixed>>
     */
    private function attachPullTags(string $path, array $pipelines): array
    {
        if (empty($pipelines)) {
            return $pipelines;
        }
        try {
            $aliases = [$path];
            $registryRepo = '';
            foreach ($this->mapping->allMaps() as $m) {
                $job = $m['job_name'] ?? '';
                $cp  = $m['current_path'] ?? '';
                $matched = ($job === $path || $cp === $path);
                if ($matched && $registryRepo === '' && !empty($m['registry_repository'])) {
                    $registryRepo = (string) $m['registry_repository'];
                }
                if ($matched && ($job !== '' || $cp !== '')) {
                    foreach (['job_name', 'current_path'] as $f) {
                        $v = $m[$f] ?? '';
                        if ($v !== '' && $v !== $path && !in_array($v, $aliases, true)) {
                            $aliases[] = $v;
                        }
                    }
                }
            }

            $tagBySha = [];
            $tagSourceBySha = [];
            $ph = implode(',', array_fill(0, count($aliases), '?'));
            $stmt = $this->pdo->prepare(
                "SELECT sha, tag FROM " . AppConfig::TABLE_SECURITY_CHECKS
                . " WHERE check_type IN ('harbor-scan', 'registry-sync') AND writeback_status = 'success'"
                . " AND sha IS NOT NULL AND sha != '' AND project IN ({$ph})"
            );
            $stmt->execute($aliases);
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $sha = (string) ($row['sha'] ?? '');
                if ($sha !== '' && ($row['tag'] ?? '') !== '') {
                    $tagBySha[$sha] = $row['tag'];
                    $tagSourceBySha[$sha] = 'scan';
                }
            }

            $shas = array_values(array_unique(array_filter(
                array_map(fn($p) => (string) ($p['sha'] ?? ''), $pipelines),
                fn($s) => $s !== ''
            )));
            if ($shas) {
                $sph = implode(',', array_fill(0, count($shas), '?'));
                $cstmt = $this->pdo->prepare(
                    "SELECT sha, tag FROM " . AppConfig::TABLE_BUILD_TAG_CACHE
                    . " WHERE sha IN ({$sph}) AND tag != ''"
                );
                $cstmt->execute($shas);
                foreach ($cstmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                    $sha = (string) ($row['sha'] ?? '');
                    if ($sha !== '' && ($row['tag'] ?? '') !== '' && !isset($tagBySha[$sha])) {
                        $tagBySha[$sha] = $row['tag'];
                        $tagSourceBySha[$sha] = 'log';
                    }
                }
            }

            foreach ($pipelines as $i => $p) {
                $sha = (string) ($p['sha'] ?? '');
                $tag = $sha !== '' && isset($tagBySha[$sha]) ? $tagBySha[$sha] : '';
                $pipelines[$i]['tag'] = $tag;
                $pipelines[$i]['tag_source'] = $sha !== '' && isset($tagBySha[$sha]) ? ($tagSourceBySha[$sha] ?? '') : '';
                $pipelines[$i]['can_resolve_tag'] = $tag === ''
                    && (($p['status'] ?? '') === 'success')
                    && $registryRepo !== '';
            }
            return $pipelines;
        } catch (\Throwable $e) {
            \App\Helper\Log::exception($e);
            return $pipelines;
        }
    }

    /**
     * POST /api/build/{path}/pipelines/{id}/resolve-tag
     *
     * 镜像 Tag 的日志兜底懒解析：对一条 status=success 且未命中回写审计的 pipeline，
     * 遍历其 jobs 日志，按可配置关键字 + 本项目镜像仓库路径命中后尽力提取 tag，
     * 并写入 ci_build_tag_cache（sha 到 tag 缓存），下次列表直接命中，不再重复拉日志。
     * Body 可带 force=1 强制重解析：跳过缓存、覆盖旧 tag，用于修正历史解析错误。
     *
     * @param array<string,string> $args
     */
    public function resolveTag(Request $request, Response $response, array $args): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_BUILD_RECORDS_PULL)) {
            return $resp;
        }

        $path       = $args['path'] ?? '';
        $pipelineId = (int) ($args['id'] ?? 0);
        $parsedBody = $request->getParsedBody();
        $body       = is_array($parsedBody) ? $parsedBody : [];
        $sha        = trim((string) ($body['sha'] ?? ''));
        $force      = (bool) ($body['force'] ?? false);
        if ($pipelineId <= 0) {
            return $this->jsonError($response, $this->__('build.resolve_tag_missing_id'), 400);
        }

        [$provider, $projectId] = $this->resolve($path);
        if (!$this->registry->isRegistered($provider)) {
            return $this->jsonError($response, $this->__('build.provider_not_configured', ['{provider}' => $provider]), 400);
        }

        $registryRepo = '';
        foreach ($this->mapping->allMaps() as $m) {
            $job = $m['job_name'] ?? '';
            $cp  = $m['current_path'] ?? '';
            if ($job === $path || $cp === $path) {
                if (!empty($m['registry_repository'])) {
                    $registryRepo = trim((string) $m['registry_repository']);
                }
                break;
            }
        }
        if ($registryRepo === '') {
            return $this->output($response, ['tag' => '', 'resolved' => false, 'reason' => 'no_repository'], $request);
        }

        if (!$force && $sha !== '') {
            try {
                $c = $this->pdo->prepare("SELECT tag FROM " . AppConfig::TABLE_BUILD_TAG_CACHE . " WHERE sha = ?");
                $c->execute([$sha]);
                $cached = $c->fetchColumn();
                if ($cached !== false) {
                    return $this->output($response, ['tag' => (string) $cached, 'resolved' => true, 'cached' => true], $request);
                }
            } catch (\Throwable $e) {
                \App\Helper\Log::exception($e);
            }
        }

        $keyword = $this->appSettings->getTagLogKeyword();
        $tag = '';
        try {
            $p = $this->registry->create($provider);
            $jobs = $p->getJobs($projectId, $pipelineId);
            foreach ($jobs as $j) {
                $jobId = (int) ($j['id'] ?? 0);
                if ($jobId <= 0) {
                    continue;
                }
                $trace = (string) $p->getJobTrace($projectId, $jobId);
                $tag = $this->extractTagFromTrace($trace, $registryRepo, $keyword, $pipelineId);
                if ($tag !== '') {
                    break;
                }
            }
        } catch (\Throwable $e) {
            \App\Helper\Log::exception($e);
            $tag = '';
        }

        if ($sha !== '') {
            try {
                $sql = \App\Service\Database::sqlUpsert(
                    AppConfig::TABLE_BUILD_TAG_CACHE,
                    'project_key, provider, project_id, sha, pipeline_id, tag, repository, source, updated_at',
                    '?, ?, ?, ?, ?, ?, ?, ?, ' . \App\Service\Database::sqlNow()
                );
                $this->pdo->prepare($sql)->execute([$path, $provider, (string) $projectId, $sha, (string) $pipelineId, $tag, $registryRepo, 'log']);
            } catch (\Throwable $e) {
                \App\Helper\Log::exception($e);
            }
        }

        return $this->output($response, ['tag' => $tag, 'resolved' => true, 'cached' => false], $request);
    }

    /**
     * 从单条 job 日志中尽力提取镜像 tag。
     * 命中条件：日志包含推送成功关键字（可配置，默认 digest）且包含本项目镜像仓库路径。
     * 提取优先级：`repo:tag`，其次 `-t TAG` / `--tag TAG`；多条候选时优先尾号等于当前构建编号的。
     */
    private function extractTagFromTrace(string $trace, string $registryRepo, string $keyword, int $pipelineId = 0): string
    {
        $trace      = (string) $trace;
        $registryRepo = trim((string) $registryRepo);
        $keyword    = trim((string) $keyword);
        if ($trace === '' || $registryRepo === '') {
            return '';
        }

        if ($keyword !== '') {
            $keywords = array_values(array_filter(array_map('trim', explode('|', $keyword)), fn($k) => $k !== ''));
            if (!empty($keywords)) {
                $hit = false;
                foreach ($keywords as $kw) {
                    if (stripos($trace, $kw) !== false) {
                        $hit = true;
                        break;
                    }
                }
                if (!$hit) {
                    return '';
                }
            }
        }
        if (stripos($trace, $registryRepo) === false) {
            return '';
        }
        $candidates = [];
        $quoted = preg_quote($registryRepo, '/');
        if (preg_match_all('/' . $quoted . ':([^\s\'"\\\\]+)/i', $trace, $m)) {
            foreach ($m[1] as $raw) {
                $tag = $this->sanitizeTag($raw);
                if ($tag !== '') {
                    $candidates[] = $tag;
                }
            }
        }
        if (preg_match_all('/-(?:-tag\s+|-t\s+)([^\s]+)/i', $trace, $m)) {
            foreach ($m[1] as $raw) {
                $tag = $this->sanitizeTag($raw);
                if ($tag !== '') {
                    $candidates[] = $tag;
                }
            }
        }
        return $this->pickTag($candidates, $pipelineId);
    }

    /**
     * 从候选 tag 中选一个：优先尾号数字等于当前构建编号的（如 v20260827-130 对应 pipelineId=130），
     * 避免日志里更早出现的上一条构建 tag（v20260827-129）被误采；无命中则取第一条。
     *
     * @param list<string> $candidates
     */
    private function pickTag(array $candidates, int $pipelineId): string
    {
        if (empty($candidates)) {
            return '';
        }
        if ($pipelineId > 0) {
            foreach ($candidates as $tag) {
                if (preg_match('/(\d+)$/', $tag, $m) && (int) $m[1] === $pipelineId) {
                    return $tag;
                }
            }
        }
        return $candidates[0];
    }

    private function sanitizeTag(string $raw): string
    {
        $tag = trim($raw, " \t\n\r\0\x0B\"'");
        $tag = rtrim($tag, ".,;)'\"");
        if (($pos = strrpos($tag, ':')) !== false) {
            $tag = substr($tag, $pos + 1);
        }
        if (preg_match('/^[A-Za-z0-9_][A-Za-z0-9._-]{0,127}$/', $tag) !== 1) {
            return '';
        }
        return $tag;
    }

    // pipeline 到 tag 映射的持久化（SQLite）

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
     *   tag                - 镜像 tag（可选；status=success 时写入 ci_pipeline_artifacts）
     *   registry_repository  - 镜像仓库名（由 job_git_map 决定，body 不覆盖）
     *   其余字段           - 作为自定义构建参数写入 variables_json
     *
     * @param array<string,string> $args
     */
    public function report(Request $request, Response $response, array $args): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_TRIGGER)) {
            return $resp;
        }

        $path        = $args['path'] ?? '';
        $parsedBody  = $request->getParsedBody();
        $body        = is_array($parsedBody) ? $parsedBody : [];
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

        [$provider, $projectId] = $this->resolve($path);
        if (!$this->registry->isRegistered($provider)) {
            return $this->jsonError($response, $this->__('build.provider_not_configured', ['{provider}' => $provider]), 400);
        }

        $p = $this->registry->create($provider);
        if (!$p instanceof \App\Service\Build\CustomPushBuildProvider) {
            return $this->jsonError($response, 'report 仅支持 custom_push 构建源', 400);
        }

        $registryRepo = '';
        if ($status === 'success') {
            $row = $this->mapping->resolveMapRow($path);
            if ($row === null) {
                return $this->jsonError($response, 'status=success 时未找到 path 对应的映射（job_git_map），无法写入 pipeline artifact', 400);
            }
            $registryRepo = trim((string) ($row['registry_repository'] ?? ''));
            if ($registryRepo === '') {
                return $this->jsonError($response, 'status=success 时 job_git_map 未配置 registry_repository，无法写入 pipeline artifact', 400);
            }
            if (!$this->isValidRegistryRepo($registryRepo)) {
                return $this->jsonError($response, 'job_git_map 中 registry_repository 必须是至少两段的 OCI 仓库名（如 mycode/runner-ci、team/project/image），不要携带 registry 地址或 URL', 400);
            }
            if (($verifyErr = $this->verifyRegistryTag($registryRepo, $tag)) !== null) {
                return $this->jsonError($response, $verifyErr, 400);
            }
        }

        $txStarted = false;
        if ($status === 'success' && !$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
            $txStarted = true;
        }
        try {
            $result = $p->report($projectId, $body);

            if (!empty($result['success']) && $status === 'success' && ($result['action'] ?? '') !== 'ignored_stale') {
                if (
                    !$this->recordPipelineTag(
                        $projectId,
                        $this->mapping->pipelineIdentity($path, $pipelineIid),
                        $tag,
                        $registryRepo,
                        'success',
                        $finishedAt
                    )
                ) {
                    if ($txStarted) {
                        $this->pdo->rollBack();
                    }
                    return $this->jsonError($response, '构建记录已写入，但镜像产物 / Tag 写入数据库失败，请重试上报', 500);
                }
            }

            if ($txStarted) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($txStarted) {
                $this->pdo->rollBack();
            }
            \App\Helper\Log::exception($e);
            return $this->jsonError($response, '构建结果写入失败，请重试上报', 500);
        }

        return $this->output($response, [
            'build_provider' => $provider,
            'pipeline_iid'   => $pipelineIid,
        ] + $result, $request);
    }

    /**
     * @return array<string, array<string, array<string,mixed>>>
     */
    private function loadPipelineTags(): array
    {
        try {
            if ($this->appSettings->getStaleTagCleanupEnabled()) {
                $this->pipelineTags->cleanupStaleTags();
            }

            $pdo = $this->pdo;
            $rows = $pdo->query("SELECT project_key AS project, pipeline_iid, tag, repository AS registry_repository, status, created_at FROM " . AppConfig::TABLE_PIPELINE_ARTIFACTS . " ORDER BY created_at DESC")->fetchAll();
            $result = [];
            foreach ($rows as $r) {
                $result[$r['project']][(string) $r['pipeline_iid']] = [
                    'tag'      => $r['tag'],
                    'registry' => $r['registry_repository'] ?? '',
                    'status'   => $r['status'] ?? '',
                ];
            }
            return $result;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * 记录安全扫描/回写结果到 ci_security_checks 表
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
            \App\Helper\Log::exception($e);
        }
    }

    private function isValidRegistryRepo(string $repo): bool
    {
        return (bool) preg_match('#^[A-Za-z0-9][A-Za-z0-9._-]*(/[A-Za-z0-9][A-Za-z0-9._-]*)+$#', $repo);
    }

    /**
     * 校验镜像仓库与 tag 是否真实存在。
     *
     * 返回 null 表示仓库存在且 tag 在其中；否则返回错误消息（未配置 / 不可达 /
     * 仓库不存在 / tag 不存在）。分两步：先判仓库存在性，再判 tag。
     *
     * 仅用于 custom_push 手动上报（report）：手动传值不可信，必须校验真实存在，
     * 保证 artifact 记录的是真实仓库 + 真实 tag。
     * 拉取式 CI 走 registry-sync，镜像 push 失败则构建失败，仓库与 tag 天然真实。
     */
    private function verifyRegistryTag(string $repo, string $tag): ?string
    {
        if (!$this->registryProvider) {
            return '镜像仓库未配置，无法校验仓库与 tag 的真实性';
        }
        if (!$this->isValidRegistryRepo($repo)) {
            return 'registry_repository 格式错误（应为至少两段的 OCI 仓库名）';
        }

        try {
            if (!$this->registryProvider->repositoryExists($repo)) {
                return '镜像仓库不存在：' . $repo . '（请核对 job_git_map 的 registry_repository）';
            }
            $tags = $this->registryProvider->getTags($repo);
        } catch (\Throwable $e) {
            return '镜像仓库不可达（' . $e->getMessage() . '），无法校验仓库与 tag';
        }

        if (isset($tags['error'])) {
            return '查询镜像仓库 tag 失败：' . $this->registryErrorMessage($tags) . '（仓库 ' . $repo . '）';
        }
        if (!in_array($tag, $tags, true)) {
            return '镜像仓库 ' . $repo . ' 中不存在 tag「' . $tag . '」：该 tag 的镜像尚未推送，或 tag 名拼写有误';
        }
        return null;
    }

    /**
     * 把镜像仓库提供方返回的错误数组翻译成对用户明确的错误描述。
     * 出错时返回 ['error' => string]；HTTP 4xx 附带 ['http_code' => int]，
     * 网络错误（重试耗尽）只有 error 无 http_code，据此区分不可达、认证失败与资源不存在。
     *
     * @param array{http_code?: int, error?: string} $err
     */
    private function registryErrorMessage(array $err): string
    {
        $httpCode = $err['http_code'] ?? 0;
        $msg      = $err['error'] ?? '未知错误';
        if ($httpCode === 0) {
            if (str_contains($msg, '不存在')) {
                return $msg;
            }
            return '镜像仓库不可达（' . $msg . '）';
        }
        if ($httpCode === 404) {
            return '资源不存在（HTTP 404）';
        }
        if ($httpCode === 401 || $httpCode === 403) {
            return '镜像仓库认证失败（HTTP ' . $httpCode . '），请检查后台「平台连接配置」里镜像仓库账号的权限';
        }
        if ($httpCode >= 500) {
            return '镜像仓库服务端异常（HTTP ' . $httpCode . '）';
        }
        return '镜像仓库返回 HTTP ' . $httpCode . '（' . $msg . '）';
    }

    private function recordPipelineTag(
        string $projectKey,
        PipelineIdentity $identity,
        string $tag,
        string $registryRepo = '',
        string $status = '',
        ?string $sourceUpdatedAt = null,
        ?string $createdAt = null
    ): bool {
        if (empty($projectKey) || $identity->pipelineIid <= 0 || empty($tag) || empty($registryRepo)) {
            return false;
        }
        if (mb_strlen($tag) > 255 || mb_strlen($projectKey) > 255 || mb_strlen($identity->projectId) > 255) {
            return false;
        }

        try {
            $artifact = $this->artifacts->record(
                $identity,
                $projectKey,
                $tag,
                $registryRepo,
                $status,
                $sourceUpdatedAt,
                $createdAt
            );
            if (!$artifact['written'] && !$artifact['ignored']) {
                return false;
            }
            return true;
        } catch (\Throwable $e) {
            \App\Helper\Log::exception($e);
            return false;
        }
    }
}
