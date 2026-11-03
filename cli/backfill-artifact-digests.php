<?php
/**
 * 回填 ci_pipeline_artifacts.digest（从镜像仓库按 repository+tag 解析）
 *
 * 用法：
 *   php cli/backfill-artifact-digests.php --dry-run
 *   php cli/backfill-artifact-digests.php
 *   php cli/backfill-artifact-digests.php --limit=100
 *
 * 退出码：0 = 完成；1 = 致命错误。
 */
require __DIR__ . '/bootstrap.php';

use App\Config\AppConfig;
use App\Service\AppSettingRepository;
use App\Service\Database;
use App\Service\HarborService;
use App\Service\I18nService;
use App\Service\PlatformConnectionRepository;
use App\Service\Registry\DockerRegistryProvider;
use App\Service\Registry\HarborProvider;
use GuzzleHttp\Client;

$dryRun = in_array('--dry-run', $argv, true);
$limit  = 0;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--limit=')) {
        $limit = max(0, (int) substr($arg, 8));
    }
}

try {
    $pdo = Database::getPdo();
} catch (\Throwable $e) {
    fwrite(STDERR, "错误：数据库初始化失败: {$e->getMessage()}\n");
    exit(1);
}

// 确保 digest 列存在（幂等）
try {
    $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
    if ($driver === 'mysql') {
        $chk = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'ci_pipeline_artifacts'
               AND COLUMN_NAME = 'digest'"
        )->fetchColumn();
        if ((int) $chk === 0) {
            $pdo->exec('ALTER TABLE ci_pipeline_artifacts ADD COLUMN digest VARCHAR(255) DEFAULT NULL');
            echo "已添加列 digest (MySQL)\n";
        }
    } else {
        $cols = $pdo->query('PRAGMA table_info(ci_pipeline_artifacts)')->fetchAll();
        $names = array_map(fn($r) => $r['name'] ?? '', $cols);
        if (!in_array('digest', $names, true)) {
            $pdo->exec('ALTER TABLE ci_pipeline_artifacts ADD COLUMN digest TEXT DEFAULT NULL');
            echo "已添加列 digest (SQLite)\n";
        }
    }
} catch (\Throwable $e) {
    fwrite(STDERR, "警告：检查/添加 digest 列失败: {$e->getMessage()}\n");
}

$registry = null;
$regType = (new AppSettingRepository($pdo))->getRegistryType();
$platformKey = $regType === 'registry' ? 'registry' : 'harbor';
$registryCfg = (new PlatformConnectionRepository($pdo))->resolveConnections()[$platformKey] ?? [];
$registryUrl = rtrim((string) ($registryCfg['url'] ?? ''), '/');
$i18n = new I18nService(__DIR__ . '/../lang');
if ($registryUrl !== '') {
    if ((new AppSettingRepository($pdo))->getRegistryType() === 'registry') {
        $registryClient = new Client([
            'base_uri'        => $registryUrl . '/',
            'headers'         => ['Accept' => 'application/json'],
            'connect_timeout' => 5,
            'timeout'         => 15,
            'http_errors'     => false,
        ]);
        $registry = new DockerRegistryProvider(
            $registryClient,
            $i18n,
            (string) ($registryCfg['username'] ?? ''),
            (string) ($registryCfg['password'] ?? ''),
            null
        );
    } else {
        $harborClient = new Client([
            'base_uri'        => $registryUrl,
            'auth'            => [
                $registryCfg['username'] ?? 'admin',
                $registryCfg['password'] ?? '',
            ],
            'headers'         => ['Accept' => 'application/json'],
            'connect_timeout' => 5,
            'timeout'         => 15,
        ]);
        $registry = new HarborProvider(new HarborService($harborClient, $pdo, $i18n, null));
    }
}

if ($registry === null) {
    echo "跳过：镜像仓库未配置（平台连接 URL 为空）。\n";
    exit(0);
}

$sql = 'SELECT id, repository, tag FROM ' . AppConfig::TABLE_PIPELINE_ARTIFACTS
    . " WHERE (digest IS NULL OR digest = '') AND tag != '' AND repository != ''"
    . ' ORDER BY id ASC';
if ($limit > 0) {
    $sql .= ' LIMIT ' . (int) $limit;
}

try {
    $rows = $pdo->query($sql)->fetchAll();
} catch (\Throwable $e) {
    fwrite(STDERR, "错误：查询失败: {$e->getMessage()}\n");
    exit(1);
}

$total   = count($rows);
$updated = 0;
$skipped = 0;
$failed  = 0;

echo ($dryRun ? '[dry-run] ' : '') . "待回填行数: {$total}\n";

$updateStmt = $pdo->prepare(
    'UPDATE ' . AppConfig::TABLE_PIPELINE_ARTIFACTS . ' SET digest = ?, updated_at = ' . Database::sqlNow() . ' WHERE id = ?'
);

foreach ($rows as $row) {
    $id   = (int) $row['id'];
    $repo = (string) $row['repository'];
    $tag  = (string) $row['tag'];
    $digest = '';
    try {
        $details = $registry->getTagDetails($repo);
        if (is_array($details) && !isset($details['error'])) {
            foreach ($details as $d) {
                if (!is_array($d)) {
                    continue;
                }
                $name = (string) ($d['name'] ?? $d['tag'] ?? '');
                if ($name === $tag) {
                    $digest = trim((string) ($d['digest'] ?? ''));
                    break;
                }
            }
        }
    } catch (\Throwable $e) {
        $failed++;
        fwrite(STDERR, "失败 id={$id} {$repo}:{$tag} — {$e->getMessage()}\n");
        continue;
    }

    if ($digest === '') {
        $skipped++;
        echo "跳过 id={$id} {$repo}:{$tag}（仓库无此 tag 或无 digest）\n";
        continue;
    }

    if ($dryRun) {
        echo "将更新 id={$id} {$repo}:{$tag} → {$digest}\n";
        $updated++;
        continue;
    }

    $updateStmt->execute([$digest, $id]);
    $updated++;
    echo "已更新 id={$id} {$repo}:{$tag} → {$digest}\n";
}

echo "完成：updated={$updated} skipped={$skipped} failed={$failed} total={$total}\n";
exit($failed > 0 && $updated === 0 ? 1 : 0);
