<?php

namespace App\Controller;

use App\Controller\Admin\BaseAdminController;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Config\AppConfig;
use App\Exceptions\ApiException;
use App\Service\Git\HostingType;
use App\Service\AdminAuthService;
use App\Service\AdminUserRepository;
use App\Service\ApiAccessLogRepository;
use App\Service\ApiTokenService;
use App\Service\AutoDiscover;
use App\Service\HarborService;
use App\Service\Registry\RegistryProviderInterface;
use App\Service\JenkinsService;
use App\Service\DeployLogRepository;
use App\Service\MappingManager;
use App\Service\OAuthClientRepository;
use App\Service\PlatformConnectionRepository;
use App\Service\PasswordHasher;
use App\Service\PlatformVersionRepository;
use App\Service\TokenService;
use App\Service\AdminRbacService;

class AdminController extends BaseAdminController
{
    private MappingManager $mapping;
    private PlatformVersionRepository $platformVersions;
    private OAuthClientRepository $oauthClients;
    private PlatformConnectionRepository $platformConnections;
    private ?AutoDiscover $autoDiscover;
    private ?TokenService $tokenService = null;
    private ?ApiTokenService $apiTokenService = null;
    private AdminAuthService $adminAuthService;
    private AdminUserRepository $adminUserRepository;
    private ?HarborService $harbor;
    private ?RegistryProviderInterface $registryProvider;
    private ?JenkinsService $jenkins = null;
    private ?DeployLogRepository $deployLog = null;
    private ?ApiAccessLogRepository $apiAccessLog = null;
    private AdminRbacService $rbacService;

    public function __construct(
        \App\Service\I18nService $i18n,
        \App\Service\Settings $config,
        \PDO $pdo,
        \App\Service\AppSettingRepository $appSettings,
        MappingManager $mapping,
        PlatformVersionRepository $platformVersions,
        OAuthClientRepository $oauthClients,
        PlatformConnectionRepository $platformConnections,
        AdminAuthService $adminAuthService,
        AdminUserRepository $adminUserRepository,
        AdminRbacService $rbacService,
        ?AutoDiscover $autoDiscover = null,
        ?TokenService $tokenService = null,
        ?ApiTokenService $apiTokenService = null,
        ?HarborService $harbor = null,
        ?JenkinsService $jenkins = null,
        ?RegistryProviderInterface $registryProvider = null
    ) {
        parent::__construct($i18n, $config, $pdo, $appSettings);
        $this->mapping            = $mapping;
        $this->platformVersions   = $platformVersions;
        $this->oauthClients       = $oauthClients;
        $this->platformConnections = $platformConnections;
        $this->autoDiscover       = $autoDiscover;
        $this->tokenService       = $tokenService;
        $this->apiTokenService    = $apiTokenService;
        $this->adminAuthService   = $adminAuthService;
        $this->adminUserRepository = $adminUserRepository;
        $this->harbor             = $harbor;
        $this->jenkins            = $jenkins;
        $this->registryProvider   = $registryProvider;
        $this->rbacService        = $rbacService;
    }

    /** 部署日志仓储（懒加载，经 CD 只读端点拉取部署记录） */
    private function deployLog(): DeployLogRepository
    {
        if ($this->deployLog === null) {
            $cd = $this->config->getCdConfig();
            $this->deployLog = new DeployLogRepository($cd['base_url'] ?? '', $cd['api_token'] ?? '');
        }
        return $this->deployLog;
    }

