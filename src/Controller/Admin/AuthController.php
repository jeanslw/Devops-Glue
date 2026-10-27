<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Config\AppConfig;
use App\Service\PasswordHasher;
use App\Service\AdminAuthService;
use App\Service\AdminUserRepository;
use App\Service\AppSettingRepository;
use App\Service\I18nService;
use App\Service\OperationLogRepository;
use App\Service\Settings;
use App\Service\TokenService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * 后台认证：登录 / 登出 / 改密 / 解锁 / 我的权限。
 * token 读写复用 TokenService，登录限流复用 AdminAuthService。
 */
class AuthController extends BaseAdminController
{
    private TokenService $tokenService;
    private AdminAuthService $adminAuthService;
    private AdminUserRepository $adminUserRepository;

    public function __construct(
        I18nService $i18n,
        Settings $config,
        AppSettingRepository $appSettings,
        OperationLogRepository $operationLog,
        TokenService $tokenService,
        AdminAuthService $adminAuthService,
        AdminUserRepository $adminUserRepository
    ) {
        parent::__construct($i18n, $config, $appSettings, $operationLog);
        $this->tokenService        = $tokenService;
        $this->adminAuthService    = $adminAuthService;
        $this->adminUserRepository = $adminUserRepository;
    }

    public function login(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? json_decode($request->getBody()->__toString(), true) ?? [];
        $user = strtolower(trim($body['username'] ?? $body['user'] ?? ''));
        $pass = $body['password'] ?? '';
        if ($user === '' || $pass === '') {
            return $this->jsonError($response, 'auth.wrong_credentials', 401);
        }

        $clientIp = $this->clientIp($request);
        if ($this->adminAuthService->isLoginLocked($clientIp, $user)) {
            $this->opLog()->record($user, 'login_locked', '', [], $clientIp, 'failure');
            return $this->jsonError($response, 'auth.login_locked', 429);
        }

        $result = $this->adminAuthService->authenticate($user, $pass, $this->config->getSystemType());
        if (!$result['success']) {
            $this->adminAuthService->recordLoginFailure($clientIp, $user);
            $this->opLog()->record($user, 'login_failed', '', [], $clientIp, 'failure');
            return $this->jsonError($response, $result['errorKey'], 401);
        }
        $this->adminAuthService->clearLoginFailure($clientIp, $user);

        $loginRole = $result['role'];
        $isRoot = $result['isRoot'];

        if (\function_exists('random_bytes')) {
            $randomBytes = \random_bytes(32);
        } elseif (\function_exists('openssl_random_pseudo_bytes')) {
            $randomBytes = \openssl_random_pseudo_bytes(32);
        } else {
            return $this->jsonError($response, 'auth.token_generate_failed', 500);
        }

        $token = bin2hex($randomBytes);
        try {
            $this->tokenService->storeAdminToken($token, $user, $loginRole);
        } catch (\Exception $e) {
            return $this->jsonError($response, 'auth.token_store_failed', 500);
        }
        $perms = $this->tokenService->loadPermissions($loginRole);

        $this->opLog()->record($user, 'login', '', [], $clientIp, 'success');

        return $this->output($response, [
            'token'       => $token,
            'role'        => $loginRole,
            'user'        => $user,
            'is_root'     => $isRoot,
            'permissions' => $perms,
            'must_change_password' => !empty($result['must_change_password']),
        ], $request);
    }

    /**
     * POST /api/admin/logout — 退出登录，清理 token
     */
    public function logout(Request $request, Response $response): Response
    {
        $header = $request->getHeaderLine('Authorization');
        $username = '';
        if (preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
            $token = $m[1];
            $username = $this->tokenService->userFromCacheToken($token);
            $this->tokenService->revoke($token);
        }
        if ($username !== '') {
            $this->opLog()->record($username, 'logout', '', [], $this->clientIp($request), 'success');
        }
        return $this->output($response, ['message' => 'logged_out'], $request);
    }

    public function changePassword(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_USERS_PASSWORD)) {
            return $resp;
        }

        $body = $request->getParsedBody() ?? json_decode($request->getBody()->__toString(), true) ?? [];
        $oldPass = $body['old_password'] ?? '';
        $newPass = $body['new_password'] ?? '';

        if (strlen($newPass) < 8) {
            return $this->jsonError($response, 'auth.new_password_short', 400);
        }

        try {
            $username = $this->currentUser;
            if ($username === '') {
                return $this->jsonError($response, 'auth.not_logged_in', 401);
            }

            if (!$this->adminAuthService->verifyCurrentPassword($username, $oldPass)) {
                $this->opLog()->record($username, 'change_password', $username, [], $this->clientIp($request), 'failure');
                return $this->jsonError($response, 'auth.old_password_wrong', 403);
            }

            $hash = PasswordHasher::hash($newPass);
            $this->adminUserRepository->updatePassword($username, $hash);

            try {
                $header = $request->getHeaderLine('Authorization');
                if (preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
                    $this->tokenService->revoke($m[1]);
                }
            } catch (\Exception $e) {
                \App\Helper\Log::exception($e);
            }

            $this->opLog()->record($username, 'change_password', $username, [], $this->clientIp($request), 'success');
            return $this->output($response, ['success' => true, 'message' => $this->__('auth.password_updated')], $request);
        } catch (\Exception $e) {
            return $this->jsonError($response, $this->__('build.modify_failed') . ': ' . $e->getMessage(), 500);
        }
    }

    public function clearLoginLocks(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if ($this->currentRole !== AppConfig::ROLE_SUPER_ADMIN) {
            return $this->jsonError($response, 'user.unlock_forbidden', 403);
        }
        try {
            $cleared = $this->adminAuthService->clearAllLoginLocks();
            $this->opLog()->record($this->currentUser, 'clear_login_locks', '', ['cleared' => $cleared], $this->clientIp($request), 'success');
            return $this->output($response, ['success' => true, 'cleared' => $cleared], $request);
        } catch (\Exception $e) {
            return $this->jsonError($response, $this->__('build.modify_failed') . ': ' . $e->getMessage(), 500);
        }
    }

    public function mePermissions(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);

        if ($this->currentRole === AppConfig::ROLE_SUPER_ADMIN) {
            $permissions = '*';
        } else {
            $permissions = $this->userPermissions;
        }

        $response->getBody()->write((string) json_encode([
            'role'        => $this->currentRole,
            'permissions' => $permissions,
        ], JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
