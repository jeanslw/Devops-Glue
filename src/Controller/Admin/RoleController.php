<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Config\AppConfig;
use App\Service\AdminRbacService;
use App\Service\AdminUserRepository;
use App\Service\AppSettingRepository;
use App\Service\I18nService;
use App\Service\OperationLogRepository;
use App\Service\RoleRepository;
use App\Service\Settings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * 后台角色管理：列表 / 新建 / 更新 / 删除。
 * 权限展开复用 AdminRbacService，角色读写复用 RoleRepository。
 */
class RoleController extends BaseAdminController
{
    private AdminRbacService $rbacService;
    private AdminUserRepository $adminUserRepository;
    private RoleRepository $roles;

    public function __construct(
        I18nService $i18n,
        Settings $config,
        AppSettingRepository $appSettings,
        OperationLogRepository $operationLog,
        AdminRbacService $rbacService,
        AdminUserRepository $adminUserRepository,
        RoleRepository $roles
    ) {
        parent::__construct($i18n, $config, $appSettings, $operationLog);
        $this->rbacService        = $rbacService;
        $this->adminUserRepository = $adminUserRepository;
        $this->roles               = $roles;
    }

    public function roleList(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_USERS_MANAGE_ADMIN)) {
            return $resp;
        }
        try {
            return $this->output($response, $this->roles->listWithPermissions(), $request);
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
            if ($this->roles->nameExists($name)) {
                return $this->jsonError($response, 'user.username_exists', 409);
            }
            $roleId = $this->roles->create($name, $desc);
            $expanded = $this->rbacService->expandPermissions($perms);
            $validKeys = $this->rbacService->allPermissionKeys();
            $this->roles->insertPermissions($roleId, $expanded, $validKeys);
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
            $r = $this->roles->findById($roleId);
            if (!$r) {
                return $this->jsonError($response, 'user.not_found', 404);
            }
            if ($r['is_system']) {
                return $this->jsonError($response, 'user.cannot_create_admin', 403);
            }

            $sets = [];
            if ($name !== '' && $name !== $r['name']) {
                if ($this->roles->nameExistsExcluding($name, $roleId)) {
                    return $this->jsonError($response, 'user.username_exists', 409);
                }
                $this->adminUserRepository->updateRoleName($r['name'], $name);
                $sets['name'] = $name;
            }
            if ($desc !== '' && $desc !== ($r['description'] ?? '')) {
                $sets['description'] = $desc;
            }
            if ($sets !== []) {
                $this->roles->update($roleId, $sets);
            }

            if (is_array($perms)) {
                $this->roles->deletePermissions($roleId);
                $expanded = $this->rbacService->expandPermissions($perms);
                $validKeys = $this->rbacService->allPermissionKeys();
                $this->roles->insertPermissions($roleId, $expanded, $validKeys);
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
            $r = $this->roles->findById($roleId);
            if (!$r) {
                return $this->jsonError($response, 'user.not_found', 404);
            }
            if ($r['is_system']) {
                return $this->jsonError($response, 'user.cannot_create_admin', 403);
            }
            if ($this->adminUserRepository->countByRole($r['name']) > 0) {
                return $this->jsonError($response, 'role.in_use', 400);
            }
            $this->roles->delete($roleId);
            $this->opLog()->record($this->currentUser, 'delete_role', (string)$r['name'], [], $this->clientIp($request), 'success');
            return $this->output($response, ['success' => true], $request);
        } catch (\Exception $e) {
            return $this->jsonError($response, $this->__('build.modify_failed') . ': ' . $e->getMessage(), 500);
        }
    }
}
