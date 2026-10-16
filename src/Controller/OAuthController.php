<?php

namespace App\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Service\I18nService;
use App\Service\OAuthService;
use App\Service\OidcService;
use App\Service\AdminAuthService;
use App\Service\AdminUserRepository;
use App\Config\AppConfig;
use App\Service\Settings;
use App\Service\OperationLogRepository;
use App\Helper\ClientIp;

/**
 * OAuth2 / OIDC Provider 控制器（授权码流程）
 *
 * 端点：
 *   GET  /oauth/authorize                  — 浏览器跳转入口，出登录表单
 *   POST /oauth/authorize                  — 表单提交，认证成功 302 回 redirect_uri?code=xxx&state=xxx
 *   POST /oauth/token                      — code 换 access_token（+ id_token，OIDC）
 *   GET  /oauth/userinfo                   — Bearer token 返回用户信息
 *   GET  /.well-known/openid-configuration — OIDC Discovery
 *   GET  /.well-known/jwks.json            — OIDC JWKS 公钥
 *
 * 注意：本组路由不挂 AuthMiddleware（authorize 是浏览器跳转，token/userinfo 各自校验）。
 */
class OAuthController extends BaseController
{
    private ?OperationLogRepository $operationLog = null;

    public function __construct(
        I18nService $i18n,
        private \PDO $pdo,
        private OAuthService $oauth,
        private AdminAuthService $auth,
        private AdminUserRepository $users,
        private OidcService $oidc,
        private Settings $config
    ) {
        parent::__construct($i18n);
    }

    /** 操作日志仓储（懒加载，写路径不阻塞主流程） */
    private function opLog(): OperationLogRepository
    {
        if ($this->operationLog === null) {
            $this->operationLog = new OperationLogRepository($this->pdo);
        }
        return $this->operationLog;
    }

