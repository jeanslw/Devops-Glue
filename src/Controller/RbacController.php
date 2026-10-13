<?php

namespace App\Controller;

use App\Config\AppConfig;
use App\Service\AdminUserRepository;
use App\Service\I18nService;
use App\Service\PasswordHasher;
use App\Service\Settings;
use App\Service\TokenService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * RBAC 用户写接口 —— 供 CD 服务账号调用（非 /api/admin/* 交互式后台）。
 *
 * 信任模型：
 *   - 鉴权：仅 API token。AuthMiddleware 已按 resolveRequiredScope() 校验 `rbac.user.write`
 *     scope；这里再检查 `currentRole === api_token`，把不走 scope 校验的登录态用户
 *     （cache token）挡在门外，避免跳过 requirePermission 后任何登录用户都能建号或删号。
 *   - 固定规则：strtolower 归一化、PasswordHasher::hash()、root 账号保护、
 *     super_admin 唯一性与禁止创建、密码最短 8 位。systems 恒为 'cd'（不接受入参）。
 *   - 跳过：requirePermission(ci.users.manage_admin) 与 currentRole===super_admin 判断
 *     （服务账号 currentRole 是 api_token，走角色校验会连 admin 都建不了）。
 */
class RbacController extends BaseController
{
    private Settings $config;
    private AdminUserRepository $adminUserRepository;
    private TokenService $tokenService;

    public function __construct(
        I18nService $i18n,
        Settings $config,
        AdminUserRepository $adminUserRepository,
        TokenService $tokenService
    ) {
        parent::__construct($i18n);
        $this->config             = $config;
        $this->adminUserRepository = $adminUserRepository;
        $this->tokenService       = $tokenService;
    }

    /**
     * 账号是否归属 CD 系统（systems 逗号列表含 'cd'）。
     * RBAC 服务账号是 CD 的账号管理通道，只允许动 cd 账号，禁止越界改 CI-only 用户。
     *
     * @param array<string,mixed> $target 用户记录
     */
    private function belongsToCd(array $target): bool
    {
        $systems = array_filter(
            array_map('trim', explode(',', strtolower((string) ($target['systems'] ?? '')))),
            static fn($v) => $v !== ''
        );
        return in_array(AppConfig::SYSTEM_CD, $systems, true);
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
        if (!AdminUserRepository::isValidUsername($username)) {
            return $this->jsonError($response, 'user.invalid_username', 400);
        }
        // 服务账号不得占用根账号名：root 未落库时抢先注册会让该账号登录响应带上 is_root
        if ($username === $this->config->getRootAdminUser()) {
            return $this->jsonError($response, 'user.cannot_edit_root', 403);
        }
        if ($role === '') {
            return $this->jsonError($response, 'user.invalid_role', 400);
        }
        // 角色以 roles 表存储的规范名为准：MySQL 大小写不敏感匹配会放过 'SUPER_ADMIN'
        // 这类变体，必须回写规范名后再做 === 守卫与落库
        $canonicalRole = $this->adminUserRepository->canonicalRole($role);
        if ($canonicalRole === null) {
            return $this->jsonError($response, 'user.role_not_found', 400);
        }
        // 服务账号不得创建 super_admin（防提权）
        if ($canonicalRole === AppConfig::ROLE_SUPER_ADMIN) {
            return $this->jsonError($response, 'user.cannot_create_super_admin', 403);
        }

        try {
            if ($this->adminUserRepository->userExists($username)) {
                return $this->jsonError($response, 'user.username_exists', 409);
            }
            $hash = PasswordHasher::hash($password);
            $this->adminUserRepository->createUser($username, $hash, $canonicalRole, AppConfig::SYSTEM_CD);
            return $this->output($response, ['success' => true, 'username' => $username], $request);
        } catch (\Exception $e) {
            return $this->jsonError($response, $this->__('build.save_failed') . ': ' . $e->getMessage(), 500);
        }
    }

    /**
     * PUT /api/rbac/users/{username} — 改角色 / 改密码（部分更新）
     *
     * @param array<string,string> $args 路由参数
     */
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

