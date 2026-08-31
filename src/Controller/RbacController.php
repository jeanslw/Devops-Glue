<?php

namespace App\Controller;

use App\Config\AppConfig;
use App\Service\AdminUserRepository;
use App\Service\I18nService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * RBAC 用户写接口 —— 供 CD 服务账号调用（非 /api/admin/* 交互式后台）。
 *
 * trusted 模型：
 *   - 鉴权：仅 API token。AuthMiddleware 已按 resolveRequiredScope() 校验 `rbac.user.write`
 *     scope；此处再兜一道 `currentRole === api_token`，把「不走 scope 校验」的登录态用户
 *     （cache token）挡在门外，避免跳过 requirePermission 后任何登录用户都能建/删号。
 *   - 不变量：strtolower 归一化、password_hash(PASSWORD_BCRYPT)、root 账号保护、
 *     super_admin 唯一性/禁止创建、密码最短 8 位。systems 恒为 'cd'（不接受入参）。
 *   - 跳过：requirePermission(ci.users.manage_admin) 与 currentRole===super_admin 判断
 *     （服务账号 currentRole 是 api_token，走角色校验会连 admin 都建不了）。
 */
class RbacController extends BaseController
{
    private AppConfig $config;
    private AdminUserRepository $adminUserRepository;

    public function __construct(
        I18nService $i18n,
        AppConfig $config,
        AdminUserRepository $adminUserRepository
    ) {
        parent::__construct($i18n);
        $this->config             = $config;
        $this->adminUserRepository = $adminUserRepository;
    }

    /**
     * 仅 API token 可调（scope 门槛由 AuthMiddleware 校验，这里挡登录态用户）。
     */
    private function requireApiToken(Response $response): ?Response
    {
        if ($this->currentRole !== AppConfig::ROLE_API_TOKEN) {
            return $this->jsonError($response, 'api_token.scope_forbidden', 403);
        }
        return null;
    }

    /** POST /api/rbac/users — 建号（systems 恒为 'cd'，禁止建 super_admin） */
    public function userCreate(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requireApiToken($response)) {
            return $resp;
        }

        $body     = $request->getParsedBody() ?? json_decode((string) $request->getBody(), true) ?? [];
        $username = strtolower(trim((string) ($body['username'] ?? '')));
        $password = (string) ($body['password'] ?? '');
        $role     = trim((string) ($body['role'] ?? ''));

        if ($username === '' || strlen($password) < 8) {
            return $this->jsonError($response, 'auth.new_password_short', 400);
        }
        if ($role === '') {
            return $this->jsonError($response, 'user.invalid_role', 400);
        }
        // 服务账号不得创建 super_admin（防提权）
        if ($role === AppConfig::ROLE_SUPER_ADMIN) {
            return $this->jsonError($response, 'user.cannot_create_super_admin', 403);
        }
        // 防「角色名悬空」：role 必须是 roles 表里真实存在的角色，否则该用户权限加载为空
        if (!$this->adminUserRepository->roleExists($role)) {
            return $this->jsonError($response, 'user.role_not_found', 400);
        }

