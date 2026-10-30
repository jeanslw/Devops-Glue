<?php

namespace App;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Middleware\ErrorMiddleware;
use App\Exceptions\ApiException;

/**
 * 错误处理器工厂
 * 负责注册所有异常类型（含 ApiException）的友好处理
 * 将 index.php 中的错误处理逻辑抽离，便于测试与维护
 */
class ErrorHandlerFactory
{
    /**
     * 注册错误处理器到 ErrorMiddleware
     * @param ErrorMiddleware $errorMiddleware
     * @param bool $appDebug 是否显示详细错误
     * @param callable $isApiRequest 判断是否 API 请求
     * @param array<string,array<int,string>> $errorMessages 友好错误文案（语言 => 状态码 => 文案）
     * @param callable $resolveErrorLocale 错误页面语言检测
     * @param ResponseFactoryInterface $responseFactory
     */
    public static function register(
        ErrorMiddleware $errorMiddleware,
        bool $appDebug,
        callable $isApiRequest,
        array $errorMessages,
        callable $resolveErrorLocale,
        ResponseFactoryInterface $responseFactory
    ): void {

        $errorMiddleware->setDefaultErrorHandler(function ($request, $exception, $displayErrorDetails) use ($isApiRequest, $errorMessages, $resolveErrorLocale, $responseFactory) {
            $response = $responseFactory->createResponse();
            $code = 500;
            $message = null;


            if ($exception instanceof ApiException) {
                $code = $exception->getStatusCode();
                $message = $exception->getMessage();
            } elseif ($exception instanceof \Slim\Exception\HttpException) {
                $c = $exception->getCode();
                $code = ($c >= 400 && $c <= 599) ? $c : 500;
            }

            self::logServerError($request, $exception instanceof \Throwable ? $exception : null, $code);

            $lang = $resolveErrorLocale($request);

            $finalMessage = $message ?? ($errorMessages[$lang][$code] ?? $errorMessages[$lang][500]);

            if ($isApiRequest($request)) {
                $payload = ['code' => $code, 'message' => $finalMessage];
                if ($displayErrorDetails && $exception instanceof \Throwable) {
                    $payload['error'] = $exception->getMessage();
                    $payload['file']  = $exception->getFile() . ':' . $exception->getLine();
                    $payload['trace'] = explode("\n", $exception->getTraceAsString());
                }
                $response->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_UNICODE));
                return $response->withStatus($code)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }

            $detail = null;
            if ($displayErrorDetails && $exception instanceof \Throwable) {
                $detail = $exception->getMessage();
            }
            return self::renderErrorHtml($request, $code, $detail, $resolveErrorLocale, $responseFactory);
        });

        // ── 注册常见 HTTP 异常的友好处理 ──
        // 注：Slim 4 仅内置了常用 HTTP 异常类，其余状态码走默认错误处理器
        $httpExceptions = [
            \Slim\Exception\HttpBadRequestException::class          => 400,
            \Slim\Exception\HttpUnauthorizedException::class        => 401,
            \Slim\Exception\HttpForbiddenException::class           => 403,
            \Slim\Exception\HttpNotFoundException::class            => 404,
            \Slim\Exception\HttpMethodNotAllowedException::class    => 405,
            \Slim\Exception\HttpTooManyRequestsException::class     => 429,
            \Slim\Exception\HttpInternalServerErrorException::class => 500,
            \Slim\Exception\HttpNotImplementedException::class      => 501,
        ];

        foreach ($httpExceptions as $exceptionClass => $statusCode) {
            $errorMiddleware->setErrorHandler($exceptionClass, function ($request, $exception, $displayErrorDetails) use ($isApiRequest, $errorMessages, $resolveErrorLocale, $statusCode, $responseFactory) {
                $response = $responseFactory->createResponse();
                self::logServerError($request, $exception instanceof \Throwable ? $exception : null, $statusCode);
                $lang = $resolveErrorLocale($request);
                $message = $errorMessages[$lang][$statusCode] ?? $errorMessages[$lang][500];

                if ($isApiRequest($request)) {
                    $payload = ['code' => $statusCode, 'message' => $message];
                    if ($displayErrorDetails && $exception instanceof \Throwable) {
                        $payload['error'] = $exception->getMessage();
                    }
                    $response->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_UNICODE));
                    return $response->withStatus($statusCode)->withHeader('Content-Type', 'application/json; charset=utf-8');
                }

                $detail = null;
                if ($displayErrorDetails && $exception instanceof \Throwable) {
                    $detail = $exception->getMessage();
                }
                return self::renderErrorHtml($request, $statusCode, $detail, $resolveErrorLocale, $responseFactory);
            });
        }
    }

    /**
     * 记录服务端错误（5xx）。
     *
     * 背景：Slim 的 ErrorMiddleware 只在**默认 handler**（Slim\Handlers\ErrorHandler）里落日志，
     * 而本工厂用 setDefaultErrorHandler() 整体替换了它 —— 若不在这里补一行日志，
     * 所有未捕获异常都会「返回 500 却在日志里查无此事」（例如 /oauth/token 的私钥解析失败，
     * 下游只看到「统一登录服务暂时不可用」，几乎无法定位）。
     *
     * 只记 5xx：4xx（未授权、参数错误等）属预期内的客户端错误，记 error 级只会刷屏。
     *
     * @param mixed $request 调用方为 Slim 错误处理闭包，形参无类型（此处做 instanceof 收窄）
     */
    private static function logServerError($request, ?\Throwable $exception, int $code): void
    {
        if ($code < 500 || $exception === null) {
            return;
        }

        $context = [
            'exception' => $exception::class,
            'file'      => $exception->getFile() . ':' . $exception->getLine(),
            'trace'     => $exception->getTraceAsString(),
        ];
        if ($request instanceof ServerRequestInterface) {
            $context['method'] = $request->getMethod();
            $context['path']   = $request->getUri()->getPath();
        }

        \App\Helper\Log::error($exception->getMessage(), $context);
    }

    private static function renderErrorHtml(ServerRequestInterface $request, int $code, ?string $detail, callable $resolveErrorLocale, ResponseFactoryInterface $responseFactory): \Psr\Http\Message\ResponseInterface
    {
        $response = $responseFactory->createResponse();
        $lang = $resolveErrorLocale($request);
        $htmlFile = __DIR__ . '/../templates/error.html';
        if (file_exists($htmlFile)) {
            $html = (string) file_get_contents($htmlFile);
            $html = str_replace('{{CODE}}', (string) $code, $html);
            $html = str_replace('{{LANG}}', $lang, $html);
            $html = str_replace('{{DETAIL}}', $detail ? htmlspecialchars($detail, ENT_QUOTES, 'UTF-8') : '', $html);
            $response->getBody()->write($html);
            return $response->withStatus($code)->withHeader('Content-Type', 'text/html; charset=utf-8');
        }

        $fallback = '<!doctype html><html lang="' . ($lang === 'en' ? 'en' : 'zh-CN') . '"><head><meta charset="utf-8"><title>' . $code . '</title></head><body style="font-family:sans-serif;padding:40px;text-align:center;"><h1>' . $code . '</h1><p>' . ($detail ?: 'Error') . '</p></body></html>';
        $response->getBody()->write($fallback);
        return $response->withStatus($code)->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
