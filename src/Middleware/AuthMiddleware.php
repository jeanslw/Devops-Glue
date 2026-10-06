<?php

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Psr\Http\Message\ResponseFactoryInterface;
use App\Config\AppConfig;
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
 * 另负责 API token 调用审计：只记录「以 API token 身份发出」的请求（含鉴权拒绝），
 * 交互式登录会话不记（其写操作已由 ci_operation_logs 覆盖）。
 * 审计写入全程静默降级，任何日志故障都不得影响主请求。
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

        // 验证 cache 中的随机 token（交互式登录会话，不记 API 调用日志）
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

        // 回退：验证 API token（服务账号 / 第三方调用），命中后做 scope 校验。
        // 计时起点放在校验之前：耗时包含 token 解析/查库，慢 DB 也能在审计里暴露。
        $startedAt = hrtime(true);
        $api = $this->tokenService->validateApiToken($token);

        if ($api === null) {
            // 带着 Bearer 来但两种 token 都不合法：可能是过期会话，也可能是伪造 API token。
            // 安全上按「API token 鉴权失败」留痕（不记任何 token 内容）。
            $response = $this->unauthorized($request, 'auth.token_invalid');
            $this->recordAccess($request, $response, $startedAt, null, 'invalid_token');
            return $response;
        }

        $required = ApiScopeResolver::resolve(
            $request->getMethod(),
            $request->getUri()->getPath()
        );

        // 管理端点等不允许 API token 访问 → 403（fail-closed）
        if ($required === null) {
            $response = $this->forbidden($request, 'api_token.scope_forbidden');
            $this->recordAccess($request, $response, $startedAt, $api, 'scope_forbidden');
            return $response;
        }
        // 具体 scope：token 必须持有
        if ($required !== '*' && !in_array($required, $api['scopes'], true)) {
            $response = $this->forbidden($request, 'api_token.scope_forbidden');
            $this->recordAccess($request, $response, $startedAt, $api, 'missing_scope:' . $required);
            return $response;
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

        $authenticatedRequest = $request
            ->withAttribute('currentUser', $api['user'])
            ->withAttribute('currentRole', AppConfig::ROLE_API_TOKEN)
            ->withAttribute('userPermissions', $permissions);

        try {
            $response = $handler->handle($authenticatedRequest);
        } catch (\Throwable $e) {
            $errorResponse = $this->responseFactory
                ->createResponse(500)
                ->withHeader('Content-Type', 'application/json');
            $errorResponse->getBody()->write(json_encode(['code' => 500, 'message' => 'Internal Server Error'], JSON_UNESCAPED_UNICODE));
            $this->recordAccess($request, $errorResponse, $startedAt, $api, 'exception:' . $e->getMessage());
            throw $e;
        }

        $this->recordAccess($request, $response, $startedAt, $api, null);
        return $response;
    }

    /**
     * 记录一次 API token 调用（DB + 文件日志双写，双双静默降级）。
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
                // 业务侧 4xx/5xx：客户端错误记 failure，401/403 记 denied
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

            // 数据表 + 文件双写都受级别开关控制（all/warning/error/off），避免审计日志失控增长。
            $level = $this->appSettings?->getApiAccessLogLevel()
                ?? \App\Config\AppConfig::DEFAULT_API_ACCESS_LOG_LEVEL;
            if (ApiAccessLogRepository::shouldRecord($result, $level)) {
                $this->apiLogs?->record($entry);
                // 文件双写：一条廉价 JSON，便于不进后台也能 grep 审计；同样不含 token 原文。
                $this->logger?->info('[API调用]', $entry);
            }
        } catch (\Throwable $e) {
            // 审计本身绝不能反噬主流程
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
