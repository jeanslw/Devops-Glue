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
        $result = [];
        $queue  = array_values($perms);
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
