<?php

declare(strict_types=1);

namespace App\Service;

use App\Config\AppConfig;

/**
 * 后台 RBAC 权限展开 / 重算的共享服务。
 *
 * 原散落在 AdminController 的 5 个私有方法，因 Role / Permission 控制器都要复用，
 * 抽成服务以避免 SQL 复制到多个类；方法体与控制器内实现保持一致（move-only）。
 */
class AdminRbacService
{
    private \PDO $pdo;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * 展开权限列表：根据 implied_rules 表自动添加隐含权限
     *
     * @param array<array-key, string> $perms
     *
     * @return list<string>
     */
    public function expandPermissions(array $perms): array
    {
        try {
            $pdo = $this->pdo;
            $implied = [];
            $rows = $pdo->query("SELECT source_key, target_key FROM " . AppConfig::TABLE_IMPLIED_RULES)->fetchAll();
            foreach ($rows as $r) {
                $implied[$r['source_key']][] = $r['target_key'];
            }
        } catch (\Exception $e) {
            $implied = AppConfig::IMPLIED_PERMISSIONS;
        }

        return $this->expandWith($perms, $implied);
    }

    /**
     * 基于给定的隐含规则映射做权限 BFS 展开（与 expandPermissions 同源，便于在「规则已删除」的剩余规则上重算）。
     *
     * @param array<array-key, string> $perms
     * @param array<string, list<string>> $implied
     *
     * @return list<string>
     */
    private function expandWith(array $perms, array $implied): array
    {
        $result = [];
        $queue  = array_values($perms);

        while ($queue) {
            $pk = array_shift($queue);
            if (in_array($pk, $result, true)) {
                continue;
            }
            $result[] = $pk;
            if (isset($implied[$pk])) {
                foreach ($implied[$pk] as $child) {
                    if (!in_array($child, $result, true) && !in_array($child, $queue, true)) {
                        $queue[] = $child;
                    }
                }
            }
        }
        return $result;
    }

    /**
     * 删除某条隐含规则后，重算各自定义角色权限，清除不再被任何剩余规则推导出来的「孤儿」隐含权限。
     *
     * 权限展开是「显式 ∪ 隐含推导」；由于 ci_role_permissions 不区分显式/推导，这里以「不被其它已有权限推导出来的权限
     * 视为显式根」重新推导，并删除推导集合之外、却又残留的权限，避免删除规则后权限仍滞留（P1 #2）。
     *
     * @return int 被清理权限的角色数
     */
    public function pruneOrphanedImpliedPermissions(): int
    {
        $pdo    = $this->pdo;
        $rows   = $pdo->query("SELECT source_key, target_key FROM " . AppConfig::TABLE_IMPLIED_RULES)->fetchAll();
        $implied = [];
        foreach ($rows as $r) {
            $implied[$r['source_key']][] = $r['target_key'];
        }

        $roleIds = $pdo->query("SELECT id FROM " . AppConfig::TABLE_ROLES . " WHERE is_system = 0")->fetchAll(\PDO::FETCH_COLUMN);
        $permSel = $pdo->prepare("SELECT perm_key FROM " . AppConfig::TABLE_ROLE_PERMISSIONS . " WHERE role_id = ?");
        $permDel = $pdo->prepare("DELETE FROM " . AppConfig::TABLE_ROLE_PERMISSIONS . " WHERE role_id = ? AND perm_key = ?");

        $cleanedRoles = 0;
        foreach ($roleIds as $roleId) {
            $roleId = (int) $roleId;
            $permSel->execute([$roleId]);
            $current = $permSel->fetchAll(\PDO::FETCH_COLUMN);
            if ($current === []) {
                continue;
            }
            // 找出「根」权限：不被该角色其它权限经由剩余规则推导出来的权限
            $roots = [];
            foreach ($current as $pk) {
                $impliedByOther = false;
                foreach ($current as $other) {
                    if ($other === $pk) {
                        continue;
                    }
                    if (in_array($pk, $this->expandWith([$other], $implied), true)) {
                        $impliedByOther = true;
                        break;
                    }
                }
                if (!$impliedByOther) {
                    $roots[] = $pk;
                }
            }
            $desired = $this->expandWith($roots, $implied);
            $removed = 0;
            foreach ($current as $pk) {
                if (!in_array($pk, $desired, true)) {
                    $permDel->execute([$roleId, $pk]);
                    $removed++;
                }
            }
            if ($removed > 0) {
                $cleanedRoles++;
            }
        }
        return $cleanedRoles;
    }

    /**
     * 隐含规则变更后，对自定义角色（is_system=0）重算权限，补进新规则带来的隐含权限。
     *
     *
     * @return int
     */
    public function resyncImpliedPermissions(): int
    {
        $pdo       = $this->pdo;
        $validKeys = $this->allPermissionKeys();
        $roles     = $pdo->query(
            "SELECT id FROM " . AppConfig::TABLE_ROLES . " WHERE is_system = 0"
        )->fetchAll(\PDO::FETCH_COLUMN);

        $permSelect = $pdo->prepare("SELECT perm_key FROM " . AppConfig::TABLE_ROLE_PERMISSIONS . " WHERE role_id = ?");
        $permInsert = $pdo->prepare("INSERT INTO " . AppConfig::TABLE_ROLE_PERMISSIONS . " (role_id, perm_key) VALUES (?, ?)");

        $affected = 0;
        foreach ($roles as $roleId) {
            $roleId = (int)$roleId;
            $permSelect->execute([$roleId]);
            $current  = $permSelect->fetchAll(\PDO::FETCH_COLUMN);
            $expanded = $this->expandPermissions($current);
            $added    = 0;
            foreach ($expanded as $pk) {
                if (!in_array($pk, $current, true) && in_array($pk, $validKeys, true)) {
                    try {
                        $permInsert->execute([$roleId, $pk]);
                        $added++;
                    } catch (\Exception $e) {
                        \App\Helper\Log::exception($e);
                    }
                }
            }
            if ($added > 0) {
                $affected++;
            }
        }
        return $affected;
    }

    /**
     * 取所有已注册的权限 key（数据驱动，从 DB 读；DB 异常时回退常量）
     *
     * @return list<string>
     */
    public function allPermissionKeys(): array
    {
        try {
            $pdo = $this->pdo;
            return array_values($pdo->query("SELECT perm_key FROM " . AppConfig::TABLE_PERMISSIONS)->fetchAll(\PDO::FETCH_COLUMN));
        } catch (\Exception $e) {
            return array_keys(AppConfig::DEFAULT_PERMISSIONS);
        }
    }

    /**
     * @return array<int,string>
     */
    public static function parseSystems(string $systems): array
    {
        return array_filter(array_map('trim', explode(',', strtolower($systems))), fn($value) => $value !== '');
    }

    public static function systemsContain(string $systems, string $needle): bool
    {
        return in_array($needle, self::parseSystems($systems), true);
    }
}
