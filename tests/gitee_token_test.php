<?php
require __DIR__ . '/../vendor/autoload.php';
use App\Config\AppConfig;
use App\Service\Logger;
use App\Service\Git\GiteeService;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../config');
$dotenv->load();

$configArray = require __DIR__ . '/../config/settings.php';
$pdo = null;
$appConfig = new AppConfig($configArray, $pdo);
$gitee = $appConfig->getGiteeConfig();
$base = $gitee['base_url'] ?? ($gitee['api_base_url'] ?? 'https://gitee.com/api/v5');
$token = $gitee['token'] ?? '';

echo "Gitee base: {$base}\n";
echo "Gitee token present: " . (!empty($token) ? 'yes' : 'no') . "\n";

$logDir = __DIR__ . '/tmp_logs';
@mkdir($logDir, 0755, true);
$logger = new Logger($logDir, 'debug');
$svc = new GiteeService($base, $token, $logger);
$repo = 'lucky-boy1/git_one_app';
echo "Calling getBranches for {$repo}...\n";
$branches = $svc->getBranches($repo);
echo "Result count: " . count($branches) . "\n";
if (!empty($branches)) echo json_encode($branches) . "\n";
$logFile = $logDir . '/app-' . date('Y-m-d') . '.log';
if (file_exists($logFile)) {
    echo "\nLog tail:\n";
    $lines = array_slice(file($logFile), -20);
    foreach ($lines as $l) echo $l;
}
