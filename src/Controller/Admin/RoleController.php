<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Config\AppConfig;
use App\Service\AdminRbacService;
use App\Service\AdminUserRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * 后台角色管理：列表 / 新建 / 更新 / 删除。
 * 方法体从 AdminController 原样搬迁；权限展开复用 AdminRbacService。
 */
class RoleController extends BaseAdminController
{
    private AdminRbacService $rbacService;
    private AdminUserRepository $adminUserRepository;

    public function __construct(
        \App\Service\I18nService $i18n,
        \App\Service\Settings $config,
        \PDO $pdo,
        \App\Service\AppSettingRepository $appSettings,
        AdminRbacService $rbacService,
        AdminUserRepository $adminUserRepository
    ) {
        parent::__construct($i18n, $config, $pdo, $appSettings);
        $this->rbacService        = $rbacService;
        $this->adminUserRepository = $adminUserRepository;
    }

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
}
