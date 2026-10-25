<?php

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response as SlimResponse;

class CorsMiddleware implements MiddlewareInterface
{
    /** @var list<string> 允许的来源列表 */
    private array $allowedOrigins;
    /** @var list<string> 允许的 HTTP 方法列表 */
    private array $allowedMethods;
    /** @var list<string> 允许的请求头列表 */
    private array $allowedHeaders;

    /**
     * @param array<string,mixed> $config CORS 配置（allowed_origins / allowed_methods / allowed_headers）
     */
    public function __construct(array $config = [])
    {
        $this->allowedOrigins = $config['allowed_origins'] ?? ['*'];
        $this->allowedMethods = $config['allowed_methods'] ?? ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS'];
        $this->allowedHeaders = $config['allowed_headers'] ?? ['Content-Type', 'Authorization', 'X-Requested-With', 'Accept'];
    }

    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        $origin = $request->getHeaderLine('Origin');


        if ($request->getMethod() === 'OPTIONS') {
            $response = new SlimResponse(204);
            return $this->addCorsHeaders($response, $origin);
        }

        $response = $handler->handle($request);
        return $this->addCorsHeaders($response, $origin);
    }

    private function addCorsHeaders(Response $response, string $origin): Response
    {


        if (in_array('*', $this->allowedOrigins)) {
            $response = $response->withHeader('Access-Control-Allow-Origin', '*');
        } elseif (!empty($origin) && in_array($origin, $this->allowedOrigins, true)) {
            $response = $response->withHeader('Access-Control-Allow-Origin', $origin);
        } else {
            return $response;
        }

        return $response
            ->withHeader('Access-Control-Allow-Methods', implode(', ', $this->allowedMethods))
            ->withHeader('Access-Control-Allow-Headers', implode(', ', $this->allowedHeaders))
            ->withHeader('Access-Control-Max-Age', '86400');
    }
}
