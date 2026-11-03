<?php

declare(strict_types=1);

namespace App\Service;

/**
 * 由原 Database 拆分出的「RbacSeeder」职责（门面转调，行为不变）。
 */
class RbacSeeder
{
    private \PDO $pdo;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function seedRbac(string $seedMode = Database::SEED_MODE_PRUNE): void
    {
        $pdo = $this->pdo;


        $failures = [];

        $permUpsert = Database::sqlUpsert(\App\Config\AppConfig::TABLE_PERMISSIONS, 'perm_key, description, parent_key', '?, ?, ?');
        $permStmt = $pdo->prepare($permUpsert);
        foreach (\App\Config\AppConfig::DEFAULT_PERMISSIONS as $key => $def) {
            $desc = $def['name'];
            $parent = $def['parent'] ?? null;
            try {
                $permStmt->execute([$key, $desc, $parent]);
            } catch (\Exception $e) {
                \App\Helper\Log::error('seedRbac 权限写入失败', ['perm_key' => $key, 'error' => $e->getMessage()]);
                $failures[] = ['step' => 'permission', 'key' => (string)$key, 'error' => $e->getMessage()];
            }
        }

        $ruleUpsert = Database::sqlUpsert(\App\Config\AppConfig::TABLE_IMPLIED_RULES, 'source_key, target_key', '?, ?');
        $ruleStmt = $pdo->prepare($ruleUpsert);
        foreach (\App\Config\AppConfig::IMPLIED_PERMISSIONS as $src => $targets) {
            foreach ($targets as $tgt) {
                try {
                    $ruleStmt->execute([$src, $tgt]);
                } catch (\Exception $e) {
                    \App\Helper\Log::error('seedRbac 隐含规则写入失败', ['source' => $src, 'target' => $tgt, 'error' => $e->getMessage()]);
                    $failures[] = ['step' => 'implied_rule', 'key' => $src . '→' . $tgt, 'error' => $e->getMessage()];
                }
            }
        }

        $findRoleStmt   = $pdo->prepare("SELECT id FROM " . \App\Config\AppConfig::TABLE_ROLES . " WHERE name = ?");
        $insertRoleStmt = $pdo->prepare("INSERT INTO " . \App\Config\AppConfig::TABLE_ROLES . " (name, description, is_system) VALUES (?, ?, ?)");
        $updateRoleStmt = $pdo->prepare("UPDATE " . \App\Config\AppConfig::TABLE_ROLES . " SET description = ?, is_system = ? WHERE id = ?");
        foreach (\App\Config\AppConfig::DEFAULT_ROLES as $roleName => $perms) {
            $roleDesc = \App\Config\AppConfig::DEFAULT_ROLE_DESCRIPTIONS[$roleName];
            $isSystem = in_array($roleName, \App\Config\AppConfig::DEFAULT_SYSTEM_ROLES) ? 1 : 0;
            try {
                $findRoleStmt->execute([$roleName]);
                $roleId = $findRoleStmt->fetchColumn();
                if ($roleId === false) {
                    try {
                        $insertRoleStmt->execute([$roleName, $roleDesc, $isSystem]);
                    } catch (\Exception $e) {
                        $findRoleStmt->execute([$roleName]);
                        $roleId = $findRoleStmt->fetchColumn();
                        if ($roleId === false) {
                            throw $e;
                        }
                        $updateRoleStmt->execute([$roleDesc, $isSystem, (int)$roleId]);
                    }
                } else {
                    $updateRoleStmt->execute([$roleDesc, $isSystem, (int)$roleId]);
                }
            } catch (\Exception $e) {
                \App\Helper\Log::error('seedRbac 角色写入失败', ['role' => $roleName, 'error' => $e->getMessage()]);
                $failures[] = ['step' => 'role', 'key' => $roleName, 'error' => $e->getMessage()];
            }
        }

        $allPermKeys = array_keys(\App\Config\AppConfig::DEFAULT_PERMISSIONS);
        $delRpStmt = ($seedMode === Database::SEED_MODE_PRUNE)
            ? $pdo->prepare("DELETE FROM " . \App\Config\AppConfig::TABLE_ROLE_PERMISSIONS . " WHERE role_id = (SELECT id FROM " . \App\Config\AppConfig::TABLE_ROLES . " WHERE name = ?)")
            : null;

        $rpStmt = $pdo->prepare(Database::sqlInsertIgnore(
            \App\Config\AppConfig::TABLE_ROLE_PERMISSIONS,
            'role_id, perm_key',
            '(SELECT id FROM ' . \App\Config\AppConfig::TABLE_ROLES . ' WHERE name = ?), ?'
        ));
        foreach (\App\Config\AppConfig::DEFAULT_ROLES as $roleName => $perms) {
            if ($delRpStmt !== null) {
                try {
                    $delRpStmt->execute([$roleName]);
                } catch (\Exception $e) {
                    \App\Helper\Log::error('seedRbac 角色权限清理失败', ['role' => $roleName, 'error' => $e->getMessage()]);
                    $failures[] = ['step' => 'role_permissions_prune', 'key' => $roleName, 'error' => $e->getMessage()];
                }
            }
            $permKeys = ($perms === '*') ? $allPermKeys : $perms;
            foreach ($permKeys as $permKey) {
                try {
                    $rpStmt->execute([$roleName, $permKey]);
                } catch (\Exception $e) {
                    \App\Helper\Log::error('seedRbac 角色权限写入失败', ['role' => $roleName, 'perm_key' => $permKey, 'error' => $e->getMessage()]);
                    $failures[] = ['step' => 'role_permission', 'key' => $roleName . ':' . $permKey, 'error' => $e->getMessage()];
                }
            }
        }

        if ($failures !== []) {
            $sample = array_slice($failures, 0, 5);
            \App\Helper\Log::error('seedRbac 部分失败：种子未播全', ['count' => count($failures), 'sample' => $sample]);
            Database::recordSystemAudit(
                Database::ACTION_RBAC_SEED_FAILED,
                '',
                ['count' => count($failures), 'sample' => $sample, 'seed_mode' => $seedMode],
                'failure'
            );
            throw new \RuntimeException(
                'RBAC 种子部分失败（' . count($failures) . ' 项，例如 ' . $sample[0]['step'] . ' ' . $sample[0]['key'] . '）：'
                . '种子未播全，本次不标记版本，下次引导将重试'
            );
        }

        $pdo->exec(
            "DELETE FROM " . \App\Config\AppConfig::TABLE_ROLE_PERMISSIONS
            . " WHERE role_id NOT IN (SELECT id FROM " . \App\Config\AppConfig::TABLE_ROLES . ")"
        );
    }

