<?php
namespace App\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Config\AppConfig;
use App\Service\AdminAuthService;
use App\Service\AdminUserRepository;
use App\Service\ApiTokenService;
use App\Service\AutoDiscover;
use App\Service\HarborService;
use App\Service\I18nService;
use App\Service\TokenService;

class AdminController extends BaseController
{
    private AppConfig $config;
    private \PDO $pdo;
    private ?AutoDiscover $autoDiscover;
    private ?TokenService $tokenService = null;
    private ?ApiTokenService $apiTokenService = null;
    private AdminAuthService $adminAuthService;
    private AdminUserRepository $adminUserRepository;
    private ?HarborService $harbor;

    public function __construct(
        I18nService $i18n,
        AppConfig $config,
        \PDO $pdo,
        AdminAuthService $adminAuthService,
        AdminUserRepository $adminUserRepository,
        ?AutoDiscover $autoDiscover = null,
        ?TokenService $tokenService = null,
        ?ApiTokenService $apiTokenService = null,
        ?HarborService $harbor = null
    ) {
        parent::__construct($i18n);
        $this->config             = $config;
        $this->pdo                = $pdo;
        $this->autoDiscover       = $autoDiscover;
        $this->tokenService       = $tokenService;
        $this->apiTokenService    = $apiTokenService;
        $this->adminAuthService   = $adminAuthService;
        $this->adminUserRepository = $adminUserRepository;
        $this->harbor             = $harbor;
    }