        if ($role === '') {
            return $this->jsonError($response, 'user.invalid_role', 400);
        }
        // 角色规范名：MySQL 大小写不敏感匹配会放过 super_admin 的大小写变体，
        // 守卫与落库统一用 roles 表存储值
        $canonicalRole = null;
        if ($role !== null) {
            $canonicalRole = $this->adminUserRepository->canonicalRole($role);
            if ($canonicalRole === null) {
                return $this->jsonError($response, 'user.role_not_found', 400);
            }
            if ($canonicalRole === AppConfig::ROLE_SUPER_ADMIN) {
                return $this->jsonError($response, 'user.cannot_promote_super_admin', 403);
            }
        }

        $passwordHash = null;
        if ($password !== null && $password !== '') {
            if (strlen((string) $password) < 8) {
                return $this->jsonError($response, 'auth.new_password_short', 400);
            }
            $passwordHash = PasswordHasher::hash((string) $password);
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
            // 跨系统边界：只允许改归属 CD 的账号，CI-only 用户不由这个服务账号管理
            if (!$this->belongsToCd($target)) {
                return $this->jsonError($response, 'user.cannot_manage_non_cd', 403);
            }

            $this->adminUserRepository->updateUser($targetUser, $passwordHash, $canonicalRole);
            return $this->output($response, ['success' => true], $request);
        } catch (\Exception $e) {
            return $this->jsonError($response, $this->__('build.modify_failed') . ': ' . $e->getMessage(), 500);
        }
    }

    /**
     * DELETE /api/rbac/users/{username} — 删号
     *
     * @param array<string,string> $args 路由参数
     */
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
            // 跨系统边界：只允许删归属 CD 的账号，CI-only 用户不由这个服务账号管理
            if (!$this->belongsToCd($target)) {
                return $this->jsonError($response, 'user.cannot_manage_non_cd', 403);
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

    /**
     * GET /api/rbac/users/{username} — 单用户（404 不存在，不含哈希）
     *
     * @param array<string,string> $args 路由参数
     */
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

    /**
     * GET /api/rbac/roles/{name}/permissions —— 角色权限清单（供 CD 做权限映射）
     *
     * 与 `/api/rbac/roles`（只有 name/description）互补：CD 拿不到细粒度权限就无法做权限映射，
     * 只能把 Glue 的权限清单硬编码一份（容易不一致）。这里复用 `TokenService::loadPermissions`
     * 的 role_permissions JOIN roles 查询，不新增 SQL。
     *
     * 返回值的三种情况（CD 的判据依赖它们，注意区分）：
     *   - 角色不存在：404（`user.role_not_found`），与角色存在但零权限区分开，
     *     否则角色名写错也返回 `[]`，CD 会把查询失败当成该角色没有任何权限而清空权限；
     *   - `super_admin`：返回 `['*']`，与登录接口 `/api/admin/login` 的 `permissions: "*"` 语义一致；
     *     该角色权限由代码授予、不落在 role_permissions 表里，若照表返回会得到 `[]`，
     *     等于把超管读成零权限；
     *   - 其余角色：返回真实权限 key 数组（可能为空数组，此时表示该角色没有被授予任何权限）。
     *
     * @param array<string,string> $args 路由参数（name = 角色名）
     */
    public function rolePermissions(Request $request, Response $response, array $args): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requireApiToken($response)) {
            return $resp;
        }
        $role = trim((string) ($args['name'] ?? ''));
        if ($role === '') {
            return $this->jsonError($response, 'user.invalid_role', 400);
        }
        try {
            // 回写规范名：大小写变体（如 'SUPER_ADMIN'）应按 roles 表存储值判定与返回
            $canonicalRole = $this->adminUserRepository->canonicalRole($role);
            if ($canonicalRole === null) {
                return $this->jsonError($response, 'user.role_not_found', 404);
            }
            $permissions = $canonicalRole === AppConfig::ROLE_SUPER_ADMIN
                ? ['*']
                : $this->tokenService->loadPermissions($canonicalRole);
            return $this->output($response, [
                'role'        => $canonicalRole,
                'permissions' => array_values($permissions),
            ], $request);
        } catch (\Exception $e) {
            return $this->jsonError($response, $this->__('build.modify_failed') . ': ' . $e->getMessage(), 500);
        }
    }
}
