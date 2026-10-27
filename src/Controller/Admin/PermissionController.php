<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Config\AppConfig;
use App\Service\AdminRbacService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * 后台权限与隐含规则：权限列表 / 注册 / 删除，隐含规则注册 / 删除。
 * 方法体从 AdminController 原样搬迁；隐含规则变更后复用 AdminRbacService 重算角色权限。
 */
class PermissionController extends BaseAdminController
{
    private AdminRbacService $rbacService;

    public function __construct(
        \App\Service\I18nService $i18n,
        \App\Service\Settings $config,
        \PDO $pdo,
        \App\Service\AppSettingRepository $appSettings,
        AdminRbacService $rbacService
    ) {
        parent::__construct($i18n, $config, $pdo, $appSettings);
        $this->rbacService = $rbacService;
    }

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
}
