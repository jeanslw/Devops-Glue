<?php

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Psr\Http\Message\ResponseFactoryInterface;
use App\Config\AppConfig;
use App\Exceptions\ApiException;
use App\Helper\ClientIp;
use App\Service\ApiAccessLogRepository;
use App\Service\AppSettingRepository;
use App\Service\I18nService;
use App\Service\Logger;
use App\Service\TokenService;
use App\Support\ApiScopeResolver;

/**
 * Bearer Token 鉴权中间件
 * 验证通过后将 currentUser / currentRole / userPermissions 写入 request attribute，供 Controller 读取
 *
 * 另负责 API token 调用审计：只记录以 API token 身份发出的请求（含鉴权拒绝），
 * 交互式登录会话不记（其写操作已由 ci_operation_logs 覆盖）。
 * 审计写入的异常一律忽略，任何日志故障都不得影响主请求。
 */
class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private I18nService $i18n,
        private ResponseFactoryInterface $responseFactory,
        private TokenService $tokenService,
        private ?ApiAccessLogRepository $apiLogs = null,
        private ?Logger $logger = null,
        private int $trustedProxyHops = 0,
        private ?AppSettingRepository $appSettings = null
    ) {
    }

    public function process(Request $request, Handler $handler): Response
    {
        $header = $request->getHeaderLine('Authorization');
        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
            return $this->unauthorized($request, 'auth.not_logged_in');
        }
        $token = $m[1];


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



        $startedAt = hrtime(true);
        $api = $this->tokenService->validateApiToken($token);

        if ($api === null) {
            $response = $this->unauthorized($request, 'auth.token_invalid');
            $this->recordAccess($request, $response, $startedAt, null, 'invalid_token');
            return $response;
        }

        $required = ApiScopeResolver::resolve(
            $request->getMethod(),
            $request->getUri()->getPath()
        );


        if ($required === null) {
            $response = $this->forbidden($request, 'api_token.scope_forbidden');
            $this->recordAccess($request, $response, $startedAt, $api, 'scope_forbidden');
            return $response;
        }

        if ($required !== '*' && !in_array($required, $api['scopes'], true)) {
            $response = $this->forbidden($request, 'api_token.scope_forbidden');
            $this->recordAccess($request, $response, $startedAt, $api, 'missing_scope:' . $required);
            return $response;
        }


        $permissions = [];
        foreach ($api['scopes'] as $scope) {
            foreach (AppConfig::API_SCOPE_PERMS[$scope] ?? [] as $perm) {
                if (!in_array($perm, $permissions, true)) {
                    $permissions[] = $perm;
                }
            }
        }

        $authenticatedRequest = $request
            ->withAttribute('currentUser', $api['user'])
            ->withAttribute('currentRole', AppConfig::ROLE_API_TOKEN)
            ->withAttribute('userPermissions', $permissions)
            ->withAttribute('apiScopes', $api['scopes']);

        try {
            $response = $handler->handle($authenticatedRequest);
        } catch (\Throwable $e) {
            if ($e instanceof ApiException) {
                $statusCode = $e->getStatusCode();
            } elseif ($e instanceof \RuntimeException) {
                $statusCode = 502;
            } else {
                $statusCode = 500;
            }


            $handled = $e instanceof \RuntimeException;
            $errorResponse = $this->responseFactory
                ->createResponse($statusCode)
                ->withHeader('Content-Type', 'application/json');
            $errorResponse->getBody()->write((string) json_encode([
                'code'    => $statusCode,
                'message' => $handled ? $e->getMessage() : 'Internal Server Error',
            ], JSON_UNESCAPED_UNICODE));
            $this->recordAccess($request, $errorResponse, $startedAt, $api, 'exception:' . $e->getMessage());
            if ($handled) {
                return $errorResponse;
            }
            throw $e;
        }

        $this->recordAccess($request, $response, $startedAt, $api, null);
        return $response;
    }

    /**
     * 记录一次 API token 调用（DB + 文件日志双写，写入异常均忽略）。
     *
     * @param array{user:string, token_name:string, scopes:string[]}|null $api 校验出的 token 元信息（失败拒绝时可为 null）
     */
    private function recordAccess(Request $request, Response $response, int|float $startedAt, ?array $api, ?string $denyReason): void
    {
        try {
            $statusCode = $response->getStatusCode();
            if ($denyReason !== null) {
                $result = in_array($statusCode, [401, 403], true) ? 'denied' : 'failure';
                $errorReason = $denyReason;
            } elseif ($statusCode < 400) {
                $result = 'success';
                $errorReason = null;
            } else {
                $result = in_array($statusCode, [401, 403], true) ? 'denied' : 'failure';
                $errorReason = 'http_' . $statusCode;
            }

            $durationMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);
            $scopes = $api !== null ? implode(',', $api['scopes']) : null;
            $entry = [
                'username'     => $api['user'] ?? null,
                'token_name'   => $api['token_name'] ?? null,
                'scopes'       => $scopes,
                'method'       => $request->getMethod(),
                'route'        => $this->resolveRoute($request),
                'status_code'  => $statusCode,
                'result'       => $result,
                'error_reason' => $errorReason,
                'ip'           => ClientIp::resolve($request->getServerParams(), $this->trustedProxyHops),
                'duration_ms'  => $durationMs,
            ];


            $level = $this->appSettings?->getApiAccessLogLevel()
                ?? \App\Config\AppConfig::DEFAULT_API_ACCESS_LOG_LEVEL;
            if (ApiAccessLogRepository::shouldRecord($result, $level)) {
                $this->apiLogs?->record($entry);

                $this->logger?->info('[API调用]', $entry);
            }
        } catch (\Throwable $e) {
            $this->logger?->error('[API调用日志] 记录失败', ['error' => $e->getMessage()]);
        }
    }

    /**
     * 路由模板优先（/api/build/{path}/...），拿不到时对原始路径做轻量归一：
     * 纯数字段替换为 {id}，降低日志基数；其余路径段（组织/项目名）保留可读。
     */
    private function resolveRoute(Request $request): string
    {
        $route = $request->getAttribute(\Slim\Routing\RouteContext::ROUTE);
        if (is_object($route) && method_exists($route, 'getPattern')) {
            $pattern = (string)$route->getPattern();
            if ($pattern !== '') {
                return $pattern;
            }
        }
        $path = $request->getUri()->getPath();
        $segments = explode('/', $path);
        foreach ($segments as $i => $seg) {
            if ($seg !== '' && ctype_digit($seg)) {
                $segments[$i] = '{id}';
            }
        }
        return implode('/', $segments);
    }

    private function unauthorized(Request $request, string $messageKey): Response
    {
        $locale = $this->i18n->detectLocale($request);
        $message = $this->i18n->trans($messageKey, [], $locale);

        $response = $this->responseFactory->createResponse();
        $response->getBody()->write((string) json_encode(['code' => 401, 'message' => $message], JSON_UNESCAPED_UNICODE));
        return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
    }

    private function forbidden(Request $request, string $messageKey): Response
    {
        $locale = $this->i18n->detectLocale($request);
        $message = $this->i18n->trans($messageKey, [], $locale);

        $response = $this->responseFactory->createResponse();
        $response->getBody()->write((string) json_encode(['code' => 403, 'message' => $message], JSON_UNESCAPED_UNICODE));
        return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
    }
}