    /** POST /api/admin/discover — 自动扫描并保存未入库的项目 */
    public function discover(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_DISCOVER)) {
            return $resp;
        }
        if (!$this->autoDiscover) {
            return $this->jsonError($response, 'map.discover_disabled', 503);
        }
        try {
            $raw = $this->autoDiscover->discover();
            // 分离错误信息
            $errors = [];
            $found = array_filter($raw, function ($i) use (&$errors) {
                if (($i['source'] ?? '') === '_errors') { $errors = $i['_errors'] ?? []; return false; }
                return true;
            });
            $found = array_values($found);
            $saved = $this->autoDiscover->saveDiscovered($found);
            if ($saved > 0) $this->invalidateTopologyCache();
            return $this->output($response, [
                'found' => count($found),
                'saved' => $saved,
                'errors' => $errors,
                'items' => array_map(fn($i) => $i['entry']['job_name'], $found),
            ], $request);
        } catch (\Exception $e) {
            return $this->jsonError($response, $this->__('build.scan_failed') . ': ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/admin/security_checks — 安全扫描结果列表（支持筛选/分页）
     */
    public function securityChecksList(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_MANAGE)) {
            return $resp;
        }

        $params = array_merge(
            $request->getQueryParams(),
            $request->getParsedBody() ?? json_decode($request->getBody()->__toString(), true) ?? []
        );
        $project   = trim($params['project'] ?? '');
        $checkType = trim($params['check_type'] ?? '');
        $state     = trim($params['state'] ?? '');
        $writeback = trim($params['writeback'] ?? '');
        $exclude   = trim($params['exclude'] ?? '');
        $page      = max(1, (int)($params['page'] ?? 1));
        $perPage   = max(1, min(100, (int)($params['per_page'] ?? 20)));

        try {
            $pdo = $this->pdo;

            $where = [];
            $bind  = [];
            if ($project !== '') {
                $where[] = 'project LIKE ?';
                $bind[]  = "%{$project}%";
            }
            if ($checkType !== '') {
                $where[] = 'check_type = ?';
                $bind[]  = $checkType;
            }
            if ($state !== '') {
                $where[] = 'state = ?';
                $bind[]  = $state;
            }
            if ($writeback !== '') {
                $where[] = 'writeback_status = ?';
                $bind[]  = $writeback;
            }
            if ($exclude !== '') {
                $excluded = array_filter(explode(',', $exclude), fn($s) => $s !== '');
                if (!empty($excluded)) {
                    $placeholders = implode(',', array_fill(0, count($excluded), '?'));
                    $where[] = "state NOT IN ({$placeholders})";
                    $bind = array_merge($bind, array_values($excluded));
                }
            }
            $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

            // 总数
            $countStmt = $pdo->prepare("SELECT count(*) FROM " . AppConfig::TABLE_SECURITY_CHECKS . " {$whereClause}");
            $countStmt->execute($bind);
            $total = (int)$countStmt->fetchColumn();

            $totalPages = max(1, (int)ceil($total / $perPage));
            $page = min($page, $totalPages);
            $offset = ($page - 1) * $perPage;

            // 分页查询
            $rows = $pdo->prepare(
                "SELECT * FROM " . AppConfig::TABLE_SECURITY_CHECKS . " {$whereClause} ORDER BY created_at DESC LIMIT {$perPage} OFFSET {$offset}"
            );
            $rows->execute($bind);
            $checks = $rows->fetchAll();

            // 可选筛选值
            $types = $pdo->query("SELECT DISTINCT check_type FROM " . AppConfig::TABLE_SECURITY_CHECKS . " ORDER BY check_type")->fetchAll(\PDO::FETCH_COLUMN);

            return $this->output($response, [
                'checks'      => $checks,
                'total'       => $total,
                'page'        => $page,
                'per_page'    => $perPage,
                'total_pages' => $totalPages,
                'filter_opts' => [
                    'check_types'       => $types,
                    'states'            => ['success', 'failed', 'pending', 'error'],
                    'writeback_statuses' => ['success', 'failed', 'skipped'],
                ],
            ], $request);
        } catch (\Exception $e) {
            return $this->jsonError($response, $this->__('build.query_failed') . ': ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/admin/login — 登录获取 token
     */
    public function login(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? json_decode($request->getBody()->__toString(), true) ?? [];
        // 请求体字段统一为 username（与用户管理接口一致）；保留 user 作为兼容别名
        // 用户名统一小写规范化，与建号/种子一致（SQLite 大小写敏感，防止跨驱动行为分裂）
        $user = strtolower(trim($body['username'] ?? $body['user'] ?? ''));
        $pass = $body['password'] ?? '';
        if ($user === '' || $pass === '') {
            return $this->jsonError($response, 'auth.wrong_credentials', 401);
        }

        $result = $this->adminAuthService->authenticate($user, $pass, $this->config->getSystemType());
        if (!$result['success']) {
            return $this->jsonError($response, $result['errorKey'], 401);
        }

        $loginRole = $result['role'];
        $isRoot = $result['isRoot'];

        if (\function_exists('random_bytes')) {
            $randomBytes = \random_bytes(32);
        } elseif (\function_exists('openssl_random_pseudo_bytes')) {
            $randomBytes = \openssl_random_pseudo_bytes(32);
        } else {
            return $this->jsonError($response, 'auth.token_generate_failed', 500);
        }

        if ($randomBytes === false) {
            return $this->jsonError($response, 'auth.token_generate_failed', 500);
        }
        $token = bin2hex($randomBytes);
        // 持久化 token，24h 过期
        try {
            $sql = \App\Service\Database::sqlUpsert(AppConfig::TABLE_CACHE, 'cache_key, value, expires_at', '?, ?, ?');
            $this->pdo->prepare($sql)->execute([AppConfig::CACHE_KEY_ADMIN_TOKEN_PREFIX . $token, $user . '|' . $loginRole, time() + AppConfig::TTL_TOKEN]);
        } catch (\Exception $e) {
            return $this->jsonError($response, 'auth.token_store_failed', 500);
        }
        $perms = $this->tokenService?->loadPermissions($loginRole) ?? [];

        return $this->output($response, [
            'token'       => $token,
            'role'        => $loginRole,
            'user'        => $user,
            'is_root'     => $isRoot,
            'permissions' => $perms,
        ], $request);
    }

    /**
     * POST /api/admin/logout — 退出登录，清理 token
     */
    public function logout(Request $request, Response $response): Response
    {
        $header = $request->getHeaderLine('Authorization');
        if (preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
            $this->tokenService?->revoke($m[1]);
        }
        return $this->output($response, ['message' => 'logged_out'], $request);
    }

    /** PUT /api/admin/password — 修改当前登录用户的密码 */
    public function changePassword(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_USERS_PASSWORD)) {
            return $resp;
        }

        $body = $request->getParsedBody() ?? json_decode($request->getBody()->__toString(), true) ?? [];
        $oldPass = $body['old_password'] ?? '';
        $newPass = $body['new_password'] ?? '';

        if (strlen($newPass) < 8) {
            return $this->jsonError($response, 'auth.new_password_short', 400);
        }

        try {
            // 修改当前登录用户的密码（而非 .env 中的 root 账号）
            $username = $this->currentUser;
            if ($username === '') {
                return $this->jsonError($response, 'auth.not_logged_in', 401);
            }

            if (!$this->adminAuthService->verifyCurrentPassword($username, $oldPass)) {
                return $this->jsonError($response, 'auth.old_password_wrong', 403);
            }

            $hash = password_hash($newPass, PASSWORD_BCRYPT);
            $repository = new \App\Service\AdminUserRepository($this->pdo);
            $repository->updatePassword($username, $hash);

            // 只踢当前用户的 token（而非所有用户）
            try {
                $header = $request->getHeaderLine('Authorization');
                if (preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
                    $this->tokenService?->revoke($m[1]);
                }
            } catch (\Exception $e) {
                \App\Helper\Log::exception($e);
            }

            return $this->output($response, ['success' => true, 'message' => $this->__('auth.password_updated')], $request);
        } catch (\Exception $e) {
            return $this->jsonError($response, $this->__('build.modify_failed') . ': ' . $e->getMessage(), 500);
        }
    }

    // ────────────────────────── CRUD ──────────────────────────

    /**
     * GET /api/admin/job_git_map — 列出所有映射（支持搜索/筛选/分页）
     */
    public function jobGitMapList(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_MAPPING_EDIT)) {
            return $resp;
        }

        $params = array_merge(
            $request->getQueryParams(),
            $request->getParsedBody() ?? json_decode($request->getBody()->__toString(), true) ?? []
        );
        $search  = trim($params['search'] ?? '');
        $platform = trim($params['platform'] ?? '');
        $provider = trim($params['provider'] ?? '');
        $page    = max(1, (int)($params['page'] ?? 1));
        $perPage = max(1, min(100, (int)($params['per_page'] ?? 20)));

        $allMaps = $this->config->getJobGitMap();
        $gitPlatforms = $this->config->getGitPlatformsConfig();
        $platformNames = array_map(fn($p) => $p['name'], $gitPlatforms);

        // 筛选
        $filtered = $allMaps;
        if ($search !== '') {
            $s = mb_strtolower($search);
            $filtered = array_filter($filtered, function ($m) use ($s) {
                foreach (['job_name','git_remote','current_path','harbor_repository'] as $f) {
                    if (mb_strpos(mb_strtolower($m[$f] ?? ''), $s) !== false) return true;
                }
                return false;
            });
        }
        if ($platform !== '') {
            $filtered = array_filter($filtered, fn($m) => ($m['git_platform'] ?? '') === $platform);
        }
        if ($provider !== '') {
            $filtered = array_filter($filtered, fn($m) => ($m['build_provider'] ?? AppConfig::PROVIDER_JENKINS) === $provider);
        }

        // 重置索引
        $filtered = array_values($filtered);
        $total = count($filtered);
        $totalPages = max(1, (int)ceil($total / $perPage));
        $page = min($page, $totalPages);

        // 分页切片
        $offset = ($page - 1) * $perPage;
        $pagedMaps = array_slice($filtered, $offset, $perPage);

        return $this->output($response, [
            'maps'        => $pagedMaps,
            'platforms'   => $platformNames,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => $totalPages,
        ], $request);
    }

    /**
     * POST /api/admin/job_git_map — 新增一条映射
     */
    public function jobGitMapSave(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_MAPPING_EDIT)) {
            return $resp;
        }

        $body = $request->getParsedBody() ?? json_decode($request->getBody()->__toString(), true) ?? [];

        $jobName = trim($body['job_name'] ?? '');
        if ($jobName === '') {
            return $this->jsonError($response, 'map.job_name_required', 400);
        }

        $maps = $this->config->getJobGitMap();

        foreach ($maps as $item) {
            if (($item['job_name'] ?? '') === $jobName) {
                return $this->jsonError($response, $this->__('map.already_exists', ['{name}' => $jobName]), 409);
            }
        }

        $entry = $this->buildEntry($body);
        $maps[] = $entry;
        $this->config->saveJobGitMap($maps);
        $this->invalidateTopologyCache();

        return $this->output($response, ['success' => true, 'entry' => $entry], $request);
    }

    /**
     * PUT /api/admin/job_git_map — 更新一条映射（按 job_name 匹配）
     */
    public function jobGitMapUpdate(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_MAPPING_EDIT)) {
            return $resp;
        }

        $body = $request->getParsedBody() ?? json_decode($request->getBody()->__toString(), true) ?? [];

        $oldName = trim($body['_original_job_name'] ?? '');
        if ($oldName === '') {
            return $this->jsonError($response, 'map.original_name_required', 400);
        }

        $maps = $this->config->getJobGitMap();
        $found = false;
        $updatedEntry = null;
        foreach ($maps as $i => $item) {
            if (($item['job_name'] ?? '') === $oldName) {
                $maps[$i] = $this->buildEntry($body);
                $updatedEntry = $maps[$i];
                $found = true;
                break;
            }
        }

        if (!$found) {
            return $this->jsonError($response, $this->__('map.not_found', ['{name}' => $oldName]), 404);
        }

        $this->config->saveJobGitMap($maps);
        $this->invalidateTopologyCache();
        return $this->output($response, ['success' => true, 'entry' => $updatedEntry], $request);
    }

    /**
     * DELETE /api/admin/job_git_map?job_name=xxx — 删除一条映射
     */
    public function jobGitMapDelete(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_MAPPING_EDIT)) {
            return $resp;
        }

        $jobName = trim($request->getQueryParams()['job_name'] ?? '');
        if ($jobName === '') {
            return $this->jsonError($response, 'map.job_name_required_param', 400);
        }

        $maps = $this->config->getJobGitMap();
        $found = false;
        foreach ($maps as $item) {
            if (($item['job_name'] ?? '') === $jobName) {
                $found = true;
                break;
            }
        }

        if (!$found) {
            return $this->jsonError($response, $this->__('map.not_found', ['{name}' => $jobName]), 404);
        }

        $this->config->deleteJobGitMap($jobName);
        $this->invalidateTopologyCache();
        return $this->output($response, ['success' => true], $request);
    }

    // ──────────────────────── 平台 API 版本 ────────────────────────

    /**
     * GET /api/admin/platform_versions — 获取所有平台 API 版本及配置状态
     */
    public function platformVersionsList(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_PLATFORM_EDIT)) {
            return $resp;
        }
        $versions = $this->config->getPlatformApiVersionsWithSource();

        // 附加配置状态
        $gitPlatforms = $this->config->getGitPlatformsConfig();
        $configuredGit = array_column($gitPlatforms, 'name');

        foreach ($versions as $name => &$info) {
            $info['configured'] = in_array($name, $configuredGit) || $name === 'harbor';
        }
        unset($info);

        // Harbor 附加探测信息：具体版本号 + 机器人账户支持情况（供管理界面明确提示）
        if (isset($versions['harbor'])) {
            $versions['harbor']['detected_version'] = $this->harbor?->getHarborVersion();
            $versions['harbor']['robot_support']    = $this->harbor?->getRobotAccountSupport() ?? 'unknown';
            $versions['harbor']['robot_account']    = $this->config->isHarborRobotAccount();
        }

        return $this->output($response, ['versions' => $versions], $request);
    }

    /**
     * PUT /api/admin/platform_versions — 更新平台 API 版本
     */
    public function platformVersionsUpdate(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_PLATFORM_EDIT)) {
            return $resp;
        }
        $body = $request->getParsedBody() ?? json_decode($request->getBody()->__toString(), true) ?? [];

        $versions = $body['versions'] ?? [];
        if (!is_array($versions) || empty($versions)) {
            return $this->jsonError($response, 'platform.versions_empty', 400);
        }

        $this->config->savePlatformApiVersions($versions);
        return $this->output($response, ['success' => true, 'versions' => $this->config->getPlatformApiVersions()], $request);
    }

    // ──────────────────────── 构建系统模式 ────────────────────────

    /**
     * GET /api/admin/build_mode — 获取构建模式及可用状态
     */
    public function getBuildMode(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_MODE_EDIT)) {
            return $resp;
        }
        $mode = $this->config->getBuildMode();

        // 检查实际可用性（由配置决定，不是模式）
        $jenkinsCfg = $this->config->getJenkinsConfig();
        $hasJenkins = !empty($jenkinsCfg['url']);
        $hasGitlab = $this->config->isPlatformConfigured('gitlab');
        $glCfg = $hasGitlab ? $this->config->getGitlabConfig() : [];
        $hasGitlabCi = $hasGitlab && !empty($glCfg['base_url']) && !empty($glCfg['token']);

        return $this->output($response, [
            'mode'          => $mode,
            'source'        => $this->config->getBuildModeSource(),
            'has_jenkins'   => $hasJenkins,
            'has_gitlab_ci' => $hasGitlabCi,
            'custom_push_enabled' => $this->config->getCustomPushEnabled(),
            'stale_tag_cleanup_enabled' => $this->config->getStaleTagCleanupEnabled(),
            'custom_providers' => array_column($this->config->getCustomBuildProviders(), 'name'),
        ], $request);
    }

    /**
     * PUT /api/admin/build_mode — 更新构建模式
     */
    public function updateBuildMode(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_MODE_EDIT)) {
            return $resp;
        }
        $body = $request->getParsedBody() ?? json_decode($request->getBody()->__toString(), true) ?? [];
        $mode = trim($body['mode'] ?? '');
        $cpEnabled = !empty($body['custom_push_enabled']);
        $staleCleanup = !empty($body['stale_tag_cleanup_enabled']);

        if (!in_array($mode, [AppConfig::BUILD_MODE_JENKINS, AppConfig::BUILD_MODE_GITLAB_CI, AppConfig::BUILD_MODE_BOTH])) {
            return $this->jsonError($response, 'build.mode_required', 400);
        }

        // 拒绝不可用的 Provider（仅校验单 provider 模式；both 始终允许，配合 custom_push 可无拉取式 CI）
        $jenkinsCfg = $this->config->getJenkinsConfig();
        $hasJenkins = !empty($jenkinsCfg['url']);
        $hasGitlab = $this->config->isPlatformConfigured('gitlab');
        $glCfg = $hasGitlab ? $this->config->getGitlabConfig() : [];
        $hasGitlabCi = $hasGitlab && !empty($glCfg['base_url']) && !empty($glCfg['token']);

        if ($mode === AppConfig::BUILD_MODE_JENKINS && !$hasJenkins) {
            return $this->jsonError($response, 'build.jenkins_unavail', 400);
        }
        if ($mode === AppConfig::BUILD_MODE_GITLAB_CI && !$hasGitlabCi) {
            return $this->jsonError($response, 'build.gitlab_ci_unavail', 400);
        }

        // custom_push 开启时要求至少配了一个 custom_providers
        if ($cpEnabled && empty($this->config->getCustomBuildProviders())) {
            return $this->jsonError($response, 'build.custom_push_no_provider', 400);
        }

        try {
            $this->config->setBuildMode($mode);
            $this->config->setCustomPushEnabled($cpEnabled);
            $this->config->setStaleTagCleanupEnabled($staleCleanup);

            // 切到单 provider 模式时，将其他 provider 的 active 记录降为 pending
            if ($mode === AppConfig::BUILD_MODE_JENKINS || $mode === AppConfig::BUILD_MODE_GITLAB_CI) {
                $otherProviders = match ($mode) {
                    AppConfig::BUILD_MODE_JENKINS      => [AppConfig::PROVIDER_GITLAB_CI],
                    AppConfig::BUILD_MODE_GITLAB_CI   => [AppConfig::PROVIDER_JENKINS],
                    default                            => [],
                };
                $maps = $this->config->getJobGitMap();
                $changed = false;
                foreach ($maps as &$m) {
                    $bp = $m['build_provider'] ?? AppConfig::PROVIDER_JENKINS;
                    if (in_array($bp, $otherProviders, true) && ($m['status'] ?? AppConfig::STATUS_ACTIVE) !== AppConfig::STATUS_PENDING) {
                        $m['status'] = AppConfig::STATUS_PENDING;
                        $changed = true;
                    }
                }
                if ($changed) {
                    $this->config->saveJobGitMap($maps);
                    $this->invalidateTopologyCache();
                }
            }

            // custom_push 关闭时，将 custom_push 的 active 记录降为 pending
            if (!$cpEnabled) {
                $maps = $this->config->getJobGitMap();
                $changed = false;
                foreach ($maps as &$m) {
                    if (($m['build_provider'] ?? '') === AppConfig::PROVIDER_CUSTOM_PUSH
                        && ($m['status'] ?? AppConfig::STATUS_ACTIVE) !== AppConfig::STATUS_PENDING) {
                        $m['status'] = AppConfig::STATUS_PENDING;
                        $changed = true;
                    }
                }
                if ($changed) {
                    $this->config->saveJobGitMap($maps);
                    $this->invalidateTopologyCache();
                }
            }

            return $this->output($response, ['success' => true, 'mode' => $mode, 'custom_push_enabled' => $cpEnabled, 'stale_tag_cleanup_enabled' => $staleCleanup], $request);
        } catch (\Exception $e) {
            return $this->jsonError($response, $this->__('build.save_failed') . ': ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/admin/custom_builds — Custom_Push 构建记录列表（「push 记录」Tab）
     */
    public function customBuildList(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_MODE_EDIT)) {
            return $resp;
        }

        $params  = $request->getQueryParams();
        $page    = max(1, (int)($params['page'] ?? 1));
        $perPage = max(1, min(100, (int)($params['per_page'] ?? 20)));

        try {
            $pdo = $this->pdo;

            // 总数按构建记录计（不 JOIN tag，避免一对多重复计数）
            $total = (int) $pdo->query("SELECT count(*) FROM " . AppConfig::TABLE_CUSTOM_BUILDS)->fetchColumn();

            $totalPages = max(1, (int) ceil($total / $perPage));
            $page = min($page, $totalPages);
            $offset = ($page - 1) * $perPage;

            $stmt = $pdo->prepare(
                'SELECT b.job_name, b.pipeline_iid, b.status, b.sha, b.variables_json, b.log_url, b.web_url, b.finished_at,
                        t.tag
                 FROM ' . AppConfig::TABLE_CUSTOM_BUILDS . ' b
                 LEFT JOIN ' . AppConfig::TABLE_PIPELINE_TAGS . ' t
                   ON t.project = b.job_name AND t.pipeline_iid = b.pipeline_iid
                 ORDER BY b.finished_at DESC, b.pipeline_iid DESC
                 LIMIT ' . $perPage . ' OFFSET ' . $offset
            );
            $stmt->execute();
            $records = array_map(function (array $r): array {
                return [
                    'job_name'       => $r['job_name'] ?? '',
                    'pipeline_iid'   => (int) ($r['pipeline_iid'] ?? 0),
                    'status'         => $r['status'] ?? '',
                    'tag'            => $r['tag'] ?? '',
                    'sha'            => $r['sha'] ?? '',
                    'variables_json' => $r['variables_json'] ?? '',
                    'log_url'        => $r['log_url'] ?? '',
                    'web_url'        => $r['web_url'] ?? '',
                    'finished_at'    => $r['finished_at'] ?? '',
                ];
            }, $stmt->fetchAll());

            return $this->output($response, [
                'records'     => $records,
                'total'       => $total,
                'page'        => $page,
                'per_page'    => $perPage,
                'total_pages' => $totalPages,
            ], $request);
        } catch (\Exception $e) {
            return $this->jsonError($response, $this->__('push.load_failed') . ': ' . $e->getMessage(), 500);
        }
    }

    // ────────────────────────── 用户管理 ──────────────────────────

    /**
     * GET /api/admin/users — 列出所有用户
     * - admin 可以看到全部用户（含 admin）
     * - 非 admin 看不到 admin 用户
     */
    public function userList(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_USERS_LIST)) {
            return $resp;
        }

        try {
            $rows = $this->adminUserRepository->listUsers($this->isAdminRole());
            $rootAdmin = $this->config->getRootAdminUser();
            foreach ($rows as &$row) {
                $row['is_root'] = ($row['username'] === $rootAdmin);
            }
            return $this->output($response, $rows, $request);
        } catch (\Exception $e) {
            return $this->jsonError($response, $this->__('build.query_failed') . ': ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/admin/users — 新增用户
     * - admin 可创建任意角色、系统（含 cd 即可）
     * - 非 admin 不能创建 admin，且 systems 不能含 ci
     */
    public function userCreate(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_USERS_MANAGE_ADMIN)) {
            return $resp;
        }

        $body = $request->getParsedBody() ?? json_decode($request->getBody()->__toString(), true) ?? [];
        // 统一小写存储，与登录规范化一致（SQLite 大小写敏感）
        $username = strtolower(trim($body['username'] ?? ''));
        $password = $body['password'] ?? '';
        $role     = trim($body['role'] ?? AppConfig::ROLE_DEPLOYER);
        $systems  = trim($body['systems'] ?? AppConfig::SYSTEM_CD);
        $email    = trim($body['email'] ?? '');
        if ($email !== '' && !\filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            return $this->jsonError($response, 'user.invalid_email', 400);
        }

        if ($username === '' || strlen($password) < 8) {
            return $this->jsonError($response, 'auth.new_password_short', 400);
        }

        // 创建 super_admin 必须由当前已是 super_admin 的操作者执行（防普通 admin 提权）
        if ($role === AppConfig::ROLE_SUPER_ADMIN && $this->currentRole !== AppConfig::ROLE_SUPER_ADMIN) {
            return $this->jsonError($response, 'user.cannot_create_super_admin', 403);
        }

        // 只有拥有 ci.users.manage_admin 权限的用户能创建管理员角色
        if ($role === AppConfig::ROLE_ADMIN || $role === AppConfig::ROLE_SUPER_ADMIN) {
            if (!$this->hasPermission(AppConfig::PERM_CI_USERS_MANAGE_ADMIN)) {
                return $this->jsonError($response, 'user.cannot_create_admin', 403);
            }
            // 管理员账号 system 至少含 cd
            if (!self::systemsContain($systems, AppConfig::SYSTEM_CD)) {
                $systems = $systems === '' ? AppConfig::SYSTEM_CD : $systems . ',' . AppConfig::SYSTEM_CD;
            }
        }

        // 非管理员创建的账号不能包含 ci
        if (!$this->isAdminRole() && self::systemsContain($systems, AppConfig::SYSTEM_CI)) {
            return $this->jsonError($response, 'user.cd_no_ci_access', 403);
        }

        try {
            if ($this->adminUserRepository->userExists($username)) {
                return $this->jsonError($response, 'user.username_exists', 409);
            }

            $hash = password_hash($password, PASSWORD_BCRYPT);
            $this->adminUserRepository->createUser($username, $hash, $role, $systems, $email);

            return $this->output($response, ['success' => true, 'username' => $username], $request);
        } catch (\Exception $e) {
            return $this->jsonError($response, $this->__('build.save_failed') . ': ' . $e->getMessage(), 500);
        }
    }

    /**
     * PUT /api/admin/users/{username} — 更新用户（改密码 / 改角色）
     */
    public function userUpdate(Request $request, Response $response, array $args): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_USERS_MANAGE_ADMIN)) {
            return $resp;
        }

        $targetUser = $args['username'] ?? '';
        if ($targetUser === '') {
            return $this->jsonError($response, 'user.invalid_username', 400);
        }

        $body = $request->getParsedBody() ?? json_decode($request->getBody()->__toString(), true) ?? [];
        $password = $body['password'] ?? null;
        $role     = isset($body['role']) ? trim($body['role']) : null;
        $email    = array_key_exists('email', $body) ? trim((string)$body['email']) : null;
        if ($email !== null && $email !== '' && !\filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            return $this->jsonError($response, 'user.invalid_email', 400);
        }

        try {
            $target = $this->adminUserRepository->findUser($targetUser);
            if (!$target) {
                return $this->jsonError($response, 'user.not_found', 404);
            }

            // 内置根账号（.env ADMIN_USER）：仅允许本人修改自己的 email 资料；
            // 角色不可改（防自降权锁死），密码走 PUT /api/admin/password（需旧密码）
            $rootAdmin = $this->config->getRootAdminUser();
            if ($targetUser === $rootAdmin) {
                if ($targetUser !== $this->currentUser || $role !== null || ($password !== null && $password !== '')) {
                    return $this->jsonError($response, 'user.cannot_edit_root', 403);
                }
            }

            // 修改管理员账号需要 ci.users.manage_admin 权限
            if ($this->isTargetAdmin($target['role']) && !$this->hasPermission(AppConfig::PERM_CI_USERS_MANAGE_ADMIN)) {
                return $this->jsonError($response, 'user.cannot_edit_admin', 403);
            }

            if ($role !== null) {
                // 提升为 super_admin 必须由 super_admin 操作（防普通 admin 提权）
                if ($role === AppConfig::ROLE_SUPER_ADMIN && $this->currentRole !== AppConfig::ROLE_SUPER_ADMIN) {
                    return $this->jsonError($response, 'user.cannot_promote_super_admin', 403);
                }
                // 提升为管理员需要 ci.users.manage_admin 权限
                if (($role === AppConfig::ROLE_ADMIN || $role === AppConfig::ROLE_SUPER_ADMIN) && !$this->hasPermission(AppConfig::PERM_CI_USERS_MANAGE_ADMIN)) {
                    return $this->jsonError($response, 'user.cannot_promote_admin', 403);
                }
            }

            $passwordHash = null;
            if ($password !== null && $password !== '') {
                if (strlen($password) < 8) {
                    return $this->jsonError($response, 'auth.new_password_short', 400);
                }
                $passwordHash = password_hash($password, PASSWORD_BCRYPT);
            }

            if ($role === null && $passwordHash === null && $email === null) {
                return $this->jsonError($response, 'user.nothing_to_update', 400);
            }

            $this->adminUserRepository->updateUser($targetUser, $passwordHash, $role, $email);
            return $this->output($response, ['success' => true], $request);
        } catch (\Exception $e) {
            return $this->jsonError($response, $this->__('build.modify_failed') . ': ' . $e->getMessage(), 500);
        }
    }

    /**
     * PUT /api/admin/users/{username}/password — 超级管理员修改任意用户密码
     *
     * 常规账号管理：登录中的 super_admin 可修改任意用户（deployer/admin/viewer）的密码。
     * 根账号（ADMIN_USER）除外——改自己的密码走 PUT /api/admin/password（需旧密码），
     * 忘记根密码走离线补丁
     */
    public function userModifyPassword(Request $request, Response $response, array $args): Response
    {
        $this->initAuthFromRequest($request);
        // 硬门槛：仅 super_admin
        if ($this->currentRole !== AppConfig::ROLE_SUPER_ADMIN) {
            return $this->jsonError($response, 'user.modify_password_forbidden', 403);
        }

        $targetUser = $args['username'] ?? '';
        if ($targetUser === '') {
            return $this->jsonError($response, 'user.invalid_username', 400);
        }

        $body = $request->getParsedBody() ?? json_decode($request->getBody()->__toString(), true) ?? [];
        $newPass = $body['new_password'] ?? '';

        if (strlen($newPass) < 8) {
            return $this->jsonError($response, 'auth.new_password_short', 400);
        }

        try {
            $target = $this->adminUserRepository->findUser($targetUser);
            if (!$target) {
                return $this->jsonError($response, 'user.not_found', 404);
            }

            // 根账号密码走 changePassword（需旧密码）或离线 CLI
            if ($targetUser === $this->config->getRootAdminUser()) {
                return $this->jsonError($response, 'user.cannot_edit_root', 403);
            }

            $this->adminUserRepository->updatePassword($targetUser, password_hash($newPass, PASSWORD_BCRYPT));

            return $this->output($response, ['success' => true, 'username' => $targetUser], $request);
        } catch (\Exception $e) {
            return $this->jsonError($response, $this->__('build.modify_failed') . ': ' . $e->getMessage(), 500);
        }
    }

    /**
     * PUT /api/admin/users/{username}/status — 启用/停用用户
     * Body: { "enabled": true|false }
     * 保护：root 账号不可停用；不能停用自己。
     */
    public function userSetStatus(Request $request, Response $response, array $args): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_USERS_MANAGE_ADMIN)) {
            return $resp;
        }

        $targetUser = $args['username'] ?? '';
        if ($targetUser === '') {
            return $this->jsonError($response, 'user.invalid_username', 400);
        }

        $body = $request->getParsedBody() ?? json_decode($request->getBody()->__toString(), true) ?? [];
        // enabled=true/false（Bool 语义清晰）；兼容直接传 status=0/1
        $enabled = array_key_exists('enabled', $body)
            ? \filter_var($body['enabled'], \FILTER_VALIDATE_BOOLEAN)
            : !empty($body['status']);

        try {
            $target = $this->adminUserRepository->findUser($targetUser);
            if (!$target) {
                return $this->jsonError($response, 'user.not_found', 404);
            }

            $rootAdmin = $this->config->getRootAdminUser();
            if (!$enabled && $targetUser === $rootAdmin) {
                return $this->jsonError($response, 'user.cannot_disable_root', 403);
            }
            if (!$enabled && $targetUser === $this->currentUser) {
                return $this->jsonError($response, 'user.cannot_disable_self', 403);
            }
            // 停用管理员需相应权限
            if (!$enabled && $this->isTargetAdmin($target['role']) && !$this->hasPermission(AppConfig::PERM_CI_USERS_MANAGE_ADMIN)) {
                return $this->jsonError($response, 'user.cannot_edit_admin', 403);
            }

            $this->adminUserRepository->setStatus($targetUser, $enabled ? 1 : 0);
            return $this->output($response, ['success' => true, 'enabled' => $enabled], $request);
        } catch (\Exception $e) {
            return $this->jsonError($response, $this->__('build.modify_failed') . ': ' . $e->getMessage(), 500);
        }
    }

    /**
     * DELETE /api/admin/users/{username} — 删除用户
     */
    public function userDelete(Request $request, Response $response, array $args): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_USERS_MANAGE_ADMIN)) {
            return $resp;
        }

        $targetUser = $args['username'] ?? '';
        if ($targetUser === '') {
            return $this->jsonError($response, 'user.invalid_username', 400);
        }

        try {
            $target = $this->adminUserRepository->findUser($targetUser);
            if (!$target) {
                return $this->jsonError($response, 'user.not_found', 404);
            }

            // 内置根账号（.env ADMIN_USER）任何人都不能删除
            $rootAdmin = $this->config->getRootAdminUser();
            if ($targetUser === $rootAdmin) {
                return $this->jsonError($response, 'user.cannot_delete_root', 403);
            }
            // 不能删除自己
            if ($targetUser === $this->currentUser) {
                return $this->jsonError($response, 'user.cannot_delete_self', 403);
            }
            // 删除管理员用户需要 ci.users.manage_admin 权限
            if ($this->isTargetAdmin($target['role']) && !$this->hasPermission(AppConfig::PERM_CI_USERS_MANAGE_ADMIN)) {
                return $this->jsonError($response, 'user.cannot_delete_admin', 403);
            }

            $this->adminUserRepository->deleteUser($targetUser);
            return $this->output($response, ['success' => true], $request);
        } catch (\Exception $e) {
            return $this->jsonError($response, $this->__('build.modify_failed') . ': ' . $e->getMessage(), 500);
        }
    }

    // ────────────────────────── 角色权限管理 ──────────────────────────

    /** GET /api/admin/roles — 列出所有角色及权限 */
    public function roleList(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_USERS_MANAGE_ADMIN)) {
            return $resp;
        }
        try {
            $pdo = $this->pdo;
            $roles = $pdo->query("SELECT id, name, description, is_system, created_at FROM " . AppConfig::TABLE_ROLES . " ORDER BY id")->fetchAll();
            $permStmt = $pdo->prepare("SELECT perm_key FROM " . AppConfig::TABLE_ROLE_PERMISSIONS . " WHERE role_id = ?");
            foreach ($roles as &$r) {
                $permStmt->execute([$r['id']]);
                $r['permissions'] = $permStmt->fetchAll(\PDO::FETCH_COLUMN);
                $r['is_system'] = (bool)$r['is_system'];
            }
            return $this->output($response, $roles, $request);
        } catch (\Exception $e) {
            return $this->jsonError($response, $this->__('build.query_failed') . ': ' . $e->getMessage(), 500);
        }
    }

    /** POST /api/admin/roles — 新建角色 */
    public function roleCreate(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if (!$this->hasPermission(AppConfig::PERM_CI_USERS_MANAGE_ADMIN)) {
            return $this->jsonError($response, 'user.cannot_create_admin', 403);
        }
        $body = $request->getParsedBody() ?? json_decode($request->getBody()->__toString(), true) ?? [];
        $name = trim($body['name'] ?? '');
        $desc = trim($body['description'] ?? '');
        $perms = $body['permissions'] ?? [];
        if ($name === '') {
            return $this->jsonError($response, 'user.invalid_username', 400);
        }
        try {
            $pdo = $this->pdo;
            $exists = $pdo->prepare("SELECT 1 FROM " . AppConfig::TABLE_ROLES . " WHERE name = ?");
            $exists->execute([$name]);
            if ($exists->fetch()) {
                return $this->jsonError($response, 'user.username_exists', 409);
            }
            $pdo->prepare("INSERT INTO " . AppConfig::TABLE_ROLES . " (name, description, is_system, created_at) VALUES (?, ?, 0, " . \App\Service\Database::sqlNow() . ")")->execute([$name, $desc]);
            $roleId = $pdo->lastInsertId();
            // 展开隐含权限 + 只分配已定义的权限键（数据驱动，从 DB 读）
            $expanded = $this->expandPermissions($perms);
            $validKeys = $this->allPermissionKeys();
            $permInsert = $pdo->prepare("INSERT INTO " . AppConfig::TABLE_ROLE_PERMISSIONS . " (role_id, perm_key) VALUES (?, ?)");
            foreach ($expanded as $pk) {
                if (in_array($pk, $validKeys, true)) {
                    try { $permInsert->execute([$roleId, $pk]); } catch (\Exception $e) { \App\Helper\Log::exception($e); }
                }
            }
            return $this->output($response, ['success' => true, 'id' => (int)$roleId, 'name' => $name], $request);
        } catch (\Exception $e) {
            return $this->jsonError($response, $this->__('build.save_failed') . ': ' . $e->getMessage(), 500);
        }
    }

    /** PUT /api/admin/roles/{id} — 更新角色（权限列表全覆盖） */
    public function roleUpdate(Request $request, Response $response, array $args): Response
    {
        $this->initAuthFromRequest($request);
        if (!$this->hasPermission(AppConfig::PERM_CI_USERS_MANAGE_ADMIN)) {
            return $this->jsonError($response, 'user.cannot_create_admin', 403);
        }
        $roleId = (int)($args['id'] ?? 0);

        $parsedBody = $request->getParsedBody();
        // 兜底：如果中间件没解析（某些环境下 PUT 的 body 可能不被解析），手动读 raw body
        if (!is_array($parsedBody) && !is_object($parsedBody)) {
            $rawBody = (string) $request->getBody();
            $parsedBody = json_decode($rawBody, true);
        }
        $body  = is_array($parsedBody) ? $parsedBody : [];
        $name  = trim($body['name'] ?? '');
        $desc  = trim($body['description'] ?? '');
        // 前端始终发送 permissions（至少为空数组），null 表示 key 不存在（理论上不会发生）
        $perms = array_key_exists('permissions', $body) ? $body['permissions'] : null;

        try {
            $pdo = $this->pdo;
            $role = $pdo->prepare("SELECT id, is_system, name FROM " . AppConfig::TABLE_ROLES . " WHERE id = ?");
            $role->execute([$roleId]);
            $r = $role->fetch();
            if (!$r) {
                return $this->jsonError($response, 'user.not_found', 404);
            }
            if ($r['is_system']) {
                return $this->jsonError($response, 'user.cannot_create_admin', 403);
            }

            // 动态构建 UPDATE：只更新实际传入的字段
            $fields = [];
            $params = [];
            if ($name !== '' && $name !== $r['name']) {
                // 检查名称冲突
                $dup = $pdo->prepare("SELECT id FROM " . AppConfig::TABLE_ROLES . " WHERE name = ? AND id != ?");
                $dup->execute([$name, $roleId]);
                if ($dup->fetch()) {
                    return $this->jsonError($response, 'user.username_exists', 409);
                }
                // 同步更新 admin_users 表中引用该角色的用户
                $this->adminUserRepository->updateRoleName($r['name'], $name);
                $fields[] = 'name = ?';
                $params[] = $name;
            }
            if ($desc !== '' && $desc !== ($r['description'] ?? '')) {
                $fields[] = 'description = ?';
                $params[] = $desc;
            }
            if (!empty($fields)) {
                $params[] = $roleId;
                $pdo->prepare("UPDATE " . AppConfig::TABLE_ROLES . " SET " . implode(', ', $fields) . " WHERE id = ?")->execute($params);
            }

            // 权限更新：只要有 permissions key 就全量替换（含空数组 = 清空所有权限）
            if (is_array($perms)) {
                $pdo->prepare("DELETE FROM " . AppConfig::TABLE_ROLE_PERMISSIONS . " WHERE role_id = ?")->execute([$roleId]);
                // 展开隐含权限
                $expanded = $this->expandPermissions($perms);
                $validKeys = $this->allPermissionKeys();
                $permInsert = $pdo->prepare("INSERT INTO " . AppConfig::TABLE_ROLE_PERMISSIONS . " (role_id, perm_key) VALUES (?, ?)");
                foreach ($expanded as $pk) {
                    if (in_array($pk, $validKeys, true)) {
                        try { $permInsert->execute([$roleId, $pk]); } catch (\Exception $e) { \App\Helper\Log::exception($e); }
                    }
                }
            }

            return $this->output($response, ['success' => true], $request);
        } catch (\Exception $e) {
            return $this->jsonError($response, $this->__('build.modify_failed') . ': ' . $e->getMessage(), 500);
        }
    }

    /** DELETE /api/admin/roles/{id} — 删除角色（仅自定义角色） */
    public function roleDelete(Request $request, Response $response, array $args): Response
    {
        $this->initAuthFromRequest($request);
        if (!$this->hasPermission(AppConfig::PERM_CI_USERS_MANAGE_ADMIN)) {
            return $this->jsonError($response, 'user.cannot_create_admin', 403);
        }
        $roleId = (int)($args['id'] ?? 0);
        try {
            $pdo = $this->pdo;
            $role = $pdo->prepare("SELECT id, is_system, name FROM " . AppConfig::TABLE_ROLES . " WHERE id = ?");
            $role->execute([$roleId]);
            $r = $role->fetch();
            if (!$r) {
                return $this->jsonError($response, 'user.not_found', 404);
            }
            if ($r['is_system']) {
                return $this->jsonError($response, 'user.cannot_create_admin', 403);
            }
            // 检查是否有用户绑定此角色
            if ($this->adminUserRepository->countByRole($r['name']) > 0) {
                return $this->jsonError($response, 'role.in_use', 400);
            }
            $pdo->prepare("DELETE FROM " . AppConfig::TABLE_ROLE_PERMISSIONS . " WHERE role_id = ?")->execute([$roleId]);
            $pdo->prepare("DELETE FROM " . AppConfig::TABLE_ROLES . " WHERE id = ?")->execute([$roleId]);
            return $this->output($response, ['success' => true], $request);
        } catch (\Exception $e) {
            return $this->jsonError($response, $this->__('build.modify_failed') . ': ' . $e->getMessage(), 500);
        }
    }

    /** GET /api/admin/permissions — 列出所有可用权限（含 parent_key 层级 + implied 隐含规则），对应权限列表子菜单 */
    public function permissionList(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if (!$this->hasPermission(AppConfig::PERM_CI_PERMISSIONS_LIST)) {
            return $this->jsonError($response, 'auth.forbidden', 403);
        }
        try {
            $pdo = $this->pdo;
            $rows = $pdo->query("SELECT perm_key, description, parent_key, created_at FROM " . AppConfig::TABLE_PERMISSIONS . " ORDER BY perm_key")->fetchAll();
            // 标注内置/注册（与删除保护逻辑同一判定源 DEFAULT_PERMISSIONS）
            foreach ($rows as &$row) {
                $row['is_builtin'] = array_key_exists($row['perm_key'], AppConfig::DEFAULT_PERMISSIONS);
            }
            unset($row);
            // 附带 implied 关系（数据驱动，前端可直接用）
            $implied = [];
            try {
                $ruleRows = $pdo->query("SELECT source_key, target_key FROM " . AppConfig::TABLE_IMPLIED_RULES)->fetchAll();
                foreach ($ruleRows as $r) {
                    $implied[$r['source_key']][] = $r['target_key'];
                }
            } catch (\Exception $e) {
                \App\Helper\Log::exception($e);
            }
            return $this->output($response, [
                'permissions'      => $rows,
                'implied'          => $implied,
                'builtin_implied'  => AppConfig::IMPLIED_PERMISSIONS,
            ], $request);
        } catch (\Exception $e) {
            return $this->jsonError($response, $this->__('build.query_failed') . ': ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/admin/permissions — 注册新权限（数据驱动，CD 项目通过此 API 注册自己的权限 key）
     * Body: { perm_key, description, parent_key? }
     * 权限：super_admin 限定（避免普通 admin 误注册）
     */
    public function permissionRegister(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if (!$this->hasPermission(AppConfig::PERM_CI_PERMISSIONS_REGISTER)) {
            return $this->jsonError($response, 'auth.forbidden', 403);
        }
        $body = json_decode((string)$request->getBody(), true) ?: [];
        $permKey = trim($body['perm_key'] ?? '');
        $desc    = trim($body['description'] ?? '');
        $parent  = trim($body['parent_key'] ?? '') ?: null;
        if ($permKey === '' || $desc === '') {
            return $this->jsonError($response, 'validation.required', 400);
        }
        if (!preg_match('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_-]*)+$/i', $permKey)) {
            return $this->jsonError($response, 'validation.invalid_perm_key', 400);
        }
        // 系统内置权限 key 不可被注册覆盖（与 permissionDelete 的保护对称）
        if (array_key_exists($permKey, AppConfig::DEFAULT_PERMISSIONS)) {
            return $this->jsonError($response, 'permission.builtin_immutable', 400);
        }
        try {
            $pdo = $this->pdo;
            $exists = $pdo->prepare("SELECT COUNT(*) FROM " . AppConfig::TABLE_PERMISSIONS . " WHERE perm_key = ?");
            $exists->execute([$permKey]);
            if ((int)$exists->fetchColumn() > 0) {
                // 已存在：仅更新描述/父级，保留首次注册时间
                $pdo->prepare("UPDATE " . AppConfig::TABLE_PERMISSIONS . " SET description = ?, parent_key = ? WHERE perm_key = ?")->execute([$desc, $parent, $permKey]);
            } else {
                // 新注册：写入注册时间
                $pdo->prepare("INSERT INTO " . AppConfig::TABLE_PERMISSIONS . " (perm_key, description, parent_key, created_at) VALUES (?, ?, ?, " . \App\Service\Database::sqlNow() . ")")->execute([$permKey, $desc, $parent]);
            }
            return $this->output($response, ['perm_key' => $permKey, 'description' => $desc, 'parent_key' => $parent], $request);
        } catch (\Exception $e) {
            return $this->jsonError($response, $this->__('build.save_failed') . ': ' . $e->getMessage(), 500);
        }
    }

    /**
     * DELETE /api/admin/permissions/{perm_key} — 删除权限（super_admin 限定）
     * 系统内置 key（DEFAULT_PERMISSIONS 中的）不可删，避免误删核心权限
     */
    public function permissionDelete(Request $request, Response $response, array $args): Response
    {
        $this->initAuthFromRequest($request);
        if (!$this->hasPermission(AppConfig::PERM_CI_PERMISSIONS_REGISTER)) {
            return $this->jsonError($response, 'auth.forbidden', 403);
        }
        $permKey = $args['perm_key'] ?? '';
        if ($permKey === '') {
            return $this->jsonError($response, 'validation.required', 400);
        }
        // 防误删系统内置权限
        if (array_key_exists($permKey, AppConfig::DEFAULT_PERMISSIONS)) {
            return $this->jsonError($response, 'permission.builtin_protected', 400);
        }
        try {
            $pdo = $this->pdo;
            $pdo->prepare("DELETE FROM " . AppConfig::TABLE_PERMISSIONS . " WHERE perm_key = ?")->execute([$permKey]);
            $pdo->prepare("DELETE FROM " . AppConfig::TABLE_ROLE_PERMISSIONS . " WHERE perm_key = ?")->execute([$permKey]);
            $pdo->prepare("DELETE FROM " . AppConfig::TABLE_IMPLIED_RULES . " WHERE source_key = ? OR target_key = ?")->execute([$permKey, $permKey]);
            return $this->output($response, ['deleted' => $permKey], $request);
        } catch (\Exception $e) {
            return $this->jsonError($response, $this->__('build.save_failed') . ': ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/admin/implied_rules — 注册隐含规则（CD 项目可通过此 API 添加新规则）
     * Body: { source_key, target_key }
     * 权限：super_admin 限定
     */
    public function impliedRuleCreate(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if (!$this->hasPermission(AppConfig::PERM_CI_PERMISSIONS_RULES)) {
            return $this->jsonError($response, 'auth.forbidden', 403);
        }
        $body = json_decode((string)$request->getBody(), true) ?: [];
        $src = trim($body['source_key'] ?? '');
        $tgt = trim($body['target_key'] ?? '');
        if ($src === '' || $tgt === '') {
            return $this->jsonError($response, 'validation.required', 400);
        }
        try {
            $pdo = $this->pdo;
            // 校验 source/target 在 permissions 表中存在
            $check = $pdo->prepare("SELECT COUNT(*) FROM " . AppConfig::TABLE_PERMISSIONS . " WHERE perm_key IN (?, ?)");
            $check->execute([$src, $tgt]);
            if ((int)$check->fetchColumn() < 2) {
                return $this->jsonError($response, 'validation.perm_not_found', 400);
            }
            $sql = \App\Service\Database::sqlUpsert(AppConfig::TABLE_IMPLIED_RULES, 'source_key, target_key', '?, ?');
            $pdo->prepare($sql)->execute([$src, $tgt]);
            return $this->output($response, ['source_key' => $src, 'target_key' => $tgt], $request);
        } catch (\Exception $e) {
            return $this->jsonError($response, $this->__('build.save_failed') . ': ' . $e->getMessage(), 500);
        }
    }

    /**
     * DELETE /api/admin/implied_rules — 删除隐含规则（super_admin 限定）
     * Query: ?source_key=xxx&target_key=yyy
     */
    public function impliedRuleDelete(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if (!$this->hasPermission(AppConfig::PERM_CI_PERMISSIONS_RULES)) {
            return $this->jsonError($response, 'auth.forbidden', 403);
        }
        $params = $request->getQueryParams();
        $src = trim($params['source_key'] ?? '');
        $tgt = trim($params['target_key'] ?? '');
        if ($src === '' || $tgt === '') {
            return $this->jsonError($response, 'validation.required', 400);
        }
        // 内置隐含规则（种子数据，来自 IMPLIED_PERMISSIONS）不可删除
        if (isset(AppConfig::IMPLIED_PERMISSIONS[$src]) && in_array($tgt, AppConfig::IMPLIED_PERMISSIONS[$src], true)) {
            return $this->jsonError($response, 'implied.builtin_protected', 400);
        }
        try {
            $pdo = $this->pdo;
            $pdo->prepare("DELETE FROM " . AppConfig::TABLE_IMPLIED_RULES . " WHERE source_key = ? AND target_key = ?")->execute([$src, $tgt]);
            return $this->output($response, ['deleted' => ['source' => $src, 'target' => $tgt]], $request);
        } catch (\Exception $e) {
            return $this->jsonError($response, $this->__('build.save_failed') . ': ' . $e->getMessage(), 500);
        }
    }

    // ────────────────────────── me/permissions ──────────────────────────

    /**
     * GET /api/admin/me/permissions — 用 token 查当前用户的权限列表
     * 不需要 admin 权限，只需要有效的 Bearer token
     * super_admin 返回 '*' 通配符表示拥有所有权限
     */
    public function mePermissions(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);

        if ($this->currentRole === AppConfig::ROLE_SUPER_ADMIN) {
            $permissions = '*';
        } else {
            $permissions = $this->userPermissions;
        }

        $response->getBody()->write(json_encode([
            'role'        => $this->currentRole,
            'permissions' => $permissions,
        ], JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json');
    }

    // ────────────────────────── API Token 管理（仅 super_admin） ──────────────────────────

    /** GET /api/admin/api_tokens/scopes — 返回可选 scope 目录（供 UI 渲染） */
    public function apiTokenScopes(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requireSuperAdmin($response)) {
            return $resp;
        }
        $scopes = [];
        foreach (AppConfig::API_SCOPES as $key => $labelKey) {
            $scopes[] = ['key' => $key, 'label' => $this->__($labelKey)];
        }
        $response->getBody()->write(json_encode(['scopes' => $scopes], JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /** GET /api/admin/api_tokens — 列表（不含明文） */
    public function apiTokenList(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requireSuperAdmin($response)) {
            return $resp;
        }
        if ($this->apiTokenService === null) {
            return $this->jsonError($response, 'api_token.service_unavailable', 503);
        }
        $tokens = $this->apiTokenService->listTokens();
        $response->getBody()->write(json_encode(['tokens' => $tokens], JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /** POST /api/admin/api_tokens — 创建，返回一次性明文 */
    public function apiTokenCreate(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requireSuperAdmin($response)) {
            return $resp;
        }
        if ($this->apiTokenService === null) {
            return $this->jsonError($response, 'api_token.service_unavailable', 503);
        }
        $body = $request->getParsedBody() ?? json_decode($request->getBody()->__toString(), true) ?? [];

        try {
            $created = $this->apiTokenService->createToken([
                'name'       => $body['name'] ?? '',
                'scopes'     => $body['scopes'] ?? [],
                'expires_at' => $body['expires_at'] ?? null,
                'created_by' => $this->currentUser,
                'note'       => $body['note'] ?? null,
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->jsonError($response, $e->getMessage(), 400);
        } catch (\Exception $e) {
            return $this->jsonError($response, $this->__('build.query_failed') . ': ' . $e->getMessage(), 500);
        }

        $response->getBody()->write(json_encode(['id' => $created['id'], 'token' => $created['token']], JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /** POST /api/admin/api_tokens/{id}/revoke — 撤销（禁用，保留记录） */
    public function apiTokenRevoke(Request $request, Response $response, array $args): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requireSuperAdmin($response)) {
            return $resp;
        }
        if ($this->apiTokenService === null) {
            return $this->jsonError($response, 'api_token.service_unavailable', 503);
        }
        $revoked = $this->apiTokenService->revoke((int)($args['id'] ?? 0));
        $response->getBody()->write(json_encode(['ok' => $revoked], JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /** DELETE /api/admin/api_tokens/{id} — 删除（硬删除记录） */
    public function apiTokenDelete(Request $request, Response $response, array $args): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requireSuperAdmin($response)) {
            return $resp;
        }
        if ($this->apiTokenService === null) {
            return $this->jsonError($response, 'api_token.service_unavailable', 503);
        }
        $deleted = $this->apiTokenService->delete((int)($args['id'] ?? 0));
        $response->getBody()->write(json_encode(['ok' => $deleted], JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json');
    }

    // ────────────────────────── helpers ──────────────────────────

    /**
     * 仅 super_admin 可访问（API Token 管理专属，独立于 RBAC 权限体系）
     */
    private function requireSuperAdmin(Response $response): ?Response
    {
        if ($this->currentRole !== AppConfig::ROLE_SUPER_ADMIN) {
            return $this->jsonError($response, 'api_token.super_admin_only', 403);
        }
        return null;
    }

    /**
     * 展开权限列表：根据 implied_rules 表自动添加隐含权限（数据驱动，不再读常量）
     */
    private function expandPermissions(array $perms): array
    {
        $result = $perms;
        try {
            $pdo = $this->pdo;
            // 一次性把所有 source_key 的目标拉出来，避免 N+1
            $implied = [];
            $rows = $pdo->query("SELECT source_key, target_key FROM " . AppConfig::TABLE_IMPLIED_RULES)->fetchAll();
            foreach ($rows as $r) {
                $implied[$r['source_key']][] = $r['target_key'];
            }
        } catch (\Exception $e) {
            // DB 不可用时回退到常量（避免硬故障）
            $implied = AppConfig::IMPLIED_PERMISSIONS;
        }
        foreach ($perms as $pk) {
            if (isset($implied[$pk])) {
                foreach ($implied[$pk] as $child) {
                    if (!in_array($child, $result, true)) {
                        $result[] = $child;
                    }
                }
            }
        }
        return $result;
    }

    /**
     * 取所有已注册的权限 key（数据驱动，从 DB 读；DB 异常时回退常量）
     */
    private function allPermissionKeys(): array
    {
        try {
            $pdo = $this->pdo;
            return $pdo->query("SELECT perm_key FROM " . AppConfig::TABLE_PERMISSIONS)->fetchAll(\PDO::FETCH_COLUMN);
        } catch (\Exception $e) {
            return array_keys(AppConfig::DEFAULT_PERMISSIONS);
        }
    }


    private static function parseSystems(string $systems): array
    {
        return array_filter(array_map('trim', explode(',', strtolower($systems))), fn($value) => $value !== '');
    }

    private static function systemsContain(string $systems, string $needle): bool
    {
        return in_array($needle, self::parseSystems($systems), true);
    }

    /**
     * 判断角色是否为管理员（兼容旧代码，基于权限 ci.manage）
     */
    private function isAdminRole(): bool
    {
        return $this->hasPermission(AppConfig::PERM_CI_MANAGE);
    }

    /**
     * 判断目标用户是否为管理员角色（基于字面角色名，用于保护内置角色）
     */
    private function isTargetAdmin(string $role): bool
    {
        return $role === AppConfig::ROLE_ADMIN || $role === AppConfig::ROLE_SUPER_ADMIN;
    }


    private function buildEntry(array $body): array
    {
        $entry = [];
        $fields = ['job_name', 'git_platform', 'build_provider', 'git_remote', 'project_id', 'web_url', 'current_path', 'harbor_repository', 'api_version', 'status'];
        foreach ($fields as $f) {
            if (array_key_exists($f, $body)) {
                $val = $body[$f];
                if ($f === 'project_id' && ($val === '' || $val === null)) {
                    $entry[$f] = null;
                } elseif ($f === 'project_id' && is_numeric($val)) {
                    $entry[$f] = (int) $val;
                } else {
                    $entry[$f] = $val;
                }
            }
        }
        if (!isset($entry['job_name'])) {
            $entry['job_name'] = '';
        }
        // 未启用 Custom_Push 时，新增/编辑为 custom_push 的映射强制降为待定，避免「关了开关仍能新增一条 active」
        if (($entry['build_provider'] ?? '') === AppConfig::PROVIDER_CUSTOM_PUSH
            && !$this->config->getCustomPushEnabled()
            && ($entry['status'] ?? AppConfig::STATUS_ACTIVE) === AppConfig::STATUS_ACTIVE) {
            $entry['status'] = AppConfig::STATUS_PENDING;
        }
        return $entry;
    }

    /**
     * 清除拓扑图缓存，确保 mapList 返回最新数据
     */
    private function invalidateTopologyCache(): void
    {
        try {
            $pdo = $this->pdo;
            $modes = [AppConfig::BUILD_MODE_JENKINS, AppConfig::BUILD_MODE_GITLAB_CI, AppConfig::BUILD_MODE_BOTH];
            foreach ($modes as $mode) {
                $pdo->prepare("DELETE FROM " . AppConfig::TABLE_CACHE . " WHERE cache_key = ?")->execute([AppConfig::CACHE_KEY_MAP_LIST_PREFIX . $mode]);
            }
            // custom_push 缓存 key 也清理
            $pdo->prepare("DELETE FROM " . AppConfig::TABLE_CACHE . " WHERE cache_key = ?")->execute([AppConfig::CACHE_KEY_MAP_LIST_PREFIX . AppConfig::PROVIDER_CUSTOM_PUSH]);
        } catch (\Exception $e) {
            // 缓存清理失败不影响主流程
        }
    }
}