    /** API token 调用日志仓储（懒加载） */
    private function apiAccessLogRepo(): ApiAccessLogRepository
    {
        if ($this->apiAccessLog === null) {
            $this->apiAccessLog = new ApiAccessLogRepository($this->pdo);
        }
        return $this->apiAccessLog;
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
            $result = $this->autoDiscover->discover();
            $found = $result['found'];
            $errors  = $result['errors'];
            $sources = $result['sources'];
            $saved = $this->autoDiscover->saveDiscovered($found);
            if ($saved > 0) {
                $this->invalidateTopologyCache();
            }
            $this->opLog()->record($this->currentUser, 'auto_discover', '', ['found' => count($found), 'saved' => $saved], $this->clientIp($request), 'success');
            return $this->output($response, [
                'found' => count($found),
                'saved' => $saved,
                'errors' => $errors,
                'sources' => $sources,
                'items' => array_map(function ($i) {
                    $entry = is_array($i['entry'] ?? null) ? $i['entry'] : [];
                    return $entry['job_name'] ?? null;
                }, $found),
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
        $user = strtolower(trim($body['username'] ?? $body['user'] ?? ''));
        $pass = $body['password'] ?? '';
        if ($user === '' || $pass === '') {
            return $this->jsonError($response, 'auth.wrong_credentials', 401);
        }

        $clientIp = $this->clientIp($request);
        if ($this->adminAuthService->isLoginLocked($clientIp, $user)) {
            $this->opLog()->record($user, 'login_locked', '', [], $clientIp, 'failure');
            return $this->jsonError($response, 'auth.login_locked', 429);
        }

        $result = $this->adminAuthService->authenticate($user, $pass, $this->config->getSystemType());
        if (!$result['success']) {
            $this->adminAuthService->recordLoginFailure($clientIp, $user);
            $this->opLog()->record($user, 'login_failed', '', [], $clientIp, 'failure');
            return $this->jsonError($response, $result['errorKey'], 401);
        }
        $this->adminAuthService->clearLoginFailure($clientIp, $user);

        $loginRole = $result['role'];
        $isRoot = $result['isRoot'];

        if (\function_exists('random_bytes')) {
            $randomBytes = \random_bytes(32);
        } elseif (\function_exists('openssl_random_pseudo_bytes')) {
            $randomBytes = \openssl_random_pseudo_bytes(32);
        } else {
            return $this->jsonError($response, 'auth.token_generate_failed', 500);
        }

        $token = bin2hex($randomBytes);
        try {
            $this->pdo->prepare("DELETE FROM " . AppConfig::TABLE_CACHE . " WHERE cache_key LIKE ? AND expires_at <= ?")
                ->execute([AppConfig::CACHE_KEY_ADMIN_TOKEN_PREFIX . '%', time()]);
            $sql = \App\Service\Database::sqlUpsert(AppConfig::TABLE_CACHE, 'cache_key, value, expires_at', '?, ?, ?');
            $this->pdo->prepare($sql)->execute([AppConfig::CACHE_KEY_ADMIN_TOKEN_PREFIX . TokenService::hashAdminToken($token), $user . '|' . $loginRole, time() + AppConfig::TTL_TOKEN]);
        } catch (\Exception $e) {
            return $this->jsonError($response, 'auth.token_store_failed', 500);
        }
        $perms = $this->tokenService?->loadPermissions($loginRole) ?? [];

        $this->opLog()->record($user, 'login', '', [], $clientIp, 'success');

        return $this->output($response, [
            'token'       => $token,
            'role'        => $loginRole,
            'user'        => $user,
            'is_root'     => $isRoot,
            'permissions' => $perms,
            'must_change_password' => !empty($result['must_change_password']),
        ], $request);
    }

    /**
     * POST /api/admin/logout — 退出登录，清理 token
     */
    public function logout(Request $request, Response $response): Response
    {
        $header = $request->getHeaderLine('Authorization');
        $username = '';
        if (preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
            $token = $m[1];
            $username = $this->tokenUserFromCache($token);
            $this->tokenService?->revoke($token);
        }
        if ($username !== '') {
            $this->opLog()->record($username, 'logout', '', [], $this->clientIp($request), 'success');
        }
        return $this->output($response, ['message' => 'logged_out'], $request);
    }

    private function tokenUserFromCache(string $token): string
    {
        try {
            $stmt = $this->pdo->prepare("SELECT value FROM " . AppConfig::TABLE_CACHE . " WHERE cache_key = ?");
            $stmt->execute([AppConfig::CACHE_KEY_ADMIN_TOKEN_PREFIX . TokenService::hashAdminToken($token)]);
            $value = $stmt->fetchColumn();
            if ($value !== false && $value !== '') {
                $parts = explode('|', (string)$value);
                if (count($parts) >= 2) {
                    array_pop($parts);
                    return implode('|', $parts);
                }
            }
        } catch (\Throwable $e) {
        }
        return '';
    }

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
            $username = $this->currentUser;
            if ($username === '') {
                return $this->jsonError($response, 'auth.not_logged_in', 401);
            }

            if (!$this->adminAuthService->verifyCurrentPassword($username, $oldPass)) {
                $this->opLog()->record($username, 'change_password', $username, [], $this->clientIp($request), 'failure');
                return $this->jsonError($response, 'auth.old_password_wrong', 403);
            }

            $hash = PasswordHasher::hash($newPass);
            $repository = new \App\Service\AdminUserRepository($this->pdo);
            $repository->updatePassword($username, $hash);

            try {
                $header = $request->getHeaderLine('Authorization');
                if (preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
                    $this->tokenService?->revoke($m[1]);
                }
            } catch (\Exception $e) {
                \App\Helper\Log::exception($e);
            }

            $this->opLog()->record($username, 'change_password', $username, [], $this->clientIp($request), 'success');
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

        $allMaps = $this->mapping->allMaps();
        $gitPlatforms = $this->config->getGitPlatformsConfig();
        $platformNames = array_map(fn($p) => $p['name'], $gitPlatforms);

        $filtered = $allMaps;
        if ($search !== '') {
            $s = mb_strtolower($search);
            $filtered = array_filter($filtered, function ($m) use ($s) {
                foreach (['job_name','git_remote','current_path','registry_repository'] as $f) {
                    if (mb_strpos(mb_strtolower($m[$f] ?? ''), $s) !== false) {
                        return true;
                    }
                }
                return false;
            });
        }
        if ($platform !== '') {
            $filtered = array_filter($filtered, fn($m) => ($m['git_platform'] ?? '') === $platform);
        }
        if ($provider !== '') {
            $filtered = array_filter($filtered, fn($m) => (string) ($m['build_provider'] ?? '') === $provider);
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

        if (trim((string) ($body['build_provider'] ?? '')) === '') {
            return $this->jsonError($response, 'map.build_provider_required', 400);
        }

        foreach ($this->mapping->allMaps() as $item) {
            if (($item['job_name'] ?? '') === $jobName) {
                return $this->jsonError($response, $this->__('map.already_exists', ['{name}' => $jobName]), 409);
            }
        }

        $entry = $this->buildEntry($body);
        $this->mapping->saveMap($entry);
        $this->invalidateTopologyCache();

        $this->opLog()->record($this->currentUser, 'create_mapping', $jobName, [], $this->clientIp($request), 'success');
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

        $found  = false;
        $oldRow = [];
        foreach ($this->mapping->allMaps() as $item) {
            if (($item['job_name'] ?? '') === $oldName) {
                $found  = true;
                $oldRow = $item;
                break;
            }
        }

        if (!$found) {
            return $this->jsonError($response, $this->__('map.not_found', ['{name}' => $oldName]), 404);
        }

        $updatedEntry = $this->buildEntry($body);
        $newName      = trim((string) ($updatedEntry['job_name'] ?? ''));
        if ($newName === '') {
            return $this->jsonError($response, 'map.job_name_required', 400);
        }
        $merged = array_merge($oldRow, $updatedEntry);
        $this->mapping->saveMap($merged);
        if ($newName !== $oldName) {
            $this->mapping->deleteMap($oldName);
        }
        $this->invalidateTopologyCache();
        $this->opLog()->record($this->currentUser, 'update_mapping', $oldName, ['job_name' => $merged['job_name'] ?? $oldName], $this->clientIp($request), 'success');
        return $this->output($response, ['success' => true, 'entry' => $merged], $request);
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

        $maps = $this->mapping->allMaps();
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

        $this->mapping->deleteMap($jobName);
        $this->invalidateTopologyCache();
        $this->opLog()->record($this->currentUser, 'delete_mapping', $jobName, [], $this->clientIp($request), 'success');
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

        $gitPlatforms = $this->config->getGitPlatformsConfig();
        $configuredGit = array_column($gitPlatforms, 'name');

        foreach ($versions as $name => &$info) {
            $info['configured'] = in_array($name, $configuredGit) || $name === 'harbor';
        }
        unset($info);

        if (isset($versions['harbor'])) {
            $registryType = $this->config->getRegistryType();
            $versions['harbor']['registry_type'] = $registryType;
            $versions['harbor']['robot_account'] = $registryType === 'registry'
                ? false
                : $this->config->isHarborRobotAccount();
        }

        $hasJenkins = !empty($this->config->getJenkinsConfig()['url']);
        $versions['jenkins'] = [
            'value'      => '',
            'source'     => 'config',
            'configured' => $hasJenkins,
        ];

        return $this->output($response, ['versions' => $versions], $request);
    }

    /**
     * GET /api/admin/platform_versions/probe — 实际探测 Harbor / Jenkins 版本（慢，独立端点）。
     * 与列表接口分离：列表毫秒级返回配置态，探测结果由前端后台补齐，避免平台不可达时整页卡住。
     */
    public function platformVersionsProbe(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_PLATFORM_EDIT)) {
            return $resp;
        }

        $result = ['harbor' => [], 'jenkins' => []];
        if ($this->config->getRegistryType() !== 'registry') {
            try {
                $result['harbor']['detected_version'] = $this->harbor?->getHarborVersion();
                $result['harbor']['robot_support']    = $this->harbor?->getRobotAccountSupport() ?? 'unknown';
            } catch (\Throwable $e) {
                \App\Helper\Log::error('[版本探测] 镜像仓库连接失败', ['error' => $e->getMessage()]);
            }
        }

        if (!empty($this->config->getJenkinsConfig()['url'])) {
            try {
                $result['jenkins']['detected_version'] = $this->jenkins?->getVersion();
            } catch (\Throwable $e) {
                \App\Helper\Log::error('[版本探测] Jenkins 连接失败', ['error' => $e->getMessage()]);
            }
        }

        return $this->output($response, $result, $request);
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
        if (!is_array($versions)) {
            return $this->jsonError($response, 'platform.versions_empty', 400);
        }

        if ($versions === []) {
            return $this->output($response, ['success' => true, 'changed' => false, 'versions' => $this->config->getPlatformApiVersions()], $request);
        }

        $this->platformVersions->saveAll($versions);
        $this->opLog()->record($this->currentUser, 'update_platform_version', '', ['versions' => $versions], $this->clientIp($request), 'success');
        return $this->output($response, ['success' => true, 'changed' => true, 'versions' => $this->config->getPlatformApiVersions()], $request);
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
        $modes = $this->appSettings->getBuildModes();

        $jenkinsCfg = $this->config->getJenkinsConfig();
        $hasJenkins = !empty($jenkinsCfg['url']);
        $hasGitlab = $this->config->isPlatformConfigured('gitlab');
        $glCfg = $hasGitlab ? $this->config->getGitlabConfig() : [];
        $hasGitlabCi = $hasGitlab && !empty($glCfg['base_url']) && !empty($glCfg['token']);
        $hasGitea = $this->config->isPlatformConfigured('gitea');
        $giteaCfg = $hasGitea ? $this->config->getGiteaConfig() : [];
        $hasGiteaCi = $hasGitea && !empty($giteaCfg['base_url']) && !empty($giteaCfg['token']);
        $hasGithub = $this->config->isPlatformConfigured('github');
        $githubCfg = $hasGithub ? $this->config->getGithubConfig() : [];
        $hasGithubActions = $hasGithub && !empty($githubCfg['base_url']) && !empty($githubCfg['token']);

        return $this->output($response, [
            'mode'          => $this->appSettings->getBuildMode(),
            'modes'         => $modes,
            'source'        => $this->appSettings->getBuildModeSource(),
            'has_jenkins'   => $hasJenkins,
            'has_gitlab_ci' => $hasGitlabCi,
            'has_gitea_ci'  => $hasGiteaCi,
            'has_github_actions' => $hasGithubActions,
            'custom_push_enabled' => $this->appSettings->getCustomPushEnabled(),
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
        $modes = $body['modes'] ?? null;
        if (!is_array($modes)) {
            $modes = isset($body['mode']) ? [trim((string) $body['mode'])] : [];
        }
        $modes = array_values(array_unique(array_filter(array_map('trim', $modes), fn($s) => $s !== '')));
        $cpEnabled = !empty($body['custom_push_enabled']);

        foreach ($modes as $m) {
            if (!in_array($m, AppConfig::BUILTIN_PULL_PROVIDERS, true)) {
                return $this->jsonError($response, 'build.mode_required', 400);
            }
        }

        $jenkinsCfg = $this->config->getJenkinsConfig();
        $hasJenkins = !empty($jenkinsCfg['url']);
        $hasGitlab = $this->config->isPlatformConfigured('gitlab');
        $glCfg = $hasGitlab ? $this->config->getGitlabConfig() : [];
        $hasGitlabCi = $hasGitlab && !empty($glCfg['base_url']) && !empty($glCfg['token']);
        $hasGitea = $this->config->isPlatformConfigured('gitea');
        $giteaCfg = $hasGitea ? $this->config->getGiteaConfig() : [];
        $hasGiteaCi = $hasGitea && !empty($giteaCfg['base_url']) && !empty($giteaCfg['token']);
        $hasGithub = $this->config->isPlatformConfigured('github');
        $githubCfg = $hasGithub ? $this->config->getGithubConfig() : [];
        $hasGithubActions = $hasGithub && !empty($githubCfg['base_url']) && !empty($githubCfg['token']);

        if (in_array(AppConfig::PROVIDER_JENKINS, $modes, true) && !$hasJenkins) {
            return $this->jsonError($response, 'build.jenkins_unavail', 400);
        }
        if (in_array(AppConfig::PROVIDER_GITLAB_CI, $modes, true) && !$hasGitlabCi) {
            return $this->jsonError($response, 'build.gitlab_ci_unavail', 400);
        }
        if (in_array(AppConfig::PROVIDER_GITEA_CI, $modes, true) && !$hasGiteaCi) {
            return $this->jsonError($response, 'build.gitea_ci_unavail', 400);
        }
        if (in_array(AppConfig::PROVIDER_GITHUB_ACTIONS, $modes, true) && !$hasGithubActions) {
            return $this->jsonError($response, 'build.github_actions_unavail', 400);
        }
        if ($cpEnabled && empty($this->config->getCustomBuildProviders())) {
            return $this->jsonError($response, 'build.custom_push_no_provider', 400);
        }

        try {
            $this->appSettings->setBuildModes($modes);
            $this->appSettings->setCustomPushEnabled($cpEnabled);

            $removed = array_values(array_diff(AppConfig::BUILTIN_PULL_PROVIDERS, $modes));
            if (!empty($removed)) {
                $maps = $this->mapping->allMaps();
                $changed = false;
                foreach ($maps as &$m) {
                    $bp = (string) ($m['build_provider'] ?? '');
                    if ($bp === '') {
                        continue;
                    }
                    if (in_array($bp, $removed, true) && ($m['status'] ?? AppConfig::STATUS_ACTIVE) !== AppConfig::STATUS_PENDING) {
                        $m['status'] = AppConfig::STATUS_PENDING;
                        $changed = true;
                    }
                }
                if ($changed) {
                    $this->mapping->saveMaps($maps);
                    $this->invalidateTopologyCache();
                }
            }

            if (!$cpEnabled) {
                $maps = $this->mapping->allMaps();
                $changed = false;
                foreach ($maps as &$m) {
                    if (
                        ($m['build_provider'] ?? '') === AppConfig::PROVIDER_CUSTOM_PUSH
                        && ($m['status'] ?? AppConfig::STATUS_ACTIVE) !== AppConfig::STATUS_PENDING
                    ) {
                        $m['status'] = AppConfig::STATUS_PENDING;
                        $changed = true;
                    }
                }
                if ($changed) {
                    $this->mapping->saveMaps($maps);
                    $this->invalidateTopologyCache();
                }
            }

            $this->opLog()->record($this->currentUser, 'update_build_mode', '', ['modes' => $modes, 'custom_push_enabled' => $cpEnabled], $this->clientIp($request), 'success');
            return $this->output($response, ['success' => true, 'modes' => $modes, 'custom_push_enabled' => $cpEnabled], $request);
        } catch (\Exception $e) {
            return $this->jsonError($response, $this->__('build.save_failed') . ': ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/admin/custom_builds — Custom_Push 构建记录列表（push 记录 Tab）
     */
    public function customBuildList(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_BUILD_RECORDS_PUSH)) {
            return $resp;
        }

        $params  = $request->getQueryParams();
        $page    = max(1, (int)($params['page'] ?? 1));
        $perPage = max(1, min(100, (int)($params['per_page'] ?? 20)));

        try {
            $pdo = $this->pdo;

            $total = (int) $pdo->query("SELECT count(*) FROM " . AppConfig::TABLE_CUSTOM_BUILDS)->fetchColumn();

            $totalPages = max(1, (int) ceil($total / $perPage));
            $page = min($page, $totalPages);
            $offset = ($page - 1) * $perPage;

            $stmt = $pdo->prepare(
                'SELECT b.job_name, b.pipeline_iid, b.status, b.sha, b.variables_json, b.log_url, b.web_url, b.finished_at,
                        t.tag
                 FROM ' . AppConfig::TABLE_CUSTOM_BUILDS . ' b
                 LEFT JOIN ' . AppConfig::TABLE_PIPELINE_ARTIFACTS . ' t
                   ON t.project_key = b.job_name AND t.pipeline_iid = b.pipeline_iid AND t.provider = ?
                 ORDER BY b.finished_at DESC, b.pipeline_iid DESC
                 LIMIT ' . $perPage . ' OFFSET ' . $offset
            );
            $stmt->execute([AppConfig::PROVIDER_CUSTOM_PUSH]);
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
        if (!AdminUserRepository::isValidUsername($username)) {
            return $this->jsonError($response, 'user.invalid_username', 400);
        }

        $canonicalRole = $this->adminUserRepository->canonicalRole($role);
        if ($canonicalRole === null) {
            return $this->jsonError($response, 'user.role_not_found', 400);
        }
        $role = $canonicalRole;

        if ($role === AppConfig::ROLE_SUPER_ADMIN && $this->currentRole !== AppConfig::ROLE_SUPER_ADMIN) {
            return $this->jsonError($response, 'user.cannot_create_super_admin', 403);
        }
        if ($role === AppConfig::ROLE_SUPER_ADMIN && $this->adminUserRepository->countByRole(AppConfig::ROLE_SUPER_ADMIN) > 0) {
            return $this->jsonError($response, 'user.only_one_super_admin', 409);
        }
        if ($role === AppConfig::ROLE_ADMIN || $role === AppConfig::ROLE_SUPER_ADMIN) {
            if (!$this->hasPermission(AppConfig::PERM_CI_USERS_MANAGE_ADMIN)) {
                return $this->jsonError($response, 'user.cannot_create_admin', 403);
            }
            if (!AdminRbacService::systemsContain($systems, AppConfig::SYSTEM_CD)) {
                $systems = $systems === '' ? AppConfig::SYSTEM_CD : $systems . ',' . AppConfig::SYSTEM_CD;
            }
        }

        if (!$this->isAdminRole() && AdminRbacService::systemsContain($systems, AppConfig::SYSTEM_CI)) {
            return $this->jsonError($response, 'user.cd_no_ci_access', 403);
        }

        try {
            if ($this->adminUserRepository->userExists($username)) {
                return $this->jsonError($response, 'user.username_exists', 409);
            }

            $hash = PasswordHasher::hash($password);
            $this->adminUserRepository->createUser($username, $hash, $role, $systems, $email);

            $this->opLog()->record($this->currentUser, 'create_user', $username, ['role' => $role, 'systems' => $systems], $this->clientIp($request), 'success');
            return $this->output($response, ['success' => true, 'username' => $username], $request);
        } catch (\Exception $e) {
            return $this->jsonError($response, $this->__('build.save_failed') . ': ' . $e->getMessage(), 500);
        }
    }

    /**
     * PUT /api/admin/users/{username} — 更新用户（改密码 / 改角色）
     *
     * @param array<string,string> $args
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

            $rootAdmin = $this->config->getRootAdminUser();
            if ($targetUser === $rootAdmin) {
                if ($targetUser !== $this->currentUser || $role !== null || ($password !== null && $password !== '')) {
                    return $this->jsonError($response, 'user.cannot_edit_root', 403);
                }
            }

            if ($this->isTargetAdmin($target['role']) && !$this->hasPermission(AppConfig::PERM_CI_USERS_MANAGE_ADMIN)) {
                return $this->jsonError($response, 'user.cannot_edit_admin', 403);
            }

            if ($role !== null) {
                $canonicalRole = $this->adminUserRepository->canonicalRole($role);
                if ($canonicalRole === null) {
                    return $this->jsonError($response, 'user.role_not_found', 400);
                }
                $role = $canonicalRole;
                if ($role === AppConfig::ROLE_SUPER_ADMIN && $this->currentRole !== AppConfig::ROLE_SUPER_ADMIN) {
                    return $this->jsonError($response, 'user.cannot_promote_super_admin', 403);
                }
                if (
                    $role === AppConfig::ROLE_SUPER_ADMIN
                    && $target['role'] !== AppConfig::ROLE_SUPER_ADMIN
                    && $this->adminUserRepository->countByRole(AppConfig::ROLE_SUPER_ADMIN) > 0
                ) {
                    return $this->jsonError($response, 'user.only_one_super_admin', 409);
                }
                if (($role === AppConfig::ROLE_ADMIN || $role === AppConfig::ROLE_SUPER_ADMIN) && !$this->hasPermission(AppConfig::PERM_CI_USERS_MANAGE_ADMIN)) {
                    return $this->jsonError($response, 'user.cannot_promote_admin', 403);
                }
            }

            $passwordHash = null;
            if ($password !== null && $password !== '') {
                if (strlen($password) < 8) {
                    return $this->jsonError($response, 'auth.new_password_short', 400);
                }
                $passwordHash = PasswordHasher::hash($password);
            }

            if ($role === null && $passwordHash === null && $email === null) {
                return $this->jsonError($response, 'user.nothing_to_update', 400);
            }

            $this->adminUserRepository->updateUser($targetUser, $passwordHash, $role, $email);
            $this->opLog()->record($this->currentUser, 'update_user', $targetUser, ['role' => $role, 'password_changed' => $passwordHash !== null], $this->clientIp($request), 'success');
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
     *
     * @param array<string,string> $args
     */
    public function userModifyPassword(Request $request, Response $response, array $args): Response
    {
        $this->initAuthFromRequest($request);
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

            if ($targetUser === $this->config->getRootAdminUser()) {
                return $this->jsonError($response, 'user.cannot_edit_root', 403);
            }

            $this->adminUserRepository->updatePassword($targetUser, PasswordHasher::hash($newPass));

            $this->opLog()->record($this->currentUser, 'reset_user_password', $targetUser, [], $this->clientIp($request), 'success');
            return $this->output($response, ['success' => true, 'username' => $targetUser], $request);
        } catch (\Exception $e) {
            return $this->jsonError($response, $this->__('build.modify_failed') . ': ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/admin/login_locks/clear — 解除所有登录失败锁定
     *
     * 全局清除 ci_cache 里 login_fail_* 限流计数（IP + 用户名维度），
     * 供被连续输错密码锁定的账号立即恢复登录。硬门槛：仅 super_admin（同重置他人密码）。
     */
    public function clearLoginLocks(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if ($this->currentRole !== AppConfig::ROLE_SUPER_ADMIN) {
            return $this->jsonError($response, 'user.unlock_forbidden', 403);
        }
        try {
            $stmt = $this->pdo->prepare("DELETE FROM " . AppConfig::TABLE_CACHE . " WHERE cache_key LIKE ?");
            $stmt->execute([AppConfig::CACHE_KEY_LOGIN_FAIL_PREFIX . '%']);
            $cleared = $stmt->rowCount();
            $this->opLog()->record($this->currentUser, 'clear_login_locks', '', ['cleared' => $cleared], $this->clientIp($request), 'success');
            return $this->output($response, ['success' => true, 'cleared' => $cleared], $request);
        } catch (\Exception $e) {
            return $this->jsonError($response, $this->__('build.modify_failed') . ': ' . $e->getMessage(), 500);
        }
    }

    /**
     * PUT /api/admin/users/{username}/status — 启用/停用用户
     * Body: { "enabled": true|false }
     * 保护：root 账号不可停用；不能停用自己。
     *
     * @param array<string,string> $args
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
            if (!$enabled && $this->isTargetAdmin($target['role']) && !$this->hasPermission(AppConfig::PERM_CI_USERS_MANAGE_ADMIN)) {
                return $this->jsonError($response, 'user.cannot_edit_admin', 403);
            }

            $this->adminUserRepository->setStatus($targetUser, $enabled ? 1 : 0);
            $this->opLog()->record($this->currentUser, 'set_user_status', $targetUser, ['enabled' => $enabled], $this->clientIp($request), 'success');
            return $this->output($response, ['success' => true, 'enabled' => $enabled], $request);
        } catch (\Exception $e) {
            return $this->jsonError($response, $this->__('build.modify_failed') . ': ' . $e->getMessage(), 500);
        }
    }

    /**
     * DELETE /api/admin/users/{username} — 删除用户
     *
     * @param array<string,string> $args
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

            $rootAdmin = $this->config->getRootAdminUser();
            if ($targetUser === $rootAdmin) {
                return $this->jsonError($response, 'user.cannot_delete_root', 403);
            }
            if ($targetUser === $this->currentUser) {
                return $this->jsonError($response, 'user.cannot_delete_self', 403);
            }
            if ($this->isTargetAdmin($target['role']) && !$this->hasPermission(AppConfig::PERM_CI_USERS_MANAGE_ADMIN)) {
                return $this->jsonError($response, 'user.cannot_delete_admin', 403);
            }

            $this->adminUserRepository->deleteUser($targetUser);
            $this->opLog()->record($this->currentUser, 'delete_user', $targetUser, [], $this->clientIp($request), 'success');
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
            $expanded = $this->rbacService->expandPermissions($perms);
            $validKeys = $this->rbacService->allPermissionKeys();
            $permInsert = $pdo->prepare("INSERT INTO " . AppConfig::TABLE_ROLE_PERMISSIONS . " (role_id, perm_key) VALUES (?, ?)");
            foreach ($expanded as $pk) {
                if (in_array($pk, $validKeys, true)) {
                    try {
                        $permInsert->execute([$roleId, $pk]);
                    } catch (\Exception $e) {
                        \App\Helper\Log::exception($e);
                    }
                }
            }
            $this->opLog()->record($this->currentUser, 'create_role', $name, ['permissions' => $perms], $this->clientIp($request), 'success');
            return $this->output($response, ['success' => true, 'id' => (int)$roleId, 'name' => $name], $request);
        } catch (\Exception $e) {
            return $this->jsonError($response, $this->__('build.save_failed') . ': ' . $e->getMessage(), 500);
        }
    }

    /**
     * PUT /api/admin/roles/{id} — 更新角色（权限列表全覆盖）
     *
     * @param array<string,string> $args
     */
    public function roleUpdate(Request $request, Response $response, array $args): Response
    {
        $this->initAuthFromRequest($request);
        if (!$this->hasPermission(AppConfig::PERM_CI_USERS_MANAGE_ADMIN)) {
            return $this->jsonError($response, 'user.cannot_create_admin', 403);
        }
        $roleId = (int)($args['id'] ?? 0);

        $parsedBody = $request->getParsedBody();
        if (!is_array($parsedBody) && !is_object($parsedBody)) {
            $rawBody = (string) $request->getBody();
            $parsedBody = json_decode($rawBody, true);
        }
        $body  = is_array($parsedBody) ? $parsedBody : [];
        $name  = trim($body['name'] ?? '');
        $desc  = trim($body['description'] ?? '');
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

            $fields = [];
            $params = [];
            if ($name !== '' && $name !== $r['name']) {
                $dup = $pdo->prepare("SELECT id FROM " . AppConfig::TABLE_ROLES . " WHERE name = ? AND id != ?");
                $dup->execute([$name, $roleId]);
                if ($dup->fetch()) {
                    return $this->jsonError($response, 'user.username_exists', 409);
                }
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

            if (is_array($perms)) {
                $pdo->prepare("DELETE FROM " . AppConfig::TABLE_ROLE_PERMISSIONS . " WHERE role_id = ?")->execute([$roleId]);
                $expanded = $this->rbacService->expandPermissions($perms);
                $validKeys = $this->rbacService->allPermissionKeys();
                $permInsert = $pdo->prepare("INSERT INTO " . AppConfig::TABLE_ROLE_PERMISSIONS . " (role_id, perm_key) VALUES (?, ?)");
                foreach ($expanded as $pk) {
                    if (in_array($pk, $validKeys, true)) {
                        try {
                            $permInsert->execute([$roleId, $pk]);
                        } catch (\Exception $e) {
                            \App\Helper\Log::exception($e);
                        }
                    }
                }
            }

            $this->opLog()->record($this->currentUser, 'update_role', (string)$r['name'], ['new_name' => $name !== '' ? $name : $r['name'], 'permissions' => is_array($perms) ? $perms : null], $this->clientIp($request), 'success');
            return $this->output($response, ['success' => true], $request);
        } catch (\Exception $e) {
            return $this->jsonError($response, $this->__('build.modify_failed') . ': ' . $e->getMessage(), 500);
        }
    }

    /**
     * DELETE /api/admin/roles/{id} — 删除角色（仅自定义角色）
     *
     * @param array<string,string> $args
     */
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
            if ($this->adminUserRepository->countByRole($r['name']) > 0) {
                return $this->jsonError($response, 'role.in_use', 400);
            }
            $pdo->prepare("DELETE FROM " . AppConfig::TABLE_ROLE_PERMISSIONS . " WHERE role_id = ?")->execute([$roleId]);
            $pdo->prepare("DELETE FROM " . AppConfig::TABLE_ROLES . " WHERE id = ?")->execute([$roleId]);
            $this->opLog()->record($this->currentUser, 'delete_role', (string)$r['name'], [], $this->clientIp($request), 'success');
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
            foreach ($rows as &$row) {
                $row['is_builtin'] = array_key_exists($row['perm_key'], AppConfig::DEFAULT_PERMISSIONS);
            }
            unset($row);
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
        if (array_key_exists($permKey, AppConfig::DEFAULT_PERMISSIONS)) {
            return $this->jsonError($response, 'permission.builtin_immutable', 400);
        }
        try {
            $pdo = $this->pdo;
            $exists = $pdo->prepare("SELECT COUNT(*) FROM " . AppConfig::TABLE_PERMISSIONS . " WHERE perm_key = ?");
            $exists->execute([$permKey]);
            if ((int)$exists->fetchColumn() > 0) {
                $pdo->prepare("UPDATE " . AppConfig::TABLE_PERMISSIONS . " SET description = ?, parent_key = ? WHERE perm_key = ?")->execute([$desc, $parent, $permKey]);
            } else {
                $pdo->prepare("INSERT INTO " . AppConfig::TABLE_PERMISSIONS . " (perm_key, description, parent_key, created_at) VALUES (?, ?, ?, " . \App\Service\Database::sqlNow() . ")")->execute([$permKey, $desc, $parent]);
            }
            $this->opLog()->record($this->currentUser, 'register_permission', $permKey, ['description' => $desc, 'parent_key' => $parent], $this->clientIp($request), 'success');
            return $this->output($response, ['perm_key' => $permKey, 'description' => $desc, 'parent_key' => $parent], $request);
        } catch (\Exception $e) {
            return $this->jsonError($response, $this->__('build.save_failed') . ': ' . $e->getMessage(), 500);
        }
    }

    /**
     * DELETE /api/admin/permissions/{perm_key} — 删除权限（super_admin 限定）
     * 系统内置 key（DEFAULT_PERMISSIONS 中的）不可删，避免误删核心权限
     *
     * @param array<string,string> $args
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
        if (array_key_exists($permKey, AppConfig::DEFAULT_PERMISSIONS)) {
            return $this->jsonError($response, 'permission.builtin_protected', 400);
        }
        try {
            $pdo = $this->pdo;
            $pdo->prepare("DELETE FROM " . AppConfig::TABLE_PERMISSIONS . " WHERE perm_key = ?")->execute([$permKey]);
            $pdo->prepare("DELETE FROM " . AppConfig::TABLE_ROLE_PERMISSIONS . " WHERE perm_key = ?")->execute([$permKey]);
            $pdo->prepare("DELETE FROM " . AppConfig::TABLE_IMPLIED_RULES . " WHERE source_key = ? OR target_key = ?")->execute([$permKey, $permKey]);
            $this->opLog()->record($this->currentUser, 'delete_permission', $permKey, [], $this->clientIp($request), 'success');
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
            $check = $pdo->prepare("SELECT COUNT(*) FROM " . AppConfig::TABLE_PERMISSIONS . " WHERE perm_key IN (?, ?)");
            $check->execute([$src, $tgt]);
            if ((int)$check->fetchColumn() < 2) {
                return $this->jsonError($response, 'validation.perm_not_found', 400);
            }
            $sql = \App\Service\Database::sqlUpsert(AppConfig::TABLE_IMPLIED_RULES, 'source_key, target_key', '?, ?');
            $pdo->prepare($sql)->execute([$src, $tgt]);
            $resynced = 0;
            try {
                $resynced = $this->rbacService->resyncImpliedPermissions();
            } catch (\Exception $e) {
                \App\Helper\Log::exception($e);
            }
            $this->opLog()->record($this->currentUser, 'create_implied_rule', $src . ' → ' . $tgt, ['roles_resynced' => $resynced], $this->clientIp($request), 'success');
            return $this->output($response, ['source_key' => $src, 'target_key' => $tgt, 'roles_resynced' => $resynced], $request);
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
        if (isset(AppConfig::IMPLIED_PERMISSIONS[$src]) && in_array($tgt, AppConfig::IMPLIED_PERMISSIONS[$src], true)) {
            return $this->jsonError($response, 'implied.builtin_protected', 400);
        }
        try {
            $pdo = $this->pdo;
            $pdo->prepare("DELETE FROM " . AppConfig::TABLE_IMPLIED_RULES . " WHERE source_key = ? AND target_key = ?")->execute([$src, $tgt]);
            $this->opLog()->record($this->currentUser, 'delete_implied_rule', $src . ' → ' . $tgt, [], $this->clientIp($request), 'success');
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

        $response->getBody()->write((string) json_encode([
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
        $locale = $this->getLocale($request);
        $scopes = [];
        foreach (AppConfig::API_SCOPES as $key => $labelKey) {
            $scopes[] = ['key' => $key, 'label' => $this->i18n->trans($labelKey, [], $locale)];
        }
        $response->getBody()->write((string) json_encode(['scopes' => $scopes], JSON_UNESCAPED_UNICODE));
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
        $response->getBody()->write((string) json_encode(['tokens' => $tokens], JSON_UNESCAPED_UNICODE));
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

        $this->opLog()->record($this->currentUser, 'create_api_token', $body['name'] ?? '', ['id' => $created['id'], 'scopes' => $body['scopes'] ?? []], $this->clientIp($request), 'success');
        $response->getBody()->write((string) json_encode(['id' => $created['id'], 'token' => $created['token']], JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * PUT /api/admin/api_tokens/{id}/scopes — 编辑 scope（改库即时生效，明文 token 不变）
     *
     * 只改写元数据，不重新签发，因此调用方无需换 token；下一次请求即按新 scope 校验。
     *
     * @param array<string,string> $args
     */
    public function apiTokenUpdateScopes(Request $request, Response $response, array $args): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requireSuperAdmin($response)) {
            return $resp;
        }
        if ($this->apiTokenService === null) {
            return $this->jsonError($response, 'api_token.service_unavailable', 503);
        }
        $id   = (int)($args['id'] ?? 0);
        $body = $request->getParsedBody() ?? json_decode($request->getBody()->__toString(), true) ?? [];
        $payload = is_array($body) ? ($body['scopes'] ?? []) : [];

        $scopes = $this->apiTokenService->normalizeScopes($payload);

        try {
            $updated = $this->apiTokenService->updateScopes($id, $payload);
        } catch (\InvalidArgumentException $e) {
            return $this->jsonError($response, $scopes === [] ? 'api_token.scopes_required' : 'api_token.scopes_invalid', 400);
        } catch (\Exception $e) {
            return $this->jsonError($response, $this->__('build.query_failed') . ': ' . $e->getMessage(), 500);
        }
        if (!$updated) {
            return $this->jsonError($response, 'api_token.not_found', 404);
        }

        $this->opLog()->record($this->currentUser, 'update_api_token_scopes', (string)$id, ['scopes' => $scopes], $this->clientIp($request), 'success');
        $response->getBody()->write((string) json_encode(['ok' => true, 'id' => $id, 'scopes' => $scopes], JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * POST /api/admin/api_tokens/{id}/revoke — 撤销（禁用，保留记录）
     *
     * @param array<string,string> $args
     */
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
        $this->opLog()->record($this->currentUser, 'revoke_api_token', (string)($args['id'] ?? ''), [], $this->clientIp($request), $revoked ? 'success' : 'failure');
        $response->getBody()->write((string) json_encode(['ok' => $revoked], JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * DELETE /api/admin/api_tokens/{id} — 删除（硬删除记录）
     *
     * @param array<string,string> $args
     */
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
        $this->opLog()->record($this->currentUser, 'delete_api_token', (string)($args['id'] ?? ''), [], $this->clientIp($request), $deleted ? 'success' : 'failure');
        $response->getBody()->write((string) json_encode(['ok' => $deleted], JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json');
    }

    // ────────────────────────── 操作日志 ──────────────────────────

    /**
     * GET /api/admin/operation_logs — 后台操作审计日志列表（分页 + 筛选）
     * 权限：ci.operation-logs
     */
    public function operationLogList(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_OPERATION_LOGS)) {
            return $resp;
        }

        $params  = $request->getQueryParams();
        $filters = [
            'username'      => trim($params['username'] ?? ''),
            'action'        => trim($params['action'] ?? ''),
            'result'        => trim($params['result'] ?? ''),
            'operator_type' => trim($params['operator_type'] ?? ''),
            'date_from'     => trim($params['date_from'] ?? ''),
            'date_to'       => trim($params['date_to'] ?? ''),
        ];
        $page    = max(1, (int)($params['page'] ?? 1));
        $perPage = max(1, min(100, (int)($params['per_page'] ?? 20)));

        try {
            return $this->output($response, $this->opLog()->list($filters, $page, $perPage), $request);
        } catch (\Exception $e) {
            return $this->jsonError($response, $this->__('build.query_failed') . ': ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/admin/deploy_logs — CD 部署记录审计列表（分页 + 筛选，只读）
     * 权限：ci.deploy-logs
     */
    public function deployLogList(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_DEPLOY_LOGS)) {
            return $resp;
        }

        $params  = $request->getQueryParams();
        $filters = [
            'project'     => trim($params['project'] ?? ''),
            'status'      => trim($params['status'] ?? ''),
            'deploy_type' => trim($params['deploy_type'] ?? ''),
            'date_from'   => trim($params['date_from'] ?? ''),
            'date_to'     => trim($params['date_to'] ?? ''),
        ];
        $page    = max(1, (int)($params['page'] ?? 1));
        $perPage = max(1, min(100, (int)($params['per_page'] ?? 20)));

        try {
            return $this->output($response, $this->deployLog()->list($filters, $page, $perPage), $request);
        } catch (\Exception $e) {
            return $this->jsonError($response, $this->__('build.query_failed') . ': ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/admin/api_access_logs — API token 调用审计列表（分页 + 筛选，只读）
     * 权限：ci.api-logs。只返回 token 展示名等审计字段，绝无 token 原文/hash/body/query。
     */
    public function apiAccessLogList(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_API_LOGS)) {
            return $resp;
        }

        $params  = $request->getQueryParams();
        $result  = trim((string)($params['result'] ?? ''));
        $method  = strtoupper(trim((string)($params['method'] ?? '')));
        $filters = [
            'result'     => in_array($result, ['success', 'failure', 'denied'], true) ? $result : '',
            'method'     => in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true) ? $method : '',
            'token_name' => trim((string)($params['token_name'] ?? '')),
            'route'      => trim((string)($params['route'] ?? '')),
            'date_from'  => trim((string)($params['date_from'] ?? '')),
            'date_to'    => trim((string)($params['date_to'] ?? '')),
        ];
        $page    = max(1, (int)($params['page'] ?? 1));
        $perPage = max(1, min(100, (int)($params['per_page'] ?? 20)));

        try {
            return $this->output(
                $response,
                $this->apiAccessLogRepo()->list($filters, $page, $perPage),
                $request
            );
        } catch (\Exception $e) {
            return $this->jsonError($response, $this->__('build.query_failed') . ': ' . $e->getMessage(), 500);
        }
    }

    // ────────────────────────── 系统信息 ──────────────────────────

    /**
     * GET /api/admin/system_info — 数据库 schema 状态（驱动/版本/核心表存在性）
     * 权限：ci.system
     */
    public function systemInfo(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_SYSTEM)) {
            return $resp;
        }
        try {
            $status = \App\Service\Database::schemaStatus();
            $status['php_version'] = PHP_VERSION;
            return $this->output($response, $status, $request);
        } catch (\Exception $e) {
            return $this->jsonError($response, $this->__('build.query_failed') . ': ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/admin/platform_config — 各平台接入配置状态（只返回已配置布尔，不下发 URL/账号/凭证）
     * 权限：ci.platform-config
     */
    public function platformConfig(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_PLATFORM_CONFIG)) {
            return $resp;
        }
        try {
            $c = $this->config;
            $jenkins = $c->getJenkinsConfig();
            $gitlab  = $c->getGitlabConfig();
            $github  = $c->getGithubConfig();
            $gitee   = $c->getGiteeConfig();
            $gitea   = $c->getGiteaConfig();
            $harbor  = $c->getHarborConfig();


            $git = fn(array $cfg) => !empty($cfg['base_url']) && !empty($cfg['token']);

            return $this->output($response, [
                'platforms' => [
                    'jenkins'     => ['configured' => !empty($jenkins['url'])],
                    'gitlab'      => ['configured' => $git($gitlab)],
                    'github'      => ['configured' => $git($github)],
                    'gitee'       => ['configured' => $git($gitee)],
                    'gitea'       => ['configured' => $git($gitea)],
                    'harbor'      => ['configured' => !empty($harbor['url']) && ($c->getRegistryType() === 'registry' || !empty($harbor['password']))],
                ],
                // 平台级 tag 设置（清理/回填/日志关键字）
                'stale_tag_cleanup_enabled' => $this->appSettings->getStaleTagCleanupEnabled(),
                'backfill_tag_enabled'      => $this->appSettings->getBackfillTagEnabled(),
                'tag_log_keyword'           => $this->appSettings->getTagLogKeyword(),
                // API 调用审计日志设置（写入级别 / 保留天数 / 清理开关）
                'api_access_log_level'           => $this->appSettings->getApiAccessLogLevel(),
                'api_access_log_retain_days'     => $this->appSettings->getApiAccessLogRetainDays(),
                'api_access_log_cleanup_enabled' => $this->appSettings->getApiAccessLogCleanupEnabled(),
            ], $request);
        } catch (\Exception $e) {
            return $this->jsonError($response, $this->__('build.query_failed') . ': ' . $e->getMessage(), 500);
        }
    }

    /**
     * PUT /api/admin/platform_config — 更新平台级 tag 设置（清理开关 / 回填开关 / 日志关键字）
     * 权限：ci.platform-config（平台管理）。这些设置与构建模式无关，故从 build_mode 移出。
     * 仅更新请求体显式携带的键，避免互相覆盖。
     */
    public function updatePlatformConfig(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_PLATFORM_CONFIG)) {
            return $resp;
        }
        $body = $request->getParsedBody() ?? json_decode($request->getBody()->__toString(), true) ?? [];

        try {
            if (array_key_exists('stale_tag_cleanup_enabled', $body)) {
                $this->appSettings->setStaleTagCleanupEnabled(!empty($body['stale_tag_cleanup_enabled']));
            }
            if (array_key_exists('backfill_tag_enabled', $body)) {
                $this->appSettings->setBackfillTagEnabled(!empty($body['backfill_tag_enabled']));
            }
            if (array_key_exists('tag_log_keyword', $body)) {
                $this->appSettings->setTagLogKeyword(trim((string) ($body['tag_log_keyword'] ?? '')));
            }
            if (array_key_exists('api_access_log_level', $body)) {
                $this->appSettings->setApiAccessLogLevel((string)($body['api_access_log_level'] ?? ''));
            }
            if (array_key_exists('api_access_log_retain_days', $body)) {
                $this->appSettings->setApiAccessLogRetainDays((int)($body['api_access_log_retain_days'] ?? 0));
            }
            if (array_key_exists('api_access_log_cleanup_enabled', $body)) {
                $this->appSettings->setApiAccessLogCleanupEnabled(!empty($body['api_access_log_cleanup_enabled']));
            }
        } catch (\InvalidArgumentException $e) {
            return $this->jsonError($response, $e->getMessage(), 400);
        } catch (\Exception $e) {
            return $this->jsonError($response, $this->__('build.query_failed') . ': ' . $e->getMessage(), 500);
        }

        return $this->output($response, [
            'stale_tag_cleanup_enabled' => $this->appSettings->getStaleTagCleanupEnabled(),
            'backfill_tag_enabled'      => $this->appSettings->getBackfillTagEnabled(),
            'tag_log_keyword'           => $this->appSettings->getTagLogKeyword(),
            'api_access_log_level'           => $this->appSettings->getApiAccessLogLevel(),
            'api_access_log_retain_days'     => $this->appSettings->getApiAccessLogRetainDays(),
            'api_access_log_cleanup_enabled' => $this->appSettings->getApiAccessLogCleanupEnabled(),
        ], $request);
    }

    /**
     * 平台连接配置的归一化负载（GET 与 PUT 返回复用同一份）。
     * 明文 token/password 永不下发，只给 *_set 布尔；url/username 可下发（非机密）。
     *
     * @return array<string,mixed>
     */
    private function platformConnectionsPayload(): array
    {
        $resolved = $this->platformConnections->resolveConnections();
        $rows = [];
        foreach ($this->platformConnections->list() as $row) {
            $p = trim((string)($row['platform'] ?? ''));
            if ($p !== '') {
                $rows[$p] = $row;
            }
        }

        $gitCiPlatforms = $this->appSettings->getGitCiEnabledPlatforms();
        $jenkinsEnabled = $this->appSettings->getJenkinsEnabled();

        $platforms = [];
        foreach (['jenkins', 'gitlab', 'github', 'gitee', 'gitea', 'harbor'] as $p) {
            $cfg = $resolved[$p] ?? [];
            $row = $rows[$p] ?? null;

            if ($p === 'harbor') {
                $url         = (string)($cfg['url'] ?? '');
                $tokenSet    = false;
                $username    = (string)($cfg['username'] ?? '');
                $passwordSet = trim((string)($row['password'] ?? '')) !== '';
                $configured  = $url !== '' && ($this->appSettings->getRegistryType() === 'registry' || $passwordSet);
            } elseif ($p === 'jenkins') {
                $url         = (string)($cfg['url'] ?? '');
                $tokenSet    = trim((string)($row['token'] ?? '')) !== '';
                $username    = (string)($cfg['user'] ?? '');
                $passwordSet = false;
                $configured  = $url !== '';
            } else {
                $url         = (string)($cfg['base_url'] ?? '');
                $tokenSet    = trim((string)($row['token'] ?? '')) !== '';
                $username    = '';
                $passwordSet = false;
                $configured  = $url !== '' && $tokenSet;
            }

            $platforms[] = [
                'platform'     => $p,
                'url'          => $url,
                'token_set'    => $tokenSet,
                'username'     => $username,
                'password_set' => $passwordSet,
                'description'  => (string)($row['description'] ?? ''),
                'registry_type' => $p === 'harbor' ? $this->appSettings->getRegistryType() : null,
                'configured'   => $configured,
                'hosting'      => in_array($p, AppConfig::GIT_PLATFORMS, true) ? HostingType::classify($url) : null,
                'ci_capable'   => $p === 'jenkins' || isset(AppConfig::GIT_PLATFORM_CI_CAPABILITIES[$p]),
                'ci_enabled'   => $p === 'jenkins' ? $jenkinsEnabled : in_array($p, $gitCiPlatforms, true),
            ];
        }

        return ['platforms' => $platforms];
    }

    /**
     * GET /api/admin/platform_connections — 各平台连接配置（url/username 明文，token/password 只回 *_set）
     * 权限：ci.platform-config。
     */
    public function platformConnections(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_PLATFORM_CONFIG)) {
            return $resp;
        }
        try {
            return $this->output($response, $this->platformConnectionsPayload(), $request);
        } catch (\Exception $e) {
            return $this->jsonError($response, $this->__('build.query_failed') . ': ' . $e->getMessage(), 500);
        }
    }

    /**
     * PUT /api/admin/platform_connections — 更新各平台连接配置（DB 唯一权威，无 env 兜底）
     * 权限：ci.platform-config。只处理请求体显式携带的键；token/password 留空即保持原值。
     * delete:true 删除行（回到未配置）；url 与凭证均空也删除行；有凭证但无 URL 拒绝（400）。
     */
    public function updatePlatformConnections(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_PLATFORM_CONFIG)) {
            return $resp;
        }
        $body = $request->getParsedBody() ?? json_decode($request->getBody()->__toString(), true) ?? [];

        $valid = ['jenkins', 'gitlab', 'github', 'gitee', 'gitea', 'harbor'];
        $gitCiPlatforms = $this->appSettings->getGitCiEnabledPlatforms();
        $gitCiTouched = false;
        $jenkinsEnabled = $this->appSettings->getJenkinsEnabled();
        $jenkinsTouched = false;
        $removedProviders = [];
        $this->pdo->beginTransaction();
        try {
            if (isset($body['platforms']) && is_array($body['platforms'])) {
                foreach ($body['platforms'] as $p) {
                    if (!is_array($p)) {
                        continue;
                    }
                    $platform = trim((string)($p['platform'] ?? ''));
                    if (!in_array($platform, $valid, true)) {
                        continue;
                    }

                    $switchedToRegistry = false;
                    if ($platform === 'harbor' && array_key_exists('registry_type', $p)) {
                        $newType = (string)($p['registry_type'] ?? 'harbor');
                        if ($newType === 'registry' && $this->appSettings->getRegistryType() !== 'registry') {
                            $switchedToRegistry = true;
                        }
                        $this->appSettings->setRegistryType($newType);
                    }

                    if ($platform === AppConfig::PROVIDER_JENKINS) {
                        if (!empty($p['delete'])) {
                            if ($jenkinsEnabled) {
                                $jenkinsEnabled = false;
                                $jenkinsTouched = true;
                                $removedProviders[] = AppConfig::PROVIDER_JENKINS;
                            }
                        } elseif (array_key_exists('ci_enabled', $p)) {
                            $ciOn = !empty($p['ci_enabled']);
                            if ($ciOn !== $jenkinsEnabled) {
                                $jenkinsEnabled = $ciOn;
                                $jenkinsTouched = true;
                                if (!$ciOn) {
                                    $removedProviders[] = AppConfig::PROVIDER_JENKINS;
                                }
                            }
                        }
                    }

                    if (isset(AppConfig::GIT_PLATFORM_CI_CAPABILITIES[$platform])) {
                        $ciProvider = AppConfig::GIT_PLATFORM_CI_CAPABILITIES[$platform];
                        if (!empty($p['delete'])) {
                            if (in_array($platform, $gitCiPlatforms, true)) {
                                $gitCiPlatforms = array_values(array_diff($gitCiPlatforms, [$platform]));
                                $gitCiTouched = true;
                                $removedProviders[] = $ciProvider;
                            }
                        } elseif (array_key_exists('ci_enabled', $p)) {
                            $ciOn = !empty($p['ci_enabled']);
                            if ($ciOn && !in_array($platform, $gitCiPlatforms, true)) {
                                $gitCiPlatforms[] = $platform;
                                $gitCiTouched = true;
                            } elseif (!$ciOn && in_array($platform, $gitCiPlatforms, true)) {
                                $gitCiPlatforms = array_values(array_diff($gitCiPlatforms, [$platform]));
                                $gitCiTouched = true;
                                $removedProviders[] = $ciProvider;
                            }
                        }
                    }
                    if (!empty($p['delete'])) {
                        $this->platformConnections->delete($platform);
                        continue;
                    }
                    $existing = $this->platformConnections->find($platform);
                    $url      = trim((string)($p['url'] ?? ''));
                    $username = trim((string)($p['username'] ?? ''));
                    $token    = (string)($existing['token'] ?? '');
                    $password = (string)($existing['password'] ?? '');
                    if (array_key_exists('token', $p)) {
                        $t = trim((string)($p['token'] ?? ''));
                        if ($t !== '') {
                            $token = $t;
                        }
                    }
                    if (array_key_exists('password', $p)) {
                        $pw = (string)($p['password'] ?? '');
                        if (trim($pw) !== '') {
                            $password = $pw;
                        }
                    }

                    // 注意：不再因切换 registry_type 而清空凭据。
                    // 镜像仓库连接（harbor 行）在 harbor / registry 两种模式下复用同一个连接，
                    // 切换类型只改变访问协议（Harbor v2 API 或 registry:2），不应丢弃凭据，
                    // 否则每次切回都会因凭据丢失而 401。
                    if ($url === '' && $token === '' && $password === '') {
                        $this->platformConnections->delete($platform);
                        continue;
                    }
                    if ($url === '') {
                        throw new \InvalidArgumentException('平台连接 [' . $platform . '] 填写了凭证但 URL 为空');
                    }
                    $description = (string)($existing['description'] ?? '');
                    if (array_key_exists('description', $p)) {
                        $description = trim((string)($p['description'] ?? ''));
                    }
                    $this->platformConnections->save($platform, [
                        'url'         => $url,
                        'token'       => $token,
                        'username'    => $username,
                        'password'    => $password,
                        'description' => $description,
                    ]);
                }
            }
            if ($gitCiTouched) {
                $this->appSettings->setGitCiEnabledPlatforms($gitCiPlatforms);
            }
            if ($jenkinsTouched) {
                $this->appSettings->setJenkinsEnabled($jenkinsEnabled);
            }


            if ($removedProviders !== []) {
                $removedProviders = array_values(array_unique($removedProviders));
                $maps = $this->mapping->allMaps();
                $changed = false;
                foreach ($maps as &$m) {
                    $bp = (string) ($m['build_provider'] ?? '');
                    if ($bp === '') {
                        continue;
                    }
                    if (in_array($bp, $removedProviders, true) && ($m['status'] ?? AppConfig::STATUS_ACTIVE) !== AppConfig::STATUS_PENDING) {
                        $m['status'] = AppConfig::STATUS_PENDING;
                        $changed = true;
                    }
                }
                if ($changed) {
                    $this->mapping->saveMaps($maps);
                    $this->invalidateTopologyCache();
                }
            }
            $this->opLog()->record($this->currentUser, 'platform_connections_update', '', [], $this->clientIp($request), 'success');
            $this->pdo->commit();
        } catch (\InvalidArgumentException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return $this->jsonError($response, $e->getMessage(), 400);
        } catch (\Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return $this->jsonError($response, $this->__('build.query_failed') . ': ' . $e->getMessage(), 500);
        }

        return $this->output($response, $this->platformConnectionsPayload(), $request);
    }

    /**
     * OIDC 配置的归一化负载（GET 与 PUT 返回复用同一份）。
     * 明文 secret 永不下发，只给 secret_set 布尔；source 标注 database|env。
     *
     * @return array<string,mixed>
     */
    private function oidcConfigPayload(): array
    {
        $dbIssuer = $this->appSettings->getOidcIssuer();
        $issuer   = $dbIssuer ?? '';
        $issuerSource = $dbIssuer !== null ? 'database' : '';

        $dbTtl = $this->appSettings->getOidcIdTokenTtl();
        $ttl   = $dbTtl ?? AppConfig::DEFAULT_OIDC_ID_TOKEN_TTL;
        $ttlSource = $dbTtl !== null ? 'database' : '';


        $clients = [];
        foreach ($this->oauthClients->list() as $row) {
            $id = trim((string)($row['client_id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $clients[] = [
                'client_id'    => $id,
                'system'       => (string)($row['system'] ?? ''),
                'enabled'      => (int)($row['enabled'] ?? 1) === 1,
                'description'  => (string)($row['description'] ?? ''),
                'redirect_uri' => (string)($row['redirect_uri'] ?? ''),
                'secret_set'   => trim((string)($row['secret'] ?? '')) !== '',
                'source'       => 'database',
            ];
        }

        return [
            'issuer'              => $issuer,
            'issuer_source'       => $issuerSource,
            'id_token_ttl'        => $ttl,
            'id_token_ttl_source' => $ttlSource,
            'clients'             => $clients,
        ];
    }

    /**
     * GET /api/admin/oidc_config — OIDC/OAuth 配置（issuer + id_token_ttl + 客户端白名单）
     * 权限：ci.platform-config。明文 secret 永不下发，只回 secret_set。
     */
    public function oidcConfig(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_PLATFORM_CONFIG)) {
            return $resp;
        }
        try {
            return $this->output($response, $this->oidcConfigPayload(), $request);
        } catch (\Exception $e) {
            return $this->jsonError($response, $this->__('build.query_failed') . ': ' . $e->getMessage(), 500);
        }
    }

    /**
     * PUT /api/admin/oidc_config — 更新 OIDC/OAuth 配置
     * 权限：ci.platform-config。只处理请求体显式携带的键；secret/redirect_uri 留空即保持原值。
     * clients 数组即「要 DB 管理的客户端集合」：delete:true 删除行（该客户端不再可用）；其余 upsert。
     * enabled 客户端最终必须同时有非空 secret 与 redirect_uri，否则 400（避免 fail-closed 静默丢弃）。
     */
    public function updateOidcConfig(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_PLATFORM_CONFIG)) {
            return $resp;
        }
        $body = $request->getParsedBody() ?? json_decode($request->getBody()->__toString(), true) ?? [];

