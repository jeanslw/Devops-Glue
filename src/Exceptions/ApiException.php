<?php
namespace App\Exceptions;

/**
 * 统一 API 异常类
 * 可在 Controller/Service 中抛出，由全局错误处理器捕获并输出结构化 JSON
 */
class ApiException extends \RuntimeException
{
    private int $statusCode;
    private ?array $context;

    /**
     * @param string $message    错误描述
     * @param int    $statusCode HTTP 状态码，默认 400
     * @param array  $context    附加调试上下文（仅 debug 模式输出）
     */
    public function __construct(string $message = '', int $statusCode = 400, ?array $context = null)
    {
        parent::__construct($message, $statusCode);
        $this->statusCode = $statusCode;
        $this->context = $context;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getContext(): ?array
    {
        return $this->context;
    }
}
