<?php
namespace App\Helper;

use App\Service\Logger;

class Log
{
    /**
     * 尝试使用项目 Logger 记录异常；若 Logger 未启用，回退到 error_log。
     */
    public static function exception(\Throwable $e): void
    {
        try {
            $settings = require __DIR__ . '/../../config/settings.php';
            $logPath = $settings['app']['log_path'] ?? '';
            $level = ($settings['app']['env'] ?? 'production') === 'production' ? 'info' : 'debug';
            $logger = new Logger($logPath, $level);
            $logger->error($e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return;
        } catch (\Throwable $inner) {
            // 任何失败都回退到 PHP 内置日志，避免阻塞原有流程
            error_log((string)$e);
        }
    }
}
