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

    /**
     * 请求级信封：每请求通过 setRequestContext() 注入，之后所有日志自动带上，
     * 无需在每个调用点重复传 request_id/path/method。CLI 不设置则为 null。
     * @var array{request_id:?string,path:?string,method:?string}
     */
    private static array $requestContext = ['request_id' => null, 'path' => null, 'method' => null];

    public static function setRequestContext(string $requestId, string $path = '', string $method = ''): void
    {
        self::$requestContext = [
            'request_id' => $requestId === '' ? null : $requestId,
            'path'       => $path === '' ? null : $path,
            'method'     => $method === '' ? null : $method,
        ];
    }

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

        // 把已知信封字段提升到顶层，便于日志聚合/检索（不再埋在 context 里）
        $promote = ['error_code', 'exception_class', 'location', 'path', 'method', 'request_id', 'correlation_id'];
        foreach ($promote as $key) {
            if (isset($context[$key])) {
                $entry[$key] = $context[$key];
                unset($context[$key]);
            }
        }
        // 请求级信封（来自 setRequestContext），调用点未显式覆盖时自动带上
        foreach (['request_id', 'path', 'method'] as $key) {
            if (!isset($entry[$key]) && self::$requestContext[$key] !== null) {
                $entry[$key] = self::$requestContext[$key];
            }
        }

        if (!empty($context)) {
            $entry['context'] = $context;
        }

        $line = json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n";
        $file = $this->logPath . 'app-' . date('Y-m-d') . '.log';
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);


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
