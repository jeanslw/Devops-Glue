<?php

/**
 * 一次性迁移：把历史遗留的镜像仓库连接行 platform 从 harbor 改为 registry。
 *
 * 背景：早期版本镜像仓库连接的 platform 恒为 harbor，类型（harbor / registry）只存在
 * ci_app_settings.registry_type。新版本改为 platform 随 registry_type 变化（harbor / registry），
 * 这样单看 ci_platform_connections 即可区分仓库类型。本脚本修复已存在的历史行。
 *
 * 行为：仅当 registry_type = 'registry' 且存在 platform='harbor' 行、且不存在 platform='registry'
 * 行时，把该行的 platform 更新为 'registry'（数据原样保留）。幂等、可重复执行。
 *
 * 用法：
 *   php cli/migrate-registry-platform.php
 *
 * 退出码：0 = 完成（含无需迁移）；1 = 致命错误。
 */

require __DIR__ . '/bootstrap.php';

use App\Config\AppConfig;
use App\Service\AppSettingRepository;
use App\Service\Database;

try {
    $pdo = Database::getPdo();
} catch (\Throwable $e) {
    fwrite(STDERR, "错误：数据库连接失败: {$e->getMessage()}\n");
    exit(1);
}

if (
    !Database::tableExists($pdo, AppConfig::TABLE_PLATFORM_CONNECTIONS)
    || !Database::tableExists($pdo, AppConfig::TABLE_APP_SETTINGS)
) {
    echo "表 " . AppConfig::TABLE_PLATFORM_CONNECTIONS . " 或 " . AppConfig::TABLE_APP_SETTINGS . " 不存在，无需迁移。\n";
    exit(0);
}

$appSettings = new AppSettingRepository($pdo);
$registryType = $appSettings->getRegistryType();

echo "当前 registry_type = {$registryType}\n";

if ($registryType !== 'registry') {
    echo "registry_type 不是 registry，无需迁移 harbor 行（保持 platform='harbor'）。\n";
    exit(0);
}

$table = AppConfig::TABLE_PLATFORM_CONNECTIONS;

$harborRow = $pdo->prepare("SELECT platform FROM {$table} WHERE platform = 'harbor'")->fetch(\PDO::FETCH_ASSOC);
$registryRow = $pdo->prepare("SELECT platform FROM {$table} WHERE platform = 'registry'")->fetch(\PDO::FETCH_ASSOC);

if ($harborRow === false) {
    echo "未找到 platform='harbor' 的镜像仓库行，无需迁移。\n";
    exit(0);
}

if ($registryRow !== false) {
    fwrite(STDERR, "提示：已同时存在 platform='registry' 行，跳过迁移以避免主键冲突。"
        . "请人工确认两条记录，必要时删除多余的 harbor 行。\n");
    exit(0);
}

$stmt = $pdo->prepare("UPDATE {$table} SET platform = 'registry' WHERE platform = 'harbor'");
$stmt->execute();
$affected = $stmt->rowCount();

echo "已迁移 {$affected} 行：platform 'harbor' → 'registry'（数据原样保留）。\n";
echo "提示：可在后台「平台连接」确认镜像仓库已显示为 Container Registry。\n";
exit(0);
