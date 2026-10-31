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
        self::error($e->getMessage(), [
            'exception_class' => get_class($e),
            'location'        => $e->getFile() . ':' . $e->getLine(),
            'trace'           => $e->getTraceAsString(),
        ]);
    }

    /**
     * 注入当前请求信封（request_id/path/method），委托给底层 Logger 静态上下文。
     * 在 Bootstrap 每个 HTTP 请求入口调用一次即可，CLI 可不调用。
     * @see \App\Service\Logger::setRequestContext()
     */
    public static function setRequestContext(string $requestId, string $path = '', string $method = ''): void
    {
        Logger::setRequestContext($requestId, $path, $method);
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
     * 记录一条警告日志（需要关注但不算故障的事件，如检测到代码回滚降级）。
     * 日志级别按 APP_DEBUG 门控：true=debug / false=info，两种级别都会保留 warning。
     *
     * @param array<string,mixed> $context 日志上下文
     */
    public static function warning(string $message, array $context = []): void
    {
        self::write('warning', $message, $context);
    }

    /**
     * @internal 测试用：丢弃已缓存的 Logger 单例，使后续对 LOG_PATH 的改动生效
     * （单例按「首个 LOG_PATH」绑定，同一进程内换目录不会再初始化）。业务代码勿调用。
     */
    public static function reset(): void
    {
        self::$logger = null;
        self::$initialized = false;
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

            $suffix = empty($context) ? '' : ' ' . json_encode($context, JSON_UNESCAPED_UNICODE);
            error_log($message . $suffix);
        } catch (\Throwable $inner) {
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



            $level = !empty($settings['app']['debug']) ? 'debug' : 'info';
            $retainDays = (int) ($settings['app']['log_retain_days'] ?? 30);
            self::$logger = new Logger($logPath, $level, $retainDays);
        } catch (\Throwable $e) {
            self::$logger = null;
        }
    }
}