        $this->pdo->beginTransaction();
        try {
            if (array_key_exists('issuer', $body)) {
                $this->appSettings->setOidcIssuer((string)($body['issuer'] ?? ''));
            }
            if (array_key_exists('id_token_ttl', $body)) {
                $this->appSettings->setOidcIdTokenTtl((int)($body['id_token_ttl'] ?? 0));
            }
            if (isset($body['clients']) && is_array($body['clients'])) {
                foreach ($body['clients'] as $c) {
                    if (!is_array($c)) {
                        continue;
                    }
                    $id = trim((string)($c['client_id'] ?? ''));
                    if ($id === '') {
                        continue;
                    }
                    if (!empty($c['delete'])) {
                        $this->oauthClients->delete($id);
                        continue;
                    }
                    $existing = $this->oauthClients->find($id);
                    $secret   = (string)($existing['secret'] ?? '');
                    $redirect = (string)($existing['redirect_uri'] ?? '');
                    if (array_key_exists('secret', $c)) {
                        $s = trim((string)($c['secret'] ?? ''));
                        if ($s !== '') {
                            $secret = $s;
                        }
                    }
                    if (array_key_exists('redirect_uri', $c)) {
                        $r = trim((string)($c['redirect_uri'] ?? ''));
                        if ($r !== '') {
                            $redirect = $r;
                        }
                    }
                    $enabled = !empty($c['enabled']);
                    if ($enabled && (trim($secret) === '' || trim($redirect) === '')) {
                        throw new \InvalidArgumentException(
                            'OIDC 客户端 [' . $id . '] 启用时必须同时填写 secret 与 redirect_uri'
                        );
                    }
                    $description = (string)($existing['description'] ?? '');
                    if (array_key_exists('description', $c)) {
                        $description = trim((string)($c['description'] ?? ''));
                    }
                    $this->oauthClients->save($id, [
                        'secret'       => $secret,
                        'redirect_uri' => $redirect,
                        'system'       => (string)($c['system'] ?? ''),
                        'enabled'      => $enabled,
                        'description'  => $description,
                    ]);
                }
            }
            $this->opLog()->record($this->currentUser, 'oidc_config_update', '', [], $this->clientIp($request), 'success');
            $this->pdo->commit();
        } catch (\InvalidArgumentException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return $this->jsonError($response, $e->getMessage(), 400);
        } catch (\Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return $this->jsonError($response, $this->__('build.query_failed') . ': ' . $e->getMessage(), 500);
        }

