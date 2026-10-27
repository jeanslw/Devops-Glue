<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Config\AppConfig;
use App\Service\ApiTokenService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * 后台 API Token 管理（仅 super_admin）。
 * 方法体从 AdminController 原样搬迁。
 */
class ApiTokenController extends BaseAdminController
{
    private ?ApiTokenService $apiTokenService = null;

    public function __construct(
        \App\Service\I18nService $i18n,
        \App\Service\Settings $config,
        \PDO $pdo,
        \App\Service\AppSettingRepository $appSettings,
        ?ApiTokenService $apiTokenService = null
    ) {
        parent::__construct($i18n, $config, $pdo, $appSettings);
        $this->apiTokenService = $apiTokenService;
    }

    /** GET /api/admin/api_tokens/scopes — 返回可选 scope 目录（供 UI 渲染） */
    public function apiTokenScopes(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requireSuperAdmin($response)) {
            return $resp;
        }
        $locale = $this->getLocale($request);
        $scopes = [];
        foreach (AppConfig::API_SCOPES as $key => $labelKey) {
            $scopes[] = ['key' => $key, 'label' => $this->i18n->trans($labelKey, [], $locale)];
        }
        $response->getBody()->write((string) json_encode(['scopes' => $scopes], JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /** GET /api/admin/api_tokens — 列表（不含明文） */
    public function apiTokenList(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requireSuperAdmin($response)) {
            return $resp;
        }
        if ($this->apiTokenService === null) {
            return $this->jsonError($response, 'api_token.service_unavailable', 503);
        }
        $tokens = $this->apiTokenService->listTokens();
        $response->getBody()->write((string) json_encode(['tokens' => $tokens], JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /** POST /api/admin/api_tokens — 创建，返回一次性明文 */
    public function apiTokenCreate(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requireSuperAdmin($response)) {
            return $resp;
        }
        if ($this->apiTokenService === null) {
            return $this->jsonError($response, 'api_token.service_unavailable', 503);
        }
        $body = $request->getParsedBody() ?? json_decode($request->getBody()->__toString(), true) ?? [];

        try {
            $created = $this->apiTokenService->createToken([
                'name'       => $body['name'] ?? '',
                'scopes'     => $body['scopes'] ?? [],
                'expires_at' => $body['expires_at'] ?? null,
                'created_by' => $this->currentUser,
                'note'       => $body['note'] ?? null,
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->jsonError($response, $e->getMessage(), 400);
        } catch (\Exception $e) {
            return $this->jsonError($response, $this->__('build.query_failed') . ': ' . $e->getMessage(), 500);
        }

        $this->opLog()->record($this->currentUser, 'create_api_token', $body['name'] ?? '', ['id' => $created['id'], 'scopes' => $body['scopes'] ?? []], $this->clientIp($request), 'success');
        $response->getBody()->write((string) json_encode(['id' => $created['id'], 'token' => $created['token']], JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * PUT /api/admin/api_tokens/{id}/scopes — 编辑 scope（改库即时生效，明文 token 不变）
     *
     * 只改写元数据，不重新签发，因此调用方无需换 token；下一次请求即按新 scope 校验。
     *
     * @param array<string,string> $args
     */
    public function apiTokenUpdateScopes(Request $request, Response $response, array $args): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requireSuperAdmin($response)) {
            return $resp;
        }
        if ($this->apiTokenService === null) {
            return $this->jsonError($response, 'api_token.service_unavailable', 503);
        }
        $id   = (int)($args['id'] ?? 0);
        $body = $request->getParsedBody() ?? json_decode($request->getBody()->__toString(), true) ?? [];
        $payload = is_array($body) ? ($body['scopes'] ?? []) : [];

        $scopes = $this->apiTokenService->normalizeScopes($payload);

        try {
            $updated = $this->apiTokenService->updateScopes($id, $payload);
        } catch (\InvalidArgumentException $e) {
            return $this->jsonError($response, $scopes === [] ? 'api_token.scopes_required' : 'api_token.scopes_invalid', 400);
        } catch (\Exception $e) {
            return $this->jsonError($response, $this->__('build.query_failed') . ': ' . $e->getMessage(), 500);
        }
        if (!$updated) {
            return $this->jsonError($response, 'api_token.not_found', 404);
        }

        $this->opLog()->record($this->currentUser, 'update_api_token_scopes', (string)$id, ['scopes' => $scopes], $this->clientIp($request), 'success');
        $response->getBody()->write((string) json_encode(['ok' => true, 'id' => $id, 'scopes' => $scopes], JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * POST /api/admin/api_tokens/{id}/revoke — 撤销（禁用，保留记录）
     *
     * @param array<string,string> $args
     */
    public function apiTokenRevoke(Request $request, Response $response, array $args): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requireSuperAdmin($response)) {
            return $resp;
        }
        if ($this->apiTokenService === null) {
            return $this->jsonError($response, 'api_token.service_unavailable', 503);
        }
        $revoked = $this->apiTokenService->revoke((int)($args['id'] ?? 0));
        $this->opLog()->record($this->currentUser, 'revoke_api_token', (string)($args['id'] ?? ''), [], $this->clientIp($request), $revoked ? 'success' : 'failure');
        $response->getBody()->write((string) json_encode(['ok' => $revoked], JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * DELETE /api/admin/api_tokens/{id} — 删除（硬删除记录）
     *
     * @param array<string,string> $args
     */
    public function apiTokenDelete(Request $request, Response $response, array $args): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requireSuperAdmin($response)) {
            return $resp;
        }
        if ($this->apiTokenService === null) {
            return $this->jsonError($response, 'api_token.service_unavailable', 503);
        }
        $deleted = $this->apiTokenService->delete((int)($args['id'] ?? 0));
        $this->opLog()->record($this->currentUser, 'delete_api_token', (string)($args['id'] ?? ''), [], $this->clientIp($request), $deleted ? 'success' : 'failure');
        $response->getBody()->write((string) json_encode(['ok' => $deleted], JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
