<?php
require __DIR__ . '/../vendor/autoload.php';
use App\Service\Logger;
use App\Service\Git\GiteeService;

$logDir = __DIR__ . '/tmp_logs';
@mkdir($logDir, 0755, true);
$logger = new Logger($logDir, 'debug');
$svc = new GiteeService('https://gitee.com/api/v5', '', $logger);

$repo = 'lucky-boy1/git_one_app';
echo "Calling GiteeService::getBranches({$repo}) with empty token...\n";
$branches = $svc->getBranches($repo);
echo "Result: " . json_encode($branches) . "\n";

$logFile = $logDir . '/app-' . date('Y-m-d') . '.log';
if (file_exists($logFile)) {
    echo "\nLog contents:\n";
    echo file_get_contents($logFile);
} else {
    echo "\nNo log file created.\n";
}