    public function seedAdmin(): void
    {
        AdminUserRepository::seedAdminFromEnv($this->pdo);
    }

    public function verifySeed(): void
    {
        $pdo = $this->pdo;
        $problems = [];

        try {
            $stmt = $pdo->prepare("SELECT count(*) c FROM " . \App\Config\AppConfig::TABLE_ROLES . " WHERE name = ?");
            $stmt->execute([\App\Config\AppConfig::ROLE_SUPER_ADMIN]);
            if ((int)$stmt->fetch()['c'] === 0) {
                $problems[] = 'roles 缺少 super_admin';
            }
        } catch (\Throwable $e) {
            $problems[] = 'roles 查询失败: ' . $e->getMessage();
        }

        try {
            $cnt = (int)$pdo->query("SELECT count(*) c FROM " . \App\Config\AppConfig::TABLE_PERMISSIONS)->fetch()['c'];
            if ($cnt === 0) {
                $problems[] = 'permissions 为空';
            }
        } catch (\Throwable $e) {
            $problems[] = 'permissions 查询失败: ' . $e->getMessage();
        }

        if (($_ENV['ADMIN_PASSWORD'] ?? '') !== '') {
            try {
                $cnt = (int)$pdo->query("SELECT count(*) c FROM " . \App\Config\AppConfig::TABLE_ADMIN_USERS)->fetch()['c'];
                if ($cnt === 0) {
                    $problems[] = 'admin_users 为空';
                }
            } catch (\Throwable $e) {
                $problems[] = 'admin_users 查询失败: ' . $e->getMessage();
            }
        }

        if (!empty($problems)) {
            throw new \RuntimeException('数据库种子自检未通过: ' . implode('；', $problems));
        }
    }

    public function seedOidcConfigFromEnvIfEmpty(): void
    {
        try {
            $pdo = $this->pdo;
            if (Database::readAppSetting($pdo, Database::OIDC_ENV_SEED_KEY) !== null) {
                return;
            }
            if (\App\Service\OidcConfigImporter::hasNoClientRow($pdo)) {
                $result = (new \App\Service\OidcConfigImporter($pdo))->importFromEnv();
                if ($result['migrated'] !== []) {
                    \App\Helper\Log::warning(
                        '检测到 ci_oauth_clients 为空（全新库）：已按 env 自动播种 OIDC 配置——'
                        . implode(', ', $result['migrated'])
                        . '。后续请在后台「系统设置 → 平台管理」页维护；确认无误后可从 env 删除对应键。',
                        ['oidc_auto_seeded' => $result['migrated']]
                    );
                }
            }
            $cdSeeded = (new \App\Service\OAuthClientRepository($pdo))
                ->find(\App\Config\AppConfig::SERVICE_CD) !== null;
            if ($cdSeeded) {
                Database::writeAppSetting($pdo, Database::OIDC_ENV_SEED_KEY, '1');
            }
        } catch (\Throwable $e) {
            \App\Helper\Log::exception($e);
        }
    }
}
