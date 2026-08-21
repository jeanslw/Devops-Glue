<?php
/**
 * 离线清理 ci_pipeline_tags 过期 tag CLI（运维工具，不随 Web 运行）
 *
 * 用法：
 *   php cli/cleanup-pipeline-tags.php
 *
 * 说明：
 *   - 以 Harbor 为唯一真值源：逐条核对 ci_pipeline_tags，Harbor 里已不存在的 tag
 *     对应的记录会被删除。正确性归 Glue（CI 层）维护——按解耦约定 CD 只读 ci_* 表
 *     绝不删（用户可能不启用 CD 系统），故本脚本不依赖 CD 存活。
 *   - 安全不变量：Harbor 未配置 / 不可达 / harbor_repository 或 tag 为空 → 跳过，
 *     绝不误删；只删「Harbor 明确返回了 tag 列表且其中没有这条」的行。
 *   - 幂等：可重复跑、并发跑无害。只碰 ci_pipeline_tags，绝不碰任何 cd_* 表。
 *   - 受后台开关 stale_tag_cleanup_enabled 控制：未开启时直接跳过（退出码 0）。
 *
 * 调度：用宿主 cron / 容器 crontab / Windows 计划任务定时调用即可，与 CD 完全解耦。
 *   例如每小时：0 * * * * php /path/to/cli/cleanup-pipeline-tags.php >> /data/logs/tag-cleanup.log 2>&1
 *
 * 退出码：0 = 完成（含「开关未开启 / Harbor 未配置 / 不可达而安全跳过」）；1 = 致命错误（库连不上/表缺失/异常）。
 */
require __DIR__ . '/../vendor/autoload.php';

use App\Config\AppConfig;
use App\Service\Database;
use App\Service\HarborService;
use App\Service\PipelineTagService;
use GuzzleHttp\Client;

/**
 * 读取配置：优先 $_ENV（phpdotenv 填充），其次真实环境变量 getenv()。
 * 避免 variables_order 不含 E 时 shell 传入的环境变量丢失。
 */
function envVal(string $key, string $default = ''): string
{
    if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
        return (string)$_ENV[$key];
    }
    $v = getenv($key);
    return $v === false ? $default : (string)$v;
}

// ── 1. 加载环境变量（顺序与 Bootstrap 一致：.env → .env.{APP_ENV} → .env.local）──
$baseDir = __DIR__ . '/../config';
Dotenv\Dotenv::createImmutable($baseDir)->load();

$appEnv = envVal('APP_ENV', 'production');
$envFile = $baseDir . '/.env.' . $appEnv;
if (file_exists($envFile)) {
    Dotenv\Dotenv::createUnsafeImmutable($baseDir, '.env.' . $appEnv)->load();
}
$localFile = $baseDir . '/.env.local';
if (file_exists($localFile)) {
    Dotenv\Dotenv::createUnsafeImmutable($baseDir, '.env.local')->load();
}

// ── 2. 连接数据库（逻辑与 config/container.php 一致）──
$driver = strtolower(envVal('DB_DRIVER', 'sqlite'));
// 关键：让 Database::sqlNow()/sqlUpsert() 按实际驱动生成正确 SQL（否则 MySQL 下会误用 sqlite 的 datetime()）
Database::init(['driver' => $driver]);
try {
    if ($driver === 'mysql') {
        $dsn = 'mysql:host=' . envVal('DB_HOST', '127.0.0.1')
            . ';port=' . envVal('DB_PORT', '3306')
            . ';dbname=' . envVal('DB_NAME', 'devops_glue')
            . ';charset=' . envVal('DB_CHARSET', 'utf8mb4');
        $pdo = new \PDO($dsn, envVal('DB_USER', 'root'), envVal('DB_PASS'), [
            \PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES ' . envVal('DB_CHARSET', 'utf8mb4'),
        ]);
    } else {
        $path = envVal('DB_PATH', $baseDir . '/data/data.db');
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        $pdo = new \PDO('sqlite:' . $path);
        $pdo->exec('PRAGMA journal_mode=WAL');
    }
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    fwrite(STDERR, "错误：数据库连接失败（driver={$driver}）: {$e->getMessage()}\n");
    exit(1);
}

// ── 3. 校验表存在（避免连错库误操作）──
try {
    $pdo->query('SELECT 1 FROM ' . AppConfig::TABLE_PIPELINE_TAGS . ' LIMIT 1');
} catch (\Throwable $e) {
    fwrite(STDERR, "错误：{$driver} 库缺少 " . AppConfig::TABLE_PIPELINE_TAGS . " 表（未初始化？）。\n");
    exit(1);
}

// ── 3.5 开关检查：后台「清理过期 tag」未开启则安全跳过（默认关闭，删除不可逆）──
try {
    $cleanupRow = $pdo->query("SELECT value FROM " . AppConfig::TABLE_APP_SETTINGS . " WHERE setting_key = 'stale_tag_cleanup_enabled'")->fetch();
    $cleanupEnabled = $cleanupRow && $cleanupRow['value'] === '1';
} catch (\Throwable $e) {
    $cleanupEnabled = false; // 表缺失/查询异常 → 视为关闭，安全跳过
}
if (!$cleanupEnabled) {
    echo "跳过：后台「清理过期 tag」开关未开启（stale_tag_cleanup_enabled=0）。\n";
    exit(0);
}

// ── 4. 构造 HarborService（未配置时传 null，服务会安全跳过）──
$harbor = null;
$harborUrl = rtrim(envVal('HARBOR_BASE_URL'), '/');
if ($harborUrl !== '') {
    $harborClient = new Client([
        'base_uri' => $harborUrl,
        'auth'     => [
            envVal('HARBOR_USER', 'admin'),
            envVal('HARBOR_PASSWORD'),
        ],
        'headers'         => ['Accept' => 'application/json'],
        'connect_timeout' => 5,   // TCP 握手超时，防止 hang
        'timeout'         => 15,  // 整个请求超时
    ]);
    $harbor = new HarborService($harborClient, $pdo, null);
}

// ── 5. 执行清理 ──
if ($harbor === null) {
    echo "跳过：Harbor 未配置（HARBOR_BASE_URL 为空），ci_pipeline_tags 未做清理。\n";
    exit(0);
}

try {
    $svc  = new PipelineTagService($pdo, $harbor);
    $stat = $svc->cleanupStaleTags();
} catch (\Throwable $e) {
    fwrite(STDERR, "错误：清理失败: {$e->getMessage()}\n");
    exit(1);
}

echo "✓ 清理完成：删除 {$stat['deleted']} 条"
    . "（核对 {$stat['checked']} / Harbor 不可达跳过 {$stat['unreachable']} / 空 harbor_repository 或 tag 跳过 {$stat['unverifiable']}）\n";
exit(0);
