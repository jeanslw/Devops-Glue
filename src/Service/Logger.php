<?php

namespace App\Service;

/**
 * 轻量级 PSR-3 风格文件日志
 * 支持 JSON 格式化，按天滚动
 */
class Logger
{
    private string $logPath;
    private string $level;
    private bool $enabled;
    private int $retainDays;

    private const LEVELS = [
        'debug'   => 0,
        'info'    => 1,
        'warning' => 2,
        'error'   => 3,
    ];

    public function __construct(string $logPath = '', string $level = 'info', int $retainDays = 30)
    {
        $this->enabled = !empty($logPath);
        if ($this->enabled) {
            $this->logPath = rtrim($logPath, '/\\') . '/';
            if (!is_dir($this->logPath)) {
                @mkdir($this->logPath, 0755, true);
            }
        }
        $this->level = $level;
        $this->retainDays = max(0, $retainDays);
    }

    /**
     * @param array<string,mixed> $context
     */
    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    /**
     * @param array<string,mixed> $context
     */
    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    /**
     * @param array<string,mixed> $context
     */
    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    /**
     * @param array<string,mixed> $context
     */
    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    /**
     * @param string              $level
     * @param string              $message
     * @param array<string,mixed> $context
     */
    private function log(string $level, string $message, array $context = []): void
    {
        if (!$this->enabled) {
            return;
        }

        if (self::LEVELS[$level] < self::LEVELS[$this->level]) {
            return;
        }

        $entry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'level'     => strtoupper($level),
            'message'   => $message,
        ];
        if (!empty($context)) {
            $entry['context'] = $context;
        }

        $line = json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n";
        $file = $this->logPath . 'app-' . date('Y-m-d') . '.log';
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);

        // 应用层日志轮转：写完顺带清理过期文件（节流，避免每个请求都扫目录）
        $this->maybePurgeOldLogs();
    }

    /**
     * 节流触发旧日志清理：每进程最多每小时执行一次。
     * 裸机/Docker 通吃——不依赖 logrotate 或 Docker 日志驱动。
     */
    private function maybePurgeOldLogs(): void
    {
        if ($this->retainDays <= 0) {
            return;
        }
        static $lastPurgeAt = 0;
        $now = time();
        if ($now - $lastPurgeAt < 3600) {
            return;
        }
        $lastPurgeAt = $now;
        $this->purgeOldLogs();
    }

    /**
     * 删除超过保留天数的 app-*.log 旧文件（按文件名日期切分，按 mtime 兜底判定）。
     * retainDays <= 0 或未启用时不清；供测试/运维直接调用。
     */
    public function purgeOldLogs(): void
    {
        if (!$this->enabled || $this->retainDays <= 0) {
            return;
        }
        $cutoff = time() - $this->retainDays * 86400;
        $files  = glob($this->logPath . 'app-*.log') ?: [];
        foreach ($files as $f) {
            $mtime = @filemtime($f);
            if ($mtime !== false && $mtime < $cutoff) {
                @unlink($f);
            }
        }
    }
}
