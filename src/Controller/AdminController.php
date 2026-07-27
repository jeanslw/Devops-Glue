<?php
namespace App\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Config\AppConfig;
use App\Service\AutoDiscover;

class AdminController extends BaseController
{
    private AppConfig $config;
    private ?AutoDiscover $autoDiscover;
    private string $currentUser = '';
    private string $currentRole = 'admin';

    public function __construct(AppConfig $config, ?AutoDiscover $autoDiscover = null)
    {
        $this->config       = $config;
        $this->autoDiscover = $autoDiscover;
    }

    /** POST /api/admin/discover — 自动扫描并保存未入库的项目 */
    public function discover(Request $request, Response $response): Response
    {
        if ($err = $this->authCheck($request, $response)) return $err;
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
        if ($err = $this->authCheck($request, $response)) return $err;

        $params = array_merge(
            $request->getQueryParams(),
            $request->getParsedBody() ?? json_decode($request->getBody()->__toString(), true) ?? []
        );
        $project   = trim($params['project'] ?? '');
        $checkType = trim($params['check_type'] ?? '');
        $state     = trim($params['state'] ?? '');
        $exclude   = trim($params['exclude'] ?? '');
        $page      = max(1, (int)($params['page'] ?? 1));
        $perPage   = max(1, min(100, (int)($params['per_page'] ?? 20)));

        try {
            $pdo = \App\Service\Database::getPdo();

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
            $countStmt = $pdo->prepare("SELECT count(*) FROM ci_security_checks {$whereClause}");
            $countStmt->execute($bind);
            $total = (int)$countStmt->fetchColumn();

            $totalPages = max(1, (int)ceil($total / $perPage));
            $page = min($page, $totalPages);
            $offset = ($page - 1) * $perPage;

            // 分页查询
            $rows = $pdo->prepare(
                "SELECT * FROM ci_security_checks {$whereClause} ORDER BY created_at DESC LIMIT {$perPage} OFFSET {$offset}"
            );
            $rows->execute($bind);
            $checks = $rows->fetchAll();

            // 可选筛选值
            $types = $pdo->query("SELECT DISTINCT check_type FROM ci_security_checks ORDER BY check_type")->fetchAll(\PDO::FETCH_COLUMN);

            return $this->output($response, [
                'checks'      => $checks,
                'total'       => $total,
                'page'        => $page,
                'per_page'    => $perPage,
                'total_pages' => $totalPages,
                'filter_opts' => [
                    'check_types' => $types,
                    'states'      => ['success', 'failed', 'pending', 'error'],
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
        $user = trim($body['user'] ?? '');
        $pass = $body['password'] ?? '';
        if ($user === '' || $pass === '') {
            return $this->jsonError($response, 'auth.wrong_credentials', 401);
        }

        $authed = false;

        $dbUser = null;
        $loginRole = 'admin'; // .env 降级默认为 admin

        // 优先查数据库
        try {
            $pdo = \App\Service\Database::getPdo();
            $row = $pdo->prepare("SELECT password_hash, systems, role FROM admin_users WHERE username = ?");
            $row->execute([$user]);
            $dbUser = $row->fetch();
            if ($dbUser && password_verify($pass, $dbUser['password_hash'])) {
                // 根据当前实例类型校验用户 systems 权限
                $systemType = $this->config->getSystemType();
                $systems = $dbUser['systems'] ?? '';
                $allowed = false;
                if ($systemType === 'ci') {
                    $allowed = strpos($systems, 'ci') !== false;
                } elseif ($systemType === 'cd') {
                    $allowed = strpos($systems, 'cd') !== false;
                } else { // both
                    $allowed = strpos($systems, 'ci') !== false || strpos($systems, 'cd') !== false;
                }
                if (!$allowed) {
                    $errKey = $systemType === 'ci' ? 'auth.no_ci_access'
                        : ($systemType === 'cd' ? 'auth.no_cd_access' : 'auth.no_system_access');
                    return $this->jsonError($response, $errKey, 403);
                }
                $loginRole = $dbUser['role'];
                $authed = true;
            }
        } catch (\Exception $e) {
            // DB 不可用时降级到 .env
        }

        // 降级：.env 验证
        if (!$authed) {
            $cred = $this->config->getAdminCredentials();
            if ($user === $cred['user'] && $pass === $cred['password'] && $pass !== '') {
                $authed = true;
                $loginRole = 'admin';
            }
        }

        if ($authed) {
            $token = bin2hex(random_bytes(32));
            // 持久化 token，24h 过期
            try {
                $pdo = \App\Service\Database::getPdo();
                $sql = \App\Service\Database::sqlUpsert('cache', 'cache_key, value, expires_at', '?, ?, ?');
                $pdo->prepare($sql)->execute(['admin_token_' . $token, $user . '|' . $loginRole, time() + 86400]);
            } catch (\Exception $e) {
                // cache 不可用时仍返回 token（降级）
            }
            return $this->output($response, ['token' => $token, 'role' => $loginRole], $request);
        }
        return $this->jsonError($response, 'auth.wrong_credentials', 401);
    }

    /** PUT /api/admin/password — 修改密码 */
    public function changePassword(Request $request, Response $response): Response
    {
        if ($err = $this->authCheck($request, $response)) return $err;

        $body = $request->getParsedBody() ?? json_decode($request->getBody()->__toString(), true) ?? [];
        $oldPass = $body['old_password'] ?? '';
        $newPass = $body['new_password'] ?? '';

        if (strlen($newPass) < 6) {
            return $this->jsonError($response, 'auth.new_password_short', 400);
        }

        try {
            $pdo = \App\Service\Database::getPdo();
            $cred = $this->config->getAdminCredentials();
            $username = $cred['user'];

            // 验证旧密码
            $row = $pdo->prepare("SELECT password_hash FROM admin_users WHERE username = ?");
            $row->execute([$username]);
            $dbUser = $row->fetch();

            $oldOk = false;
            if ($dbUser) {
                $oldOk = password_verify($oldPass, $dbUser['password_hash']);
            }
            // 降级：用 .env 密码验证
            if (!$oldOk && $oldPass === $cred['password']) {
                $oldOk = true;
            }
            if (!$oldOk) {
                return $this->jsonError($response, 'auth.old_password_wrong', 403);
            }

            // 更新密码
            $hash = password_hash($newPass, PASSWORD_BCRYPT);
            $sql = \App\Service\Database::sqlUpsert('admin_users', 'username, password_hash, updated_at', '?, ?, ' . \App\Service\Database::sqlNow());
            \App\Service\Database::getPdo()->prepare($sql)->execute([$username, $hash]);

            // 密码变更后清除所有旧 token
            try {
                \App\Service\Database::getPdo()->exec("DELETE FROM cache WHERE cache_key LIKE 'admin_token_%'");
            } catch (\Exception $e) {}

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
        if ($err = $this->authCheck($request, $response)) return $err;

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
            $filtered = array_filter($filtered, fn($m) => ($m['build_provider'] ?? 'jenkins') === $provider);
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
        if ($err = $this->authCheck($request, $response)) return $err;

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
        if ($err = $this->authCheck($request, $response)) return $err;

        $body = $request->getParsedBody() ?? json_decode($request->getBody()->__toString(), true) ?? [];

        $oldName = trim($body['_original_job_name'] ?? '');
        if ($oldName === '') {
            return $this->jsonError($response, 'map.original_name_required', 400);
        }

        $maps = $this->config->getJobGitMap();
        $found = false;
        foreach ($maps as $i => $item) {
            if (($item['job_name'] ?? '') === $oldName) {
                $maps[$i] = $this->buildEntry($body);
                $found = true;
                break;
            }
        }

        if (!$found) {
            return $this->jsonError($response, $this->__('map.not_found', ['{name}' => $oldName]), 404);
        }

        $this->config->saveJobGitMap($maps);
        $this->invalidateTopologyCache();
        return $this->output($response, ['success' => true, 'entry' => $maps[$i] ?? null], $request);
    }

    /**
     * DELETE /api/admin/job_git_map?job_name=xxx — 删除一条映射
     */
    public function jobGitMapDelete(Request $request, Response $response): Response
    {
        if ($err = $this->authCheck($request, $response)) return $err;

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
        if ($err = $this->authCheck($request, $response)) return $err;
        $versions = $this->config->getPlatformApiVersionsWithSource();

        // 附加配置状态
        $gitPlatforms = $this->config->getGitPlatformsConfig();
        $configuredGit = array_column($gitPlatforms, 'name');

        foreach ($versions as $name => &$info) {
            $info['configured'] = in_array($name, $configuredGit) || $name === 'harbor';
        }
        unset($info);

        return $this->output($response, ['versions' => $versions], $request);
    }

    /**
     * PUT /api/admin/platform_versions — 更新平台 API 版本
     */
    public function platformVersionsUpdate(Request $request, Response $response): Response
    {
        if ($err = $this->authCheck($request, $response)) return $err;
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
        if ($err = $this->authCheck($request, $response)) return $err;
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
        ], $request);
    }

    /**
     * PUT /api/admin/build_mode — 更新构建模式
     */
    public function updateBuildMode(Request $request, Response $response): Response
    {
        if ($err = $this->authCheck($request, $response)) return $err;
        $body = $request->getParsedBody() ?? json_decode($request->getBody()->__toString(), true) ?? [];
        $mode = trim($body['mode'] ?? '');
        if (!in_array($mode, ['jenkins', 'gitlab_ci', 'both'])) {
            return $this->jsonError($response, 'build.mode_required', 400);
        }

        // 拒绝不可用的 Provider
        $jenkinsCfg = $this->config->getJenkinsConfig();
        $hasJenkins = !empty($jenkinsCfg['url']);
        $hasGitlab = $this->config->isPlatformConfigured('gitlab');
        $glCfg = $hasGitlab ? $this->config->getGitlabConfig() : [];
        $hasGitlabCi = $hasGitlab && !empty($glCfg['base_url']) && !empty($glCfg['token']);

        if (($mode === 'jenkins' || $mode === 'both') && !$hasJenkins) {
            return $this->jsonError($response, 'build.jenkins_unavail', 400);
        }
        if (($mode === 'gitlab_ci' || $mode === 'both') && !$hasGitlabCi) {
            return $this->jsonError($response, 'build.gitlab_ci_unavail', 400);
        }

        try {
            $this->config->setBuildMode($mode);

            // 切到单 provider 模式时，将对方 provider 的 active 记录降为 pending
            // 杜绝切模式后对方记录仍处于启用状态，避免意外参与构建或干扰自动发现
            if ($mode === 'jenkins' || $mode === 'gitlab_ci') {
                $otherProvider = ($mode === 'jenkins') ? 'gitlab_ci' : 'jenkins';
                $maps = $this->config->getJobGitMap();
                $changed = false;
                foreach ($maps as &$m) {
                    if (($m['build_provider'] ?? 'jenkins') === $otherProvider && ($m['status'] ?? 'active') !== 'pending') {
                        $m['status'] = 'pending';
                        $changed = true;
                    }
                }
                if ($changed) {
                    $this->config->saveJobGitMap($maps);
                    $this->invalidateTopologyCache();
                }
            }

            return $this->output($response, ['success' => true, 'mode' => $mode], $request);
        } catch (\Exception $e) {
            return $this->jsonError($response, $this->__('build.save_failed') . ': ' . $e->getMessage(), 500);
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
        if ($err = $this->authCheck($request, $response)) return $err;

        try {
            $pdo = \App\Service\Database::getPdo();
            if ($this->currentRole === 'admin') {
                $rows = $pdo->query("SELECT username, role, systems, updated_at FROM admin_users ORDER BY username")->fetchAll();
            } else {
                $rows = $pdo->query("SELECT username, role, systems, updated_at FROM admin_users WHERE role != 'admin' ORDER BY username")->fetchAll();
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
        if ($err = $this->authCheck($request, $response)) return $err;

        $body = $request->getParsedBody() ?? json_decode($request->getBody()->__toString(), true) ?? [];
        $username = trim($body['username'] ?? '');
        $password = $body['password'] ?? '';
        $role     = trim($body['role'] ?? 'deployer');
        $systems  = trim($body['systems'] ?? 'cd');

        if ($username === '' || strlen($password) < 6) {
            return $this->jsonError($response, 'auth.new_password_short', 400);
        }

        // 只有 admin 能创建 admin 角色
        if ($role === 'admin') {
            if ($this->currentRole !== 'admin') {
                return $this->jsonError($response, 'user.cannot_create_admin', 403);
            }
            // admin 创建的 admin 账号 system 至少含 cd
            if (strpos($systems, 'cd') === false) {
                $systems = $systems === '' ? 'cd' : $systems . ',cd';
            }
        }

        // 非 admin 创建的账号不能包含 ci
        if ($this->currentRole !== 'admin' && strpos($systems, 'ci') !== false) {
            return $this->jsonError($response, 'user.cd_no_ci_access', 403);
        }

        try {
            $pdo = \App\Service\Database::getPdo();
            // 检查重名
            $exists = $pdo->prepare("SELECT 1 FROM admin_users WHERE username = ?");
            $exists->execute([$username]);
            if ($exists->fetch()) {
                return $this->jsonError($response, 'user.username_exists', 409);
            }

            $hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("INSERT INTO admin_users (username, password_hash, role, systems, updated_at) VALUES (?, ?, ?, ?, " . \App\Service\Database::sqlNow() . ")");
            $stmt->execute([$username, $hash, $role, $systems]);

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
        if ($err = $this->authCheck($request, $response)) return $err;

        $targetUser = $args['username'] ?? '';
        if ($targetUser === '') {
            return $this->jsonError($response, 'user.invalid_username', 400);
        }

        $body = $request->getParsedBody() ?? json_decode($request->getBody()->__toString(), true) ?? [];
        $password = $body['password'] ?? null;
        $role     = isset($body['role']) ? trim($body['role']) : null;

        try {
            $pdo = \App\Service\Database::getPdo();

            // 查目标用户信息
            $row = $pdo->prepare("SELECT username, role FROM admin_users WHERE username = ?");
            $row->execute([$targetUser]);
            $target = $row->fetch();
            if (!$target) {
                return $this->jsonError($response, 'user.not_found', 404);
            }

            // 不能修改 admin
            if ($target['role'] === 'admin') {
                return $this->jsonError($response, 'user.cannot_edit_admin', 403);
            }

            $fields = [];
            $bind   = [];

            if ($role !== null) {
                if ($role === 'admin') {
                    return $this->jsonError($response, 'user.cannot_promote_admin', 403);
                }
                $fields[] = 'role = ?';
                $bind[]   = $role;
            }

            if ($password !== null && $password !== '') {
                if (strlen($password) < 6) {
                    return $this->jsonError($response, 'auth.new_password_short', 400);
                }
                $fields[] = 'password_hash = ?';
                $bind[]   = password_hash($password, PASSWORD_BCRYPT);
            }

            if (empty($fields)) {
                return $this->jsonError($response, 'user.nothing_to_update', 400);
            }

            $fields[] = 'updated_at = ' . \App\Service\Database::sqlNow();
            $sql = 'UPDATE admin_users SET ' . implode(', ', $fields) . ' WHERE username = ?';
            $bind[] = $targetUser;
            $pdo->prepare($sql)->execute($bind);

            return $this->output($response, ['success' => true], $request);
        } catch (\Exception $e) {
            return $this->jsonError($response, $this->__('build.modify_failed') . ': ' . $e->getMessage(), 500);
        }
    }

    /**
     * DELETE /api/admin/users/{username} — 删除用户
     */
    public function userDelete(Request $request, Response $response, array $args): Response
    {
        if ($err = $this->authCheck($request, $response)) return $err;

        $targetUser = $args['username'] ?? '';
        if ($targetUser === '') {
            return $this->jsonError($response, 'user.invalid_username', 400);
        }

        try {
            $pdo = \App\Service\Database::getPdo();

            // 查目标用户信息
            $row = $pdo->prepare("SELECT username, role FROM admin_users WHERE username = ?");
            $row->execute([$targetUser]);
            $target = $row->fetch();
            if (!$target) {
                return $this->jsonError($response, 'user.not_found', 404);
            }

            // 不能删除自己
            if ($targetUser === $this->currentUser) {
                return $this->jsonError($response, 'user.cannot_delete_self', 403);
            }
            // 非 admin 不能删除 admin 用户
            if ($this->currentRole !== 'admin' && $target['role'] === 'admin') {
                return $this->jsonError($response, 'user.cannot_delete_admin', 403);
            }

            $pdo->prepare("DELETE FROM admin_users WHERE username = ?")->execute([$targetUser]);
            return $this->output($response, ['success' => true], $request);
        } catch (\Exception $e) {
            return $this->jsonError($response, $this->__('build.modify_failed') . ': ' . $e->getMessage(), 500);
        }
    }

    // ────────────────────────── helpers ──────────────────────────

    /**
     * 验证 Bearer token
     */
    private function authCheck(Request $request, Response $response): ?Response
    {
        $cred = $this->config->getAdminCredentials();
        $header = $request->getHeaderLine('Authorization');
        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
            return $this->jsonError($response, 'auth.not_logged_in', 401);
        }
        $token = $m[1];

        // 验证 cache 中的随机 token
        try {
            $pdo = \App\Service\Database::getPdo();
            $row = $pdo->prepare("SELECT value FROM cache WHERE cache_key = ? AND expires_at > ?");
            $row->execute(['admin_token_' . $token, time()]);
            $cache = $row->fetch(\PDO::FETCH_ASSOC);
            if ($cache) {
                // value 格式：username|role
                $parts = explode('|', $cache['value']);
                $this->currentUser = $parts[0] ?? '';
                $this->currentRole = $parts[1] ?? 'admin';
                return null;
            }
        } catch (\Exception $e) {
            // DB 不可用降级
        }

        // 未设任何密码则放行
        if (empty($cred['password'])) {
            try {
                $pdo = \App\Service\Database::getPdo();
                $cnt = $pdo->query("SELECT count(*) c FROM admin_users")->fetch()['c'];
                if ($cnt == 0) return null;
            } catch (\Exception $e) {}
        }

        return $this->jsonError($response, 'auth.token_invalid', 401);
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
        return $entry;
    }

    /**
     * 清除拓扑图缓存，确保 mapList 返回最新数据
     */
    private function invalidateTopologyCache(): void
    {
        try {
            $pdo = \App\Service\Database::getPdo();
            $modes = ['jenkins', 'gitlab_ci', 'both'];
            foreach ($modes as $mode) {
                $pdo->prepare("DELETE FROM cache WHERE cache_key = ?")->execute(['map_list_' . $mode]);
            }
        } catch (\Exception $e) {
            // 缓存清理失败不影响主流程
        }
    }
}