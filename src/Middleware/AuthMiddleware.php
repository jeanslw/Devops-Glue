<?php
namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Psr\Http\Message\ResponseFactoryInterface;
use App\Config\AppConfig;
use App\Service\I18nService;
use App\Service\TokenService;

/**
 * Bearer Token 鉴权中间件
 * 验证通过后将 currentUser / currentRole / userPermissions 写入 request attribute，供 Controller 读取
 */
class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private I18nService $i18n,
        private ResponseFactoryInterface $responseFactory,
        private TokenService $tokenService
    ) {}

    public function process(Request $request, Handler $handler): Response
    {
        $header = $request->getHeaderLine('Authorization');
        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
            return $this->unauthorized($request, 'auth.not_logged_in');
        }
        $token = $m[1];

        // 验证 cache 中的随机 token
        $result = $this->tokenService->validate($token);
        if ($result) {
            $permissions = $this->tokenService->loadPermissions($result['role']);
            return $handler->handle(
                $request
                    ->withAttribute('currentUser', $result['user'])
                    ->withAttribute('currentRole', $result['role'])
                    ->withAttribute('userPermissions', $permissions)
            );
        }

        // 未设任何密码且无管理员用户则放行（首次初始化场景）
        if ($this->tokenService->isFirstInit()) {
            return $handler->handle(
                $request
                    ->withAttribute('currentUser', '')
                    ->withAttribute('currentRole', AppConfig::ROLE_ADMIN)
                    ->withAttribute('userPermissions', [])
            );
        }

        return $this->unauthorized($request, 'auth.token_invalid');
    }

    private function unauthorized(Request $request, string $messageKey): Response
    {
        $locale = $this->i18n->detectLocale($request);
        $message = $this->i18n->trans($messageKey, [], $locale);

        $response = $this->responseFactory->createResponse();
        $response->getBody()->write(json_encode(['code' => 401, 'message' => $message], JSON_UNESCAPED_UNICODE));
        return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
    }
}
