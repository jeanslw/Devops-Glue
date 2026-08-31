<?php

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response as SlimResponse;

class CorsMiddleware implements MiddlewareInterface
{
    private array $allowedOrigins;
    private array $allowedMethods;
    private array $allowedHeaders;

    public function __construct(array $config = [])
    {
        $this->allowedOrigins = $config['allowed_origins'] ?? ['*'];
        $this->allowedMethods = $config['allowed_methods'] ?? ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS'];
        $this->allowedHeaders = $config['allowed_headers'] ?? ['Content-Type', 'Authorization', 'X-Requested-With', 'Accept'];
    }

    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        $origin = $request->getHeaderLine('Origin');

        // 预检请求直接返回
        if ($request->getMethod() === 'OPTIONS') {
            $response = new SlimResponse(204);
            return $this->addCorsHeaders($response, $origin);
        }

        $response = $handler->handle($request);
        return $this->addCorsHeaders($response, $origin);
    }

    private function addCorsHeaders(Response $response, string $origin): Response
    {
        // 仅在允许所有来源或请求 Origin 在白名单中时设置 CORS 头。
        // 不匹配时不返回任何 CORS 头，避免误放行非法 Origin。
        if (in_array('*', $this->allowedOrigins)) {
            $response = $response->withHeader('Access-Control-Allow-Origin', '*');
        } elseif (!empty($origin) && in_array($origin, $this->allowedOrigins, true)) {
            $response = $response->withHeader('Access-Control-Allow-Origin', $origin);
        } else {
            // Origin 不匹配且非通配符，直接返回原始响应（不添加 CORS 头）
            return $response;
        }

        return $response
            ->withHeader('Access-Control-Allow-Methods', implode(', ', $this->allowedMethods))
            ->withHeader('Access-Control-Allow-Headers', implode(', ', $this->allowedHeaders))
            ->withHeader('Access-Control-Max-Age', '86400');
    }
}
