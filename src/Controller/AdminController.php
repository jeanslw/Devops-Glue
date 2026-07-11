<?php
namespace App\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Config\AppConfig;

class AdminController extends BaseController
{
    private AppConfig $config;

    public function __construct(AppConfig $config)
    {
        $this->config = $config;
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
            $token = base64_encode($user . ':' . $pass);  // 用原始密码，不用 hash
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
            $pdo->prepare("INSERT INTO admin_users (username, password_hash, updated_at) VALUES (?, ?, datetime('now','localtime')) ON CONFLICT(username) DO UPDATE SET password_hash=excluded.password_hash, updated_at=excluded.updated_at")
                ->execute([$username, $hash]);

            return $this->output($response, ['success' => true, 'message' => '密码已更新，请重新登录'], $request);
        } catch (\Exception $e) {
            return $this->jsonError($response, '修改失败: ' . $e->getMessage(), 500);
        }
    }

    // ────────────────────────── CRUD ──────────────────────────

    /**
     * GET /api/admin/job_git_map — 列出所有映射
     */
    public function jobGitMapList(Request $request, Response $response): Response
    {
        if ($err = $this->authCheck($request, $response)) return $err;

        $maps = $this->config->getJobGitMap();
        $platforms = $this->config->getGitPlatformsConfig();
        $platformNames = array_map(fn($p) => $p['name'], $platforms);

        return $this->output($response, [
            'maps'      => $maps,
            'platforms' => $platformNames,
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
        $newMaps = [];
        $found = false;
        foreach ($maps as $item) {
            if (($item['job_name'] ?? '') === $jobName) {
                $found = true;
                continue;
            }
            $newMaps[] = $item;
        }

        if (!$found) {
            return $this->jsonError($response, "映射 '{$jobName}' 不存在", 404);
        }

        $this->config->saveJobGitMap($newMaps);
        return $this->output($response, ['success' => true], $request);
    }

    // ──────────────────────── 平台 API 版本 ────────────────────────

    /**
     * GET /api/admin/platform_versions — 获取所有平台 API 版本
     */
    public function platformVersionsList(Request $request, Response $response): Response
    {
        if ($err = $this->authCheck($request, $response)) return $err;
        $versions = $this->config->getPlatformApiVersionsWithSource();
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

        // 尝试 env 密码（token 用原始密码生成）
        if (!empty($cred['password'])) {
            $expected = base64_encode($cred['user'] . ':' . $cred['password']);
            if (hash_equals($expected, $token)) return null;
        }

        // 尝试 DB 密码验证
        try {
            $pdo = \App\Service\Database::getPdo();
            $row = $pdo->prepare("SELECT password_hash FROM admin_users WHERE username = ?");
            $row->execute([$cred['user']]);
            $dbUser = $row->fetch();
            if ($dbUser) {
                $expected = base64_encode($cred['user'] . ':' . $dbUser['password_hash']);
                if (hash_equals($expected, $token)) return null;
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