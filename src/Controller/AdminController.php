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
            return $this->jsonError($response, '自动发现功能未启用（Jenkins 不可用）', 503);
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
            return $this->output($response, [
                'found' => count($found),
                'saved' => $saved,
                'errors' => $errors,
                'items' => array_map(fn($i) => $i['entry']['job_name'], $found),
            ], $request);
        } catch (\Exception $e) {
            return $this->jsonError($response, '扫描失败: ' . $e->getMessage(), 500);
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
            return $this->jsonError($response, '查询失败: ' . $e->getMessage(), 500);
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
            return $this->jsonError($response, '账号或密码错误', 401);
        }

        $authed = false;

        // 优先查数据库
        try {
            $pdo = \App\Service\Database::getPdo();
            $row = $pdo->prepare("SELECT password_hash FROM admin_users WHERE username = ?");
            $row->execute([$user]);
            $dbUser = $row->fetch();
            if ($dbUser && password_verify($pass, $dbUser['password_hash'])) {
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
            }
        }

        if ($authed) {
            $token = bin2hex(random_bytes(32));
            // 持久化 token，24h 过期
            try {
                $pdo = \App\Service\Database::getPdo();
                $sql = \App\Service\Database::sqlUpsert('cache', 'cache_key, value, expires_at', '?, ?, ?');
                $pdo->prepare($sql)->execute(['admin_token_' . $token, $user, time() + 86400]);
            } catch (\Exception $e) {
                // cache 不可用时仍返回 token（降级）
            }
            return $this->output($response, ['token' => $token], $request);
        }
        return $this->jsonError($response, '账号或密码错误', 401);
    }

    /** PUT /api/admin/password — 修改密码 */
    public function changePassword(Request $request, Response $response): Response
    {
        if ($err = $this->authCheck($request, $response)) return $err;

        $body = $request->getParsedBody() ?? json_decode($request->getBody()->__toString(), true) ?? [];
        $oldPass = $body['old_password'] ?? '';
        $newPass = $body['new_password'] ?? '';

        if (strlen($newPass) < 6) {
            return $this->jsonError($response, '新密码至少 6 位', 400);
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
                return $this->jsonError($response, '旧密码错误', 403);
            }

            // 更新密码
            $hash = password_hash($newPass, PASSWORD_BCRYPT);
            $sql = \App\Service\Database::sqlUpsert('admin_users', 'username, password_hash, updated_at', '?, ?, ' . \App\Service\Database::sqlNow());
            \App\Service\Database::getPdo()->prepare($sql)->execute([$username, $hash]);

            // 密码变更后清除所有旧 token
            try {
                \App\Service\Database::getPdo()->exec("DELETE FROM cache WHERE cache_key LIKE 'admin_token_%'");
            } catch (\Exception $e) {}

            return $this->output($response, ['success' => true, 'message' => '密码已更新，请重新登录'], $request);
        } catch (\Exception $e) {
            return $this->jsonError($response, '修改失败: ' . $e->getMessage(), 500);
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
            return $this->jsonError($response, 'job_name 为必填字段', 400);
        }

        $maps = $this->config->getJobGitMap();

        foreach ($maps as $item) {
            if (($item['job_name'] ?? '') === $jobName) {
                return $this->jsonError($response, "映射 '{$jobName}' 已存在，请使用编辑功能", 409);
            }
        }

        $entry = $this->buildEntry($body);
        $maps[] = $entry;
        $this->config->saveJobGitMap($maps);

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
            return $this->jsonError($response, '_original_job_name 为必填字段', 400);
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
            return $this->jsonError($response, "映射 '{$oldName}' 不存在", 404);
        }

        $this->config->saveJobGitMap($maps);
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
            return $this->jsonError($response, 'job_name 为必填参数', 400);
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
            return $this->jsonError($response, "映射 '{$jobName}' 不存在", 404);
        }

        $this->config->deleteJobGitMap($jobName);
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
            return $this->jsonError($response, 'versions 不能为空', 400);
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
            return $this->jsonError($response, 'mode 必须为 jenkins / gitlab_ci / both', 400);
        }

        // 拒绝不可用的 Provider
        $jenkinsCfg = $this->config->getJenkinsConfig();
        $hasJenkins = !empty($jenkinsCfg['url']);
        $hasGitlab = $this->config->isPlatformConfigured('gitlab');
        $glCfg = $hasGitlab ? $this->config->getGitlabConfig() : [];
        $hasGitlabCi = $hasGitlab && !empty($glCfg['base_url']) && !empty($glCfg['token']);

        if (($mode === 'jenkins' || $mode === 'both') && !$hasJenkins) {
            return $this->jsonError($response, 'Jenkins 不可用（未配置 url），请先检查配置文件', 400);
        }
        if (($mode === 'gitlab_ci' || $mode === 'both') && !$hasGitlabCi) {
            return $this->jsonError($response, 'GitLab CI 不可用（未配置 base_url / token），请先检查配置文件', 400);
        }

        try {
            $this->config->setBuildMode($mode);
            return $this->output($response, ['success' => true, 'mode' => $mode], $request);
        } catch (\Exception $e) {
            return $this->jsonError($response, '保存失败: ' . $e->getMessage(), 500);
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
            return $this->jsonError($response, '未登录', 401);
        }
        $token = $m[1];

        // 验证 cache 中的随机 token
        try {
            $pdo = \App\Service\Database::getPdo();
            $row = $pdo->prepare("SELECT value FROM cache WHERE cache_key = ? AND expires_at > ?");
            $row->execute(['admin_token_' . $token, time()]);
            if ($row->fetch()) return null;
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

        return $this->jsonError($response, 'token 无效', 401);
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
}