<?php

namespace App\Helper;

use App\Service\Logger;

class Log
{
    private static ?Logger $logger = null;
    private static bool $initialized = false;

    /**
     * 尝试使用项目 Logger 记录异常；若 Logger 未启用，回退到 error_log。
     * 单例模式：首次调用时初始化 Logger，后续调用复用同一实例。
     */
    public static function exception(\Throwable $e): void
    {
        try {
            if (!self::$initialized) {
                self::initLogger();
            }
            if (self::$logger !== null) {
                self::$logger->error($e->getMessage(), ['trace' => $e->getTraceAsString()]);
                return;
            }
            // 若 Logger 初始化失败或未启用，回退到 error_log
            error_log((string)$e);
        } catch (\Throwable $inner) {
            // 任何失败都回退到 PHP 内置日志，避免阻塞原有流程
            error_log((string)$e);
        }
    }

    /**
     * 初始化 Logger 实例（单例）
     */
    private static function initLogger(): void
    {
        self::$initialized = true;
        try {
            $settings = require __DIR__ . '/../../config/settings.php';
            $logPath = $settings['app']['log_path'] ?? '';
            $level = ($settings['app']['env'] ?? 'production') === 'production' ? 'info' : 'debug';
            self::$logger = new Logger($logPath, $level);
        } catch (\Throwable $e) {
            // Logger 初始化失败，保持 null，后续调用走 error_log 分支
            self::$logger = null;
        }
    }
}
