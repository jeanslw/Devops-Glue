<?php
namespace App\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Service\I18nService;
use App\Service\OAuthService;
use App\Service\AdminAuthService;
use App\Config\AppConfig;

/**
 * 极简 OAuth2 Provider 控制器（授权码流程）
 *
 * 端点：
 *   GET  /oauth/authorize  — 浏览器跳转入口，出登录表单
 *   POST /oauth/authorize  — 表单提交，认证成功 302 回 redirect_uri?code=xxx&state=xxx
 *   POST /oauth/token      — code 换 access_token（client_secret 校验）
 *   GET  /oauth/userinfo   — Bearer token 返回用户信息
 *
 * 注意：本组路由不挂 AuthMiddleware（authorize 是浏览器跳转，token/userinfo 各自校验）。
 */
class OAuthController extends BaseController
{
    public function __construct(
        I18nService $i18n,
        private OAuthService $oauth,
        private AdminAuthService $auth
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
        $responseType = (string)($q['response_type'] ?? 'code');

        if ($responseType !== 'code' || !$this->oauth->validateClient($clientId, $redirectUri)) {
            return $this->jsonError($response, 'oauth.invalid_request', 400);
        }

        $response->getBody()->write($this->renderLoginForm($clientId, $redirectUri, $state, ''));
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
        $username    = trim((string)($body['username'] ?? ''));
        $password    = (string)($body['password'] ?? '');

        if (!$this->oauth->validateClient($clientId, $redirectUri)) {
            return $this->jsonError($response, 'oauth.invalid_request', 400);
        }

        // 复用现有认证逻辑（DB 用户 + .env 兜底），systemType 用 CI（OAuth 登录视为 CI 侧）
        $result = $this->auth->authenticate($username, $password, AppConfig::SYSTEM_CI);
        if (empty($result['success'])) {
            $response->getBody()->write($this->renderLoginForm(
                $clientId, $redirectUri, $state, $this->__($result['errorKey'] ?? 'auth.wrong_credentials')
            ));
            return $response->withStatus(401)->withHeader('Content-Type', 'text/html; charset=utf-8');
        }

        $code = $this->oauth->issueCode(
            $clientId,
            $redirectUri,
            (string)$result['user'],
            (string)$result['role']
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

        $response->getBody()->write(json_encode([
            'access_token' => $accessToken,
            'token_type'   => 'Bearer',
            'expires_in'   => 3600,
        ]));
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

        $response->getBody()->write(json_encode([
            'sub'      => $data['user'],   // OAuth 标准唯一标识
            'username' => $data['user'],
            'name'     => $data['user'],
            'role'     => $data['role'] ?? '',
        ]));
        return $response->withHeader('Content-Type', 'application/json');
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
     * 渲染内嵌登录表单（无 session，每次请求独立）
     */
    private function renderLoginForm(string $clientId, string $redirectUri, string $state, string $error): string
    {
        $e = $error !== ''
            ? '<div class="err">' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . '</div>'
            : '';
        $cid = htmlspecialchars($clientId, ENT_QUOTES, 'UTF-8');

        $ruri = htmlspecialchars($redirectUri, ENT_QUOTES, 'UTF-8');
        $st = htmlspecialchars($state, ENT_QUOTES, 'UTF-8');
        $title = $this->__('oauth.login_title');
        $userLabel = $this->__('admin.account_placeholder');
        $passLabel = $this->__('admin.password_placeholder');
        $btnLabel = $this->__('auth.login');

        return <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$title} — Devops-Glue</title>
<style>
  body{font-family:system-ui,-apple-system,sans-serif;background:#f0f2f5;display:flex;justify-content:center;align-items:center;min-height:100vh;margin:0}
  .card{background:#fff;padding:2rem 2.5rem;border-radius:8px;box-shadow:0 2px 12px rgba(0,0,0,.1);width:320px}
  h2{margin:0 0 1.5rem;font-size:1.25rem;text-align:center;color:#333}
  label{display:block;margin-bottom:.25rem;font-size:.875rem;color:#555}
  input[type=text],input[type=password]{width:100%;padding:.5rem .75rem;margin-bottom:1rem;border:1px solid #d9d9d9;border-radius:4px;box-sizing:border-box;font-size:1rem}
  button{width:100%;padding:.6rem;background:#1677ff;color:#fff;border:none;border-radius:4px;font-size:1rem;cursor:pointer}
  button:hover{background:#4096ff}
  .err{color:#ff4d4f;font-size:.875rem;margin-bottom:1rem;text-align:center}
</style>
</head>
<body>
<div class="card">
  <h2>{$title}</h2>
  {$e}

  <form method="post" action="">
    <input type="hidden" name="client_id" value="{$cid}">
    <input type="hidden" name="redirect_uri" value="{$ruri}">
    <input type="hidden" name="state" value="{$st}">
    <label>{$userLabel}</label>
    <input type="text" name="username" required autofocus>
    <label>{$passLabel}</label>
    <input type="password" name="password" required>
    <button type="submit">{$btnLabel}</button>
  </form>
</div>
</body>
</html>
HTML;
    }
}