        return $this->output($response, $this->oidcConfigPayload(), $request);
    }

    /**
     * 手动触发 tag 定时任务的前置校验（开关 + 镜像仓库），与两个 CLI 脚本的安全不变量保持一致。
     * 返回 null 表示可执行；否则返回应直接下发的错误响应。
     * 权限由调用方先行校验（ci.platform-config）。
     */
    private function guardTagJob(Response $response, bool $enabled, string $disabledKey): ?Response
    {
        if (!$enabled) {
            return $this->jsonError($response, $disabledKey, 409);
        }
        $registry = $this->config->getHarborConfig();
        if (empty($registry['url']) || $this->registryProvider === null) {
            return $this->jsonError($response, 'build.registry_unconfigured', 409);
        }
        return null;
    }

    /**
     * POST /api/admin/tag_cleanup — 后台手动立即执行一次清理过期 tag
     *（等价于跑一遍 cli/cleanup-pipeline-tags.php，供无 cron / 无 CLI 的部署主动触发）。
     * 权限：ci.platform-config（与启用清理过期 tag 记录开关同权限）。
     * 与 CLI 一致的校验：开关未开启返回 409；镜像仓库未配置（url 为空或提供方不可用）返回 409；
     * 镜像仓库（Harbor 或 registry:2）不可达以及 registry_repository 或 tag 为空的行一律跳过，不删除。幂等，可重复执行。
     */
    public function runTagCleanup(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_PLATFORM_CONFIG)) {
            return $resp;
        }
        if ($resp = $this->guardTagJob($response, $this->appSettings->getStaleTagCleanupEnabled(), 'build.tag_cleanup_disabled')) {
            return $resp;
        }

        @set_time_limit(0);
        try {
            $service = new \App\Service\PipelineTagService($this->pdo, $this->registryProvider);
            $stat    = $service->cleanupStaleTags();
            $this->opLog()->record($this->currentUser, 'cleanup_pipeline_tags', '', $stat, $this->clientIp($request), 'success');
            return $this->output($response, $stat + ['success' => true], $request);
        } catch (\Throwable $e) {
            if ($this->currentUser !== '') {
                try {
                    $this->opLog()->record($this->currentUser, 'cleanup_pipeline_tags', '', ['error' => $e->getMessage()], $this->clientIp($request), 'failure');
                } catch (\Throwable $ignored) {
                }
            }
            return $this->jsonError($response, $this->__('build.stale_tag_cleanup_failed') . ': ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/admin/tag_backfill — 后台手动立即执行一次镜像 Tag 日志回填
     *（等价于跑一遍 cli/backfill-pipeline-tags.php，供无 cron / 无 CLI 的部署主动触发）。
     * 权限：ci.platform-config（与启用镜像 Tag 日志回填开关同权限）。
     * 与 CLI 一致的校验：开关未开启返回 409；镜像仓库未配置（url 为空或提供方不可用）返回 409；
     * 仅当镜像仓库（Harbor 或 registry:2）确认该 tag 存在、且该 (provider,project_id,pipeline_iid) 尚无
     * canonical tag 时才写入，不覆盖 scan-sync 的结果。幂等，可重复执行。
     */
    public function runTagBackfill(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_PLATFORM_CONFIG)) {
            return $resp;
        }
        if ($resp = $this->guardTagJob($response, $this->appSettings->getBackfillTagEnabled(), 'build.tag_backfill_disabled')) {
            return $resp;
        }
        @set_time_limit(0);
        try {
            $service = new \App\Service\PipelineTagService($this->pdo, $this->registryProvider);
            $stat    = $service->backfillTagsFromBuildLog();
            $this->opLog()->record($this->currentUser, 'backfill_pipeline_tags', '', $stat, $this->clientIp($request), 'success');
            return $this->output($response, $stat + ['success' => true], $request);
        } catch (\Throwable $e) {
            if ($this->currentUser !== '') {
                try {
                    $this->opLog()->record($this->currentUser, 'backfill_pipeline_tags', '', ['error' => $e->getMessage()], $this->clientIp($request), 'failure');
                } catch (\Throwable $ignored) {
                }
            }
            return $this->jsonError($response, $this->__('build.tag_backfill_failed') . ': ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/admin/migrate — 手动同步库结构（建缺失表 + 补列 + 索引 + 种子 + 标记 schema 当前）
     *
     * 与 `cli/migrate.php` 等价的入口，区别是本入口用应用自身的数据库账号执行 DDL：
     * 该账号缺 CREATE/ALTER 权限时（手动建库模式的最小权限部署很常见）返回 409 并提示改用 CLI，
     * 而不是返回 500。
     * 权限：仅 super_admin（DDL 敏感操作）
     */
    public function migrateSchema(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requireSuperAdmin($response)) {
            return $resp;
        }
        try {
            $status = \App\Service\Database::migrateNow();
            $this->opLog()->record($this->currentUser, 'migrate_schema', '', ['driver' => $status['driver'], 'app_version' => $status['app_version']], $this->clientIp($request), 'success');
            return $this->output($response, ['success' => true, 'status' => $status], $request);
        } catch (\Exception $e) {
            $driverCode = $e instanceof \PDOException ? (int) ($e->errorInfo[1] ?? 0) : 0;
            $kind       = \App\Service\Database::classifyMigrationError(
                (string) $e->getMessage(),
                (string) $e->getCode(),
                $driverCode
            );
            $this->recordSchemaSyncFailure($request, $e->getMessage(), $kind);

            $tables = \App\Service\Database::lastTableBuildLog();
            if ($kind === 'ddl_denied') {
                return $this->jsonError(
                    $response,
                    $this->__('sys.migrate_denied') . ' ' . $this->__('sys.migrate_denied_hint')
                        . ' [' . mb_substr($e->getMessage(), 0, 300) . ']',
                    409,
                    ['tables' => $tables]
                );
            }
            return $this->jsonError($response, $this->__('sys.migrate_failed') . ': ' . $e->getMessage(), 500, ['tables' => $tables]);
        }
    }

    private function recordSchemaSyncFailure(Request $request, string $message, string $kind): void
    {
        if ($this->currentUser === '') {
            return;
        }
        try {
            $this->opLog()->record(
                $this->currentUser,
                'migrate_schema',
                '',
                ['error' => $message, 'kind' => $kind],
                $this->clientIp($request),
                'failure'
            );
        } catch (\Throwable $ignored) {
        }
    }

    /**
     * POST /api/admin/backup — 手动执行数据库备份，仅备份、不提供恢复。
     * 支持 sqlite / mysql 两种驱动，产物为 zip 归档，命名 devops-glue_<driver>_<datetime>.zip；
     * 启用 CD（共享库存在 cd_* 表）时额外产出一份 devops-cd_<driver>_<datetime>.zip。
     * 权限：仅 super_admin（与迁移同级，属敏感运维操作）。
     *
     * 备份目录：Docker 内 BACKUP_DIR=/data/backups（compose 卷映射宿主 ./data/backups）；
     * 非 Docker / 未设 BACKUP_DIR 时落仓库根 backups/。目录不存在时自动创建并滚动留存最近 10 份。
     * 备份逻辑由 DataBackupService 提供（bin/ 不随镜像发布，Web 侧不依赖 CLI 工具），
     * 完成后写入操作日志。
     */
    public function backupDatabase(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requireSuperAdmin($response)) {
            return $resp;
        }
        try {
            $service       = new \App\Service\DataBackupService($this->pdo);
            $files         = $service->backup(10);
            $warningKey    = $service->lastWarningKey();
            $warningParams = $service->lastWarningParams();

            $detail = ['driver' => $service->driver(), 'files' => count($files)];
            if ($warningKey !== '') {
                $detail['warning'] = $this->i18n->trans($warningKey, $warningParams);
            }
            $this->opLog()->record(
                $this->currentUser,
                'backup_database',
                implode(', ', array_column($files, 'name')),
                $detail,
                $this->clientIp($request),
                'success'
            );

            $out = [];
            foreach ($files as $f) {
                $out[] = [
                    'name'   => $f['name'],
                    'mode'   => $f['mode'],
                    'tables' => count($f['counts']),
                    'rows'   => array_sum($f['counts']),
                ];
            }

            return $this->output($response, [
                'success'  => true,
                'files'    => $out,

                'warnings' => $warningKey !== '' ? [['key' => $warningKey, 'params' => $warningParams]] : [],
            ], $request);
        } catch (\Throwable $e) {
            if ($this->currentUser !== '') {
                try {
                    $this->opLog()->record($this->currentUser, 'backup_database', '', ['error' => $e->getMessage()], $this->clientIp($request), 'failure');
                } catch (\Throwable $ignored) {
                }
            }
            return $this->jsonError($response, $this->__('sys.backup_failed') . ': ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/admin/backups — 列出已生成的数据库备份文件（供数据管理面板渲染）。
     * 权限：仅 super_admin（与备份同级）。
     */
    public function listBackups(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requireSuperAdmin($response)) {
            return $resp;
        }
        try {
            $service = new \App\Service\DataBackupService($this->pdo);
            return $this->output($response, ['files' => $service->listBackups()], $request);
        } catch (\Throwable $e) {
            return $this->jsonError($response, $this->__('sys.backup_failed') . ': ' . $e->getMessage(), 500);
        }
    }

    // ────────────────────────── helpers ──────────────────────────



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
}
