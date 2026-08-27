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
    public function __construct(
        I18nService $i18n,
        private OAuthService $oauth,
        private AdminAuthService $auth,
        private AdminUserRepository $users,
        private OidcService $oidc
    ) {
        parent::__construct($i18n);
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

        // 复用现有认证逻辑（DB 用户 + .env 兜底），systemType 用 CI（OAuth 登录视为 CI 侧）
        $result = $this->auth->authenticate($username, $password, AppConfig::SYSTEM_CI);
        if (empty($result['success'])) {
            $response->getBody()->write($this->renderLoginForm(
                $clientId, $redirectUri, $state, $scope, $nonce, $this->__($result['errorKey'] ?? 'auth.wrong_credentials')
            ));
            return $response->withStatus(401)->withHeader('Content-Type', 'text/html; charset=utf-8');
        }

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

        // Grafana 等客户端默认用 HTTP Basic Auth 传 client_id/client_secret（RFC 6749 §2.3.1）
        if ($clientId === '' || $clientSecret === '') {
            [$basicId, $basicSecret] = $this->parseBasicAuth($request);
            if ($clientId === '')     $clientId = $basicId;
            if ($clientSecret === '') $clientSecret = $basicSecret;
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
            'expires_in'   => 3600,
        ];

        // OIDC：仅当授权请求 scope 含 openid 才签发 id_token（纯 OAuth2/Grafana 不签，保持向后兼容）
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

        $response->getBody()->write(json_encode($payload));
        return $response->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * GET /oauth/userinfo — Bearer token 返回用户信息
     *
     * Grafana 需要至少一个唯一标识字段；返回 username / role。
     */
    public function userinfo(Request $request, Response $response): Response
    {
        $authHeader = (string)($request->getHeaderLine('Authorization') ?? '');
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
        $response->getBody()->write(json_encode([
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
     * GET /oauth/userinfo/emails — GitHub 风格邮箱子端点（Grafana 兜底请求）
     *
     * userinfo 已含 email 时 Grafana 一般不会再调此端点；注册它是为了兼容
     * 仍走 GitHub emails 流程的客户端，返回与 userinfo 一致的占位邮箱。
     */
    public function userinfoEmails(Request $request, Response $response): Response
    {
        $authHeader = (string)($request->getHeaderLine('Authorization') ?? '');
        if (!preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m)) {
            return $this->oauthError($response, 'invalid_token', 401);
        }

        $data = $this->oauth->validateAccessToken(trim($m[1]));
        if ($data === null) {
            return $this->oauthError($response, 'invalid_token', 401);
        }

        $emailInfo = $this->resolveEmailInfo((string)$data['user']);
        $response->getBody()->write(json_encode([[
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
        $response->getBody()->write(json_encode(
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
        $response->getBody()->write(json_encode($this->oidc->jwks(), JSON_UNESCAPED_SLASHES));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * 取用户邮箱：实时查库（token 期内 email 变更即时生效）；
     * 用户不存在或 email 为空时退回 username@devops-glue.local 占位（Grafana 按 email 匹配用户，不能为空）。
     */
    private function resolveEmailInfo(string $username): array
    {
        $user  = $this->users->findByUsername($username);
        $email = trim((string)($user['email'] ?? ''));
        if ($email !== '') {
            return ['email' => $email, 'verified' => true];
        }
        // 用户未配置邮箱时退回 username@devops-glue.local 占位（Grafana 按 email 匹配用户，不能为空）。
        // 该占位域是系统虚构、OP 无法验证，故 email_verified/verified 必须为 false，不得撒谎为已验证。
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
     * 解析 HTTP Basic Auth 头（RFC 6749 §2.3.1 客户端认证）
     * 返回 [client_id, client_secret]，解析失败返回 ['', '']
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
        $response->getBody()->write(json_encode(['error' => $error]));
        return $response->withStatus($code)->withHeader('Content-Type', 'application/json');
    }

    /**
     * 渲染登录表单（模板：templates/oauth_login.html，占位符 {{key}} 替换）
     */
    private function renderLoginForm(string $clientId, string $redirectUri, string $state, string $scope, string $nonce, string $error): string
    {
        $template = file_get_contents(__DIR__ . '/../../templates/oauth_login.html');
        if ($template === false) {
            // 模板缺失属部署异常，直接抛错暴露问题，不静默降级
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
