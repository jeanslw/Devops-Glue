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

        $cred = $this->config->getAdminCredentials();
        if ($user === $cred['user'] && $pass === $cred['password'] && $pass !== '') {
            $token = base64_encode($user . ':' . $pass);
            return $this->output($response, ['token' => $token], $request);
        }
        return $this->jsonError($response, '账号或密码错误', 401);
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
        if (empty($cred['password'])) {
            return null; // 未设置密码则跳过认证
        }

        $header = $request->getHeaderLine('Authorization');
        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
            return $this->jsonError($response, '未登录', 401);
        }

        $expected = base64_encode($cred['user'] . ':' . $cred['password']);
        if (!hash_equals($expected, $m[1])) {
            return $this->jsonError($response, 'token 无效', 401);
        }

        return null; // 通过
    }

    private function buildEntry(array $body): array
    {
        $entry = [];
        $fields = ['job_name', 'git_platform', 'git_remote', 'project_id', 'web_url', 'current_path', 'harbor_repository', 'api_version'];
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