        try {
            if ($this->adminUserRepository->userExists($username)) {
                return $this->jsonError($response, 'user.username_exists', 409);
            }
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $this->adminUserRepository->createUser($username, $hash, $role, AppConfig::SYSTEM_CD);
            return $this->output($response, ['success' => true, 'username' => $username], $request);
        } catch (\Exception $e) {
            return $this->jsonError($response, $this->__('build.save_failed') . ': ' . $e->getMessage(), 500);
        }
    }

    /** PUT /api/rbac/users/{username} — 改角色 / 改密码（部分更新） */
    public function userUpdate(Request $request, Response $response, array $args): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requireApiToken($response)) {
            return $resp;
        }

        $targetUser = strtolower(trim((string) ($args['username'] ?? '')));
        if ($targetUser === '') {
            return $this->jsonError($response, 'user.invalid_username', 400);
        }

        $body     = $request->getParsedBody() ?? json_decode((string) $request->getBody(), true) ?? [];
        $password = $body['password'] ?? null;
        $role     = array_key_exists('role', $body) ? trim((string) $body['role']) : null;

        if ($role === AppConfig::ROLE_SUPER_ADMIN) {
            return $this->jsonError($response, 'user.cannot_promote_super_admin', 403);
        }
        if ($role === '') {
            return $this->jsonError($response, 'user.invalid_role', 400);
        }
        // 防「角色名悬空」：改角色时 role 必须是 roles 表里真实存在的角色
        if ($role !== null && !$this->adminUserRepository->roleExists($role)) {
            return $this->jsonError($response, 'user.role_not_found', 400);
        }

        $passwordHash = null;
        if ($password !== null && $password !== '') {
            if (strlen((string) $password) < 8) {
                return $this->jsonError($response, 'auth.new_password_short', 400);
            }
            $passwordHash = password_hash((string) $password, PASSWORD_BCRYPT);
        }

        if ($role === null && $passwordHash === null) {
            return $this->jsonError($response, 'user.nothing_to_update', 400);
        }

        try {
            $target = $this->adminUserRepository->findUser($targetUser);
            if (!$target) {
                return $this->jsonError($response, 'user.not_found', 404);
            }
            // 内置根账号 / 既有 super_admin 均不可被服务账号改动
            if (
                $targetUser === $this->config->getRootAdminUser()
                || $target['role'] === AppConfig::ROLE_SUPER_ADMIN
            ) {
                return $this->jsonError($response, 'user.cannot_edit_root', 403);
            }

            $this->adminUserRepository->updateUser($targetUser, $passwordHash, $role);
            return $this->output($response, ['success' => true], $request);
        } catch (\Exception $e) {
            return $this->jsonError($response, $this->__('build.modify_failed') . ': ' . $e->getMessage(), 500);
        }
    }

    /** DELETE /api/rbac/users/{username} — 删号 */
    public function userDelete(Request $request, Response $response, array $args): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requireApiToken($response)) {
            return $resp;
        }

        $targetUser = strtolower(trim((string) ($args['username'] ?? '')));
        if ($targetUser === '') {
            return $this->jsonError($response, 'user.invalid_username', 400);
        }

        try {
            $target = $this->adminUserRepository->findUser($targetUser);
            if (!$target) {
                return $this->jsonError($response, 'user.not_found', 404);
            }
            if (
                $targetUser === $this->config->getRootAdminUser()
                || $target['role'] === AppConfig::ROLE_SUPER_ADMIN
            ) {
                return $this->jsonError($response, 'user.cannot_delete_root', 403);
            }

            $this->adminUserRepository->deleteUser($targetUser);
            return $this->output($response, ['success' => true], $request);
        } catch (\Exception $e) {
            return $this->jsonError($response, $this->__('build.modify_failed') . ': ' . $e->getMessage(), 500);
        }
    }

    /** GET /api/rbac/users — 用户列表（全量，无分页，不含哈希） */
    public function userList(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requireApiToken($response)) {
            return $resp;
        }
        try {
            $users = $this->adminUserRepository->listUsersForApi();
            return $this->output($response, ['users' => $users], $request);
        } catch (\Exception $e) {
            return $this->jsonError($response, $this->__('build.modify_failed') . ': ' . $e->getMessage(), 500);
        }
    }

    /** GET /api/rbac/users/{username} — 单用户（404 不存在，不含哈希） */
    public function userGet(Request $request, Response $response, array $args): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requireApiToken($response)) {
            return $resp;
        }
        $username = strtolower(trim((string) ($args['username'] ?? '')));
        if ($username === '') {
            return $this->jsonError($response, 'user.invalid_username', 400);
        }
        try {
            $user = $this->adminUserRepository->findUserForApi($username);
            if (!$user) {
                return $this->jsonError($response, 'user.not_found', 404);
            }
            return $this->output($response, $user, $request);
        } catch (\Exception $e) {
            return $this->jsonError($response, $this->__('build.modify_failed') . ': ' . $e->getMessage(), 500);
        }
    }

    /** POST /api/rbac/users/{username}/verify-password — 校验密码，仅返回布尔（哈希不出 Glue） */
    public function userVerifyPassword(Request $request, Response $response, array $args): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requireApiToken($response)) {
            return $resp;
        }
        $username = strtolower(trim((string) ($args['username'] ?? '')));
        if ($username === '') {
            return $this->jsonError($response, 'user.invalid_username', 400);
        }
        $body     = $request->getParsedBody() ?? json_decode((string) $request->getBody(), true) ?? [];
        $password = (string) ($body['password'] ?? '');
        if ($password === '') {
            return $this->jsonError($response, 'user.password_required', 400);
        }
        try {
            $user = $this->adminUserRepository->findByUsername($username);
            if (!$user) {
                return $this->jsonError($response, 'user.not_found', 404);
            }
            $valid = password_verify($password, (string) ($user['password_hash'] ?? ''));
            return $this->output($response, ['valid' => $valid], $request);
        } catch (\Exception $e) {
            return $this->jsonError($response, $this->__('build.modify_failed') . ': ' . $e->getMessage(), 500);
        }
    }

    /** GET /api/rbac/roles — 角色目录（name + description，供审批规则选角色） */
    public function roleList(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requireApiToken($response)) {
            return $resp;
        }
        try {
            $roles = $this->adminUserRepository->listRolesForApi();
            return $this->output($response, ['roles' => $roles], $request);
        } catch (\Exception $e) {
            return $this->jsonError($response, $this->__('build.modify_failed') . ': ' . $e->getMessage(), 500);
        }
    }
}