    /**
     * GET /oauth/authorize — 展示登录表单
     */
    public function authorizeForm(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();
        $clientId     = (string)($q['client_id'] ?? '');
        $redirectUri  = (string)($q['redirect_uri'] ?? '');
        $state        = (string)($q['state'] ?? '');
        $scope        = (string)($q['scope'] ?? '');
        $nonce        = (string)($q['nonce'] ?? '');
        $responseType = (string)($q['response_type'] ?? 'code');

        if ($responseType !== 'code' || !$this->oauth->validateClient($clientId, $redirectUri)) {
            return $this->jsonError($response, 'oauth.invalid_request', 400);
        }

        $response->getBody()->write($this->renderLoginForm($clientId, $redirectUri, $state, $scope, $nonce, ''));
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * POST /oauth/authorize — 处理登录表单，签发授权码并 302 回跳
     */
    public function authorizeSubmit(Request $request, Response $response): Response
    {
        $body = (array)$request->getParsedBody();
        $clientId    = (string)($body['client_id'] ?? '');
        $redirectUri = (string)($body['redirect_uri'] ?? '');
        $state       = (string)($body['state'] ?? '');
        $scope       = (string)($body['scope'] ?? '');
        $nonce       = (string)($body['nonce'] ?? '');
        $username    = trim((string)($body['username'] ?? ''));
        $password    = (string)($body['password'] ?? '');

        if (!$this->oauth->validateClient($clientId, $redirectUri)) {
            return $this->jsonError($response, 'oauth.invalid_request', 400);
        }

        // 登录失败限流：与 /api/admin/login 同一策略（IP + 用户名 5 次/15 分钟），
        // 避免 OAuth 登录页成为绕过管理后台限流的暴力破解入口。
        $clientIp = $this->clientIp($request);
        if ($this->auth->isLoginLocked($clientIp, $username)) {
            $this->opLog()->record($username, 'login_locked', '', [], $clientIp, 'failure');
            $response->getBody()->write($this->renderLoginForm(
                $clientId,
                $redirectUri,
                $state,
                $scope,
                $nonce,
                $this->__('auth.login_locked')
            ));
            return $response->withStatus(429)->withHeader('Content-Type', 'text/html; charset=utf-8');
        }

        // 复用现有认证逻辑（DB 用户 + .env 兜底）：按客户端声明的归属系统校验账号；
        // 未声明 system 的客户端（Jenkins / Harbor / GitLab）回退 CI。
        $systemType = $this->oauth->clientSystem($clientId) ?: AppConfig::SYSTEM_CI;
        $result = $this->auth->authenticate($username, $password, $systemType);
        if (empty($result['success'])) {
            $this->auth->recordLoginFailure($clientIp, $username);
            $this->opLog()->record($username, 'login_failed', '', [], $clientIp, 'failure');
            $response->getBody()->write($this->renderLoginForm(
                $clientId,
                $redirectUri,
                $state,
                $scope,
                $nonce,
                $this->__($result['errorKey'] ?? 'auth.wrong_credentials')
            ));
            return $response->withStatus(401)->withHeader('Content-Type', 'text/html; charset=utf-8');
        }
        $this->auth->clearLoginFailure($clientIp, $username);
        $this->opLog()->record((string)$result['user'], 'login', '', [], $clientIp, 'success');

        $code = $this->oauth->issueCode(
            $clientId,
            $redirectUri,
            (string)$result['user'],
            (string)$result['role'],
            $scope,
            $nonce !== '' ? $nonce : null
        );

        $sep = str_contains($redirectUri, '?') ? '&' : '?';
        $location = $redirectUri . $sep . 'code=' . urlencode($code);
        if ($state !== '') {
            $location .= '&state=' . urlencode($state);
        }
        return $response->withStatus(302)->withHeader('Location', $location);
    }

    /**
     * POST /oauth/token — 授权码换访问令牌
     *
     * 参数（application/x-www-form-urlencoded）：
     *   grant_type=authorization_code, code, redirect_uri, client_id, client_secret
     */
    public function token(Request $request, Response $response): Response
    {
        $body = (array)$request->getParsedBody();
        $grantType    = (string)($body['grant_type'] ?? '');
        $code         = (string)($body['code'] ?? '');
        $redirectUri  = (string)($body['redirect_uri'] ?? '');
        $clientId     = (string)($body['client_id'] ?? '');
        $clientSecret = (string)($body['client_secret'] ?? '');

        // 部分客户端用 HTTP Basic Auth 传 client_id/client_secret（RFC 6749 §2.3.1）
        if ($clientId === '' || $clientSecret === '') {
            [$basicId, $basicSecret] = $this->parseBasicAuth($request);
            if ($clientId === '') {
                $clientId = $basicId;
            }
            if ($clientSecret === '') {
                $clientSecret = $basicSecret;
            }
        }

        if ($grantType !== 'authorization_code' || $code === '') {
            return $this->oauthError($response, 'unsupported_grant_type', 400);
        }
        if (!$this->oauth->validateClientSecret($clientId, $clientSecret)) {
            return $this->oauthError($response, 'invalid_client', 401);
        }

        $data = $this->oauth->consumeCode($code, $clientId, $redirectUri);
        if ($data === null) {
            return $this->oauthError($response, 'invalid_grant', 400);
        }

        $accessToken = $this->oauth->issueAccessToken(
            (string)$data['user'],
            (string)$data['role'],
            $clientId
        );

        $scope = (string)($data['scope'] ?? '');
        $nonce = isset($data['nonce']) ? (string)$data['nonce'] : null;

        $payload = [
            'access_token' => $accessToken,
            'token_type'   => 'Bearer',
            'expires_in'   => OAuthService::ACCESS_TOKEN_TTL, // 引用常量：与 cache 实际过期时间同源
        ];

        // 仅当授权请求 scope 含 openid 才签发 id_token（纯 OAuth2 客户端不签发）
        if ($this->hasOpenidScope($scope)) {
            $payload['id_token'] = $this->oidc->signIdToken(
                $this->buildIdTokenClaims((string)$data['user'], (string)$data['role'], $this->resolveIssuer($request)),
                $clientId,
                $nonce
            );
        }
        if ($scope !== '') {
            $payload['scope'] = $scope;
        }

        $response->getBody()->write((string) json_encode($payload));
        return $response->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * GET /oauth/userinfo — Bearer token 返回用户信息
     *
     * 返回用户唯一标识与角色（sub / username / preferred_username / email / role / groups）。
     */
    public function userinfo(Request $request, Response $response): Response
    {
        $authHeader = (string)$request->getHeaderLine('Authorization');
        if (!preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m)) {
            return $this->oauthError($response, 'invalid_token', 401);
        }

        $data = $this->oauth->validateAccessToken(trim($m[1]));
        if ($data === null) {
            return $this->oauthError($response, 'invalid_token', 401);
        }

        $username  = (string)$data['user'];
        $role      = (string)($data['role'] ?? '');
        $emailInfo = $this->resolveEmailInfo($username);
        $response->getBody()->write((string) json_encode([
            'sub'                => $username,   // OAuth/OIDC 标准唯一标识
            'username'           => $username,
            'preferred_username' => $username,
            'name'               => $username,
            'email'              => $emailInfo['email'],
            'email_verified'     => $emailInfo['verified'],
            'role'               => $role,
            'groups'             => $this->oidc->groupsFromRole($role),
        ]));

        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * GET /oauth/userinfo/emails — GitHub 风格邮箱子端点
     *
     * 供仍按 GitHub emails 流程取邮箱的 OAuth2 客户端使用，返回与 userinfo 一致的邮箱。
     */
    public function userinfoEmails(Request $request, Response $response): Response
    {
        $authHeader = (string)$request->getHeaderLine('Authorization');
        if (!preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m)) {
            return $this->oauthError($response, 'invalid_token', 401);
        }

        $data = $this->oauth->validateAccessToken(trim($m[1]));
        if ($data === null) {
            return $this->oauthError($response, 'invalid_token', 401);
        }

        $emailInfo = $this->resolveEmailInfo((string)$data['user']);
        $response->getBody()->write((string) json_encode([[
            'email'    => $emailInfo['email'],
            'primary'  => true,
            'verified' => $emailInfo['verified'],
        ]]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * GET /.well-known/openid-configuration — OIDC Discovery 文档
     */
    public function discovery(Request $request, Response $response): Response
    {
        $response->getBody()->write((string) json_encode(
            $this->oidc->discovery($this->resolveIssuer($request)),
            JSON_UNESCAPED_SLASHES
        ));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * GET /.well-known/jwks.json — OIDC 公钥集合（仅公钥，绝不含私钥）
     */
    public function jwks(Request $request, Response $response): Response
    {
        $response->getBody()->write((string) json_encode($this->oidc->jwks(), JSON_UNESCAPED_SLASHES));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * 取用户邮箱：实时查库（token 期内 email 变更即时生效）；
     * 用户不存在或 email 为空时退回 username@devops-glue.local 占位（客户端要求 email 非空）。
     *
     * @return array{email: string, verified: bool}
     */
    private function resolveEmailInfo(string $username): array
    {
        $user  = $this->users->findByUsername($username);
        $email = trim((string)($user['email'] ?? ''));
        if ($email !== '') {
            return ['email' => $email, 'verified' => true];
        }
        // 用户未配置邮箱时退回 username@devops-glue.local 占位（客户端要求 email 非空）。
        // 占位域非用户真实邮箱、无法验证，故 email_verified/verified 必须为 false。
        return ['email' => $username . '@devops-glue.local', 'verified' => false];
    }

    /**
     * scope 是否包含 openid（决定是否签发 id_token）
     */
    private function hasOpenidScope(string $scope): bool
    {
        if (trim($scope) === '') {
            return false;
        }
        $parts = preg_split('/\s+/', trim($scope));
        return is_array($parts) && in_array(OidcService::SCOPE_OPENID, $parts, true);
    }

    /**
     * 组装 id_token 的 claims（iss/sub 由 OidcService 补 exp/iat/aud/nonce）
     *
     * 新增 claim（如 P2 计划的 `systems`）必须同时改 4 处，漏掉第 2 处该 claim 会被丢弃
     * （签出的 id_token 里没有它，也不报错）：
     *   1. 本方法：组装取值；
     *   2. `OidcService::OPTIONAL_CLAIMS`：透传白名单（关键）；
     *   3. `OidcService::CLAIMS_SUPPORTED`：discovery 的 `claims_supported` 声明；
     *   4. `userinfo()` 的返回数组：若该 claim 也要在 userinfo 暴露。
     * 对应测试：`tests/Unit/OidcServiceTest.php` 的两条白名单交叉断言。
     *
     * @return array<string,mixed>
     */
    private function buildIdTokenClaims(string $username, string $role, string $issuer): array
    {
        $emailInfo = $this->resolveEmailInfo($username);
        return [
            'iss'                => $issuer,
            'sub'                => $username,
            'name'               => $username,
            'preferred_username' => $username,
            'email'              => $emailInfo['email'],
            'email_verified'     => $emailInfo['verified'],
            'groups'             => $this->oidc->groupsFromRole($role),
        ];
    }

    /**
     * 确定 issuer：优先 OIDC_ISSUER 配置，缺省从当前请求 scheme+host 推导
     *
     * 推导路径有意不读 X-Forwarded-Proto / X-Forwarded-Host（可伪造，会污染 discovery 文档），
     * 因此经 nginx / Ingress 反向代理时，推导出的 issuer 很可能是内网地址（如 http://devops-glue），
     * 有两种后果：一是 OIDC 客户端 `iss` 校验失败；二是更隐蔽的，discovery 的 `jwks_uri` 也指向内网，
     * 客户端拉不到公钥，表现为验签失败而不是网络不通，容易误判为密钥问题。
     * 因此凡经反向代理对外提供 SSO 的部署，必须显式配置 `OIDC_ISSUER=<对外地址>`；
     *   联调前核对方式：`curl -s <issuer>/.well-known/openid-configuration | jq .issuer,.jwks_uri`。
     */
    private function resolveIssuer(Request $request): string
    {
        $configured = $this->oidc->issuer();
        if ($configured !== '') {
            return $configured;
        }
        $uri    = $request->getUri();
        $scheme = $uri->getScheme() !== '' ? $uri->getScheme() : 'http';
        $host   = $uri->getHost();
        $port   = $uri->getPort();
        // 非默认端口（80/443）需拼回 issuer，否则 discovery 文档里的端点 URL 会丢端口，
        // 客户端（Harbor/Jenkins/GitLab）按文档拿到的端点就无法访问。
        if ($port !== null && !(($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443))) {
            $host .= ':' . $port;
        }
        return $scheme . '://' . $host;
    }

    /**
     * 客户端 IP（与 AdminController::clientIp 共用 App\Helper\ClientIp）。
     * 默认只信 REMOTE_ADDR；TRUSTED_PROXY_HOPS>0 且对端为内网反代时才从 XFF 右端取数，
     * 避免伪造 XFF 绕过登录限流。
     */
    private function clientIp(Request $request): string
    {
        return ClientIp::resolve($request->getServerParams(), $this->config->getTrustedProxyHops());
    }

    /**
     * 解析 HTTP Basic Auth 头（RFC 6749 §2.3.1 客户端认证）
     * 返回 [client_id, client_secret]，解析失败返回 ['', '']
     *
     * @return list<string>
     */
    private function parseBasicAuth(Request $request): array
    {
        $header = (string)$request->getHeaderLine('Authorization');
        if (!preg_match('/^Basic\s+(.+)$/i', $header, $m)) {
            return ['', ''];
        }
        $decoded = base64_decode(trim($m[1]), true);
        if ($decoded === false || !str_contains($decoded, ':')) {
            return ['', ''];
        }
        [$id, $secret] = explode(':', $decoded, 2);
        return [urldecode($id), urldecode($secret)];
    }

    /**
     * OAuth 标准错误响应（RFC 6749）
     */
    private function oauthError(Response $response, string $error, int $code): Response
    {
        $response->getBody()->write((string) json_encode(['error' => $error]));
        return $response->withStatus($code)->withHeader('Content-Type', 'application/json');
    }

    /**
     * 渲染登录表单（模板：templates/oauth_login.html，占位符 {{key}} 替换）
     */
    private function renderLoginForm(string $clientId, string $redirectUri, string $state, string $scope, string $nonce, string $error): string
    {
        $template = file_get_contents(__DIR__ . '/../../templates/oauth_login.html');
        if ($template === false) {
            // 模板缺失属部署异常，直接抛错暴露问题，不做降级处理
            throw new \RuntimeException('OAuth 登录模板缺失: templates/oauth_login.html');
        }

        $errorHtml = $error !== ''
            ? '<div class="err">' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . '</div>'
            : '';

        return str_replace(
            ['{{title}}', '{{error}}', '{{client_id}}', '{{redirect_uri}}', '{{state}}', '{{scope}}', '{{nonce}}', '{{user_label}}', '{{pass_label}}', '{{btn_label}}'],
            [
                htmlspecialchars($this->__('oauth.login_title'), ENT_QUOTES, 'UTF-8'),
                $errorHtml,
                htmlspecialchars($clientId, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($redirectUri, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($state, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($scope, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($this->__('admin.account_placeholder'), ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($this->__('admin.password_placeholder'), ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($this->__('auth.login'), ENT_QUOTES, 'UTF-8'),
            ],
            $template
        );
    }
}
