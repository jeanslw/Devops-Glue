<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Config\AppConfig;
use App\Service\AdminRbacService;
use App\Service\AdminUserRepository;
use App\Service\PasswordHasher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * 后台用户管理：列表 / 新增 / 更新 / 改密 / 启停 / 删除。
 * 方法体从 AdminController 原样搬迁；isAdminRole / isTargetAdmin 一并迁入。
 */
class UserController extends BaseAdminController
{
    private AdminUserRepository $adminUserRepository;

    public function __construct(
        \App\Service\I18nService $i18n,
        \App\Service\Settings $config,
        \PDO $pdo,
        \App\Service\AppSettingRepository $appSettings,
        AdminUserRepository $adminUserRepository
    ) {
        parent::__construct($i18n, $config, $pdo, $appSettings);
        $this->adminUserRepository = $adminUserRepository;
    }

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
