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
    ) {
    }

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

        // 回退：验证 API token（服务账号 / 第三方调用），命中后做 scope 校验
        $api = $this->tokenService->validateApiToken($token);
        if ($api !== null) {
            $required = AppConfig::resolveRequiredScope(
                $request->getMethod(),
                $request->getUri()->getPath()
            );

            // 管理端点等不允许 API token 访问 → 403（fail-closed）
            if ($required === null) {
                return $this->forbidden($request, 'api_token.scope_forbidden');
            }
            // 具体 scope：token 必须持有
            if ($required !== '*' && !in_array($required, $api['scopes'], true)) {
                return $this->forbidden($request, 'api_token.scope_forbidden');
            }

            // scope → 控制器内二次校验所需权限（如 build.write/harbor.scan → ci.trigger）
            $permissions = [];
            foreach ($api['scopes'] as $scope) {
                foreach (AppConfig::API_SCOPE_PERMS[$scope] ?? [] as $perm) {
                    if (!in_array($perm, $permissions, true)) {
                        $permissions[] = $perm;
                    }
                }
            }

            return $handler->handle(
                $request
                    ->withAttribute('currentUser', $api['user'])
                    ->withAttribute('currentRole', AppConfig::ROLE_API_TOKEN)
                    ->withAttribute('userPermissions', $permissions)
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

    private function forbidden(Request $request, string $messageKey): Response
    {
        $locale = $this->i18n->detectLocale($request);
        $message = $this->i18n->trans($messageKey, [], $locale);

        $response = $this->responseFactory->createResponse();
        $response->getBody()->write(json_encode(['code' => 403, 'message' => $message], JSON_UNESCAPED_UNICODE));
        return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
    }
}
