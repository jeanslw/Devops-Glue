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
        self::error($e->getMessage(), ['trace' => $e->getTraceAsString()]);
    }

    /**
     * 记录一条友好错误日志（message + 简要 context，不携带堆栈）。
     * 若 Logger 未启用，回退到 error_log。
     *
     * @param array<string,mixed> $context 日志上下文
     */
    public static function error(string $message, array $context = []): void
    {
        self::write('error', $message, $context);
    }

    /**
     * 记录一条警告日志（「需要人关注但非故障」的事件，如检测到代码降级回滚）。
     * 日志级别按 APP_DEBUG 门控：true=debug / false=info，两种级别都会保留 warning。
     *
     * @param array<string,mixed> $context 日志上下文
     */
    public static function warning(string $message, array $context = []): void
    {
        self::write('warning', $message, $context);
    }

    /**
     * 统一写日志：Logger 未启用 / 初始化失败 / 写入失败时一律回退 error_log，
     * 绝不因日志问题阻塞调用方（迁移、引导这些关键路径都依赖本方法）。
     *
     * @param string              $level   'error' | 'warning'
     * @param array<string,mixed> $context 日志上下文
     */
    private static function write(string $level, string $message, array $context = []): void
    {
        try {
            if (!self::$initialized) {
                self::initLogger();
            }
            if (self::$logger !== null) {
                if ($level === 'warning') {
                    self::$logger->warning($message, $context);
                } else {
                    self::$logger->error($message, $context);
                }
                return;
            }
            // 若 Logger 初始化失败或未启用，回退到 error_log
            $suffix = empty($context) ? '' : ' ' . json_encode($context, JSON_UNESCAPED_UNICODE);
            error_log($message . $suffix);
        } catch (\Throwable $inner) {
            // 任何失败都回退到 PHP 内置日志，避免阻塞原有流程
            error_log($message);
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
            // 日志级别由 APP_DEBUG 门控（与环境无关）：
            //   APP_DEBUG=true  -> 'debug'：debug/info/warning/error 全记（开发联调用）
            //   APP_DEBUG=false -> 'info'：保留 info/warning/error（生产诊断信息不丢，仅过滤 debug 噪声）
            $level = !empty($settings['app']['debug']) ? 'debug' : 'info';
            $retainDays = (int) ($settings['app']['log_retain_days'] ?? 30);
            self::$logger = new Logger($logPath, $level, $retainDays);
        } catch (\Throwable $e) {
            // Logger 初始化失败，保持 null，后续调用走 error_log 分支
            self::$logger = null;
        }
    }
}
