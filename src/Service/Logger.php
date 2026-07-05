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

    private const LEVELS = [
        'debug'   => 0,
        'info'    => 1,
        'warning' => 2,
        'error'   => 3,
    ];

    public function __construct(string $logPath = '', string $level = 'info')
    {
        $this->enabled = !empty($logPath);
        if ($this->enabled) {
            $this->logPath = rtrim($logPath, '/\\') . '/';
            if (!is_dir($this->logPath)) {
                @mkdir($this->logPath, 0755, true);
            }
        }
        $this->level = $level;
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

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
    }
}
