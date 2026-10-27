<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Config\AppConfig;
use App\Service\OAuthClientRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * 后台 OIDC / OAuth 配置（issuer + id_token_ttl + 客户端白名单）。
 * 方法体从 AdminController 原样搬迁。
 */
class OidcController extends BaseAdminController
{
    private OAuthClientRepository $oauthClients;

    public function __construct(
        \App\Service\I18nService $i18n,
        \App\Service\Settings $config,
        \PDO $pdo,
        \App\Service\AppSettingRepository $appSettings,
        OAuthClientRepository $oauthClients
    ) {
        parent::__construct($i18n, $config, $pdo, $appSettings);
        $this->oauthClients = $oauthClients;
    }

    /**
     * OIDC 配置的归一化负载（GET 与 PUT 返回复用同一份）。
     * 明文 secret 永不下发，只给 secret_set 布尔；source 标注 database|env。
     *
     * @return array<string,mixed>
     */
    private function oidcConfigPayload(): array
    {
        $dbIssuer = $this->appSettings->getOidcIssuer();
        $issuer   = $dbIssuer ?? '';
        $issuerSource = $dbIssuer !== null ? 'database' : '';

        $dbTtl = $this->appSettings->getOidcIdTokenTtl();
        $ttl   = $dbTtl ?? AppConfig::DEFAULT_OIDC_ID_TOKEN_TTL;
        $ttlSource = $dbTtl !== null ? 'database' : '';


        $clients = [];
        foreach ($this->oauthClients->list() as $row) {
            $id = trim((string)($row['client_id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $clients[] = [
                'client_id'    => $id,
                'system'       => (string)($row['system'] ?? ''),
                'enabled'      => (int)($row['enabled'] ?? 1) === 1,
                'description'  => (string)($row['description'] ?? ''),
                'redirect_uri' => (string)($row['redirect_uri'] ?? ''),
                'secret_set'   => trim((string)($row['secret'] ?? '')) !== '',
                'source'       => 'database',
            ];
        }

        return [
            'issuer'              => $issuer,
            'issuer_source'       => $issuerSource,
            'id_token_ttl'        => $ttl,
            'id_token_ttl_source' => $ttlSource,
            'clients'             => $clients,
        ];
    }

    /**
     * GET /api/admin/oidc_config — OIDC/OAuth 配置（issuer + id_token_ttl + 客户端白名单）
     * 权限：ci.platform-config。明文 secret 永不下发，只回 secret_set。
     */
    public function oidcConfig(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_PLATFORM_CONFIG)) {
            return $resp;
        }
        try {
            return $this->output($response, $this->oidcConfigPayload(), $request);
        } catch (\Exception $e) {
            return $this->jsonError($response, $this->__('build.query_failed') . ': ' . $e->getMessage(), 500);
        }
    }

    /**
     * PUT /api/admin/oidc_config — 更新 OIDC/OAuth 配置
     * 权限：ci.platform-config。只处理请求体显式携带的键；secret/redirect_uri 留空即保持原值。
     * clients 数组即「要 DB 管理的客户端集合」：delete:true 删除行（该客户端不再可用）；其余 upsert。
     * enabled 客户端最终必须同时有非空 secret 与 redirect_uri，否则 400（避免 fail-closed 静默丢弃）。
     */
    public function updateOidcConfig(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_PLATFORM_CONFIG)) {
            return $resp;
        }
        $body = $request->getParsedBody() ?? json_decode($request->getBody()->__toString(), true) ?? [];

        $this->pdo->beginTransaction();
        try {
            if (array_key_exists('issuer', $body)) {
                $this->appSettings->setOidcIssuer((string)($body['issuer'] ?? ''));
            }
            if (array_key_exists('id_token_ttl', $body)) {
                $this->appSettings->setOidcIdTokenTtl((int)($body['id_token_ttl'] ?? 0));
            }
            if (isset($body['clients']) && is_array($body['clients'])) {
                foreach ($body['clients'] as $c) {
                    if (!is_array($c)) {
                        continue;
                    }
                    $id = trim((string)($c['client_id'] ?? ''));
                    if ($id === '') {
                        continue;
                    }
                    if (!empty($c['delete'])) {
                        $this->oauthClients->delete($id);
                        continue;
                    }
                    $existing = $this->oauthClients->find($id);
                    $secret   = (string)($existing['secret'] ?? '');
                    $redirect = (string)($existing['redirect_uri'] ?? '');
                    if (array_key_exists('secret', $c)) {
                        $s = trim((string)($c['secret'] ?? ''));
                        if ($s !== '') {
                            $secret = $s;
                        }
                    }
                    if (array_key_exists('redirect_uri', $c)) {
                        $r = trim((string)($c['redirect_uri'] ?? ''));
                        if ($r !== '') {
                            $redirect = $r;
                        }
                    }
                    $enabled = !empty($c['enabled']);
                    if ($enabled && (trim($secret) === '' || trim($redirect) === '')) {
                        throw new \InvalidArgumentException(
                            'OIDC 客户端 [' . $id . '] 启用时必须同时填写 secret 与 redirect_uri'
                        );
                    }
                    $description = (string)($existing['description'] ?? '');
                    if (array_key_exists('description', $c)) {
                        $description = trim((string)($c['description'] ?? ''));
                    }
                    $this->oauthClients->save($id, [
                        'secret'       => $secret,
                        'redirect_uri' => $redirect,
                        'system'       => (string)($c['system'] ?? ''),
                        'enabled'      => $enabled,
                        'description'  => $description,
                    ]);
                }
            }
            $this->opLog()->record($this->currentUser, 'oidc_config_update', '', [], $this->clientIp($request), 'success');
            $this->pdo->commit();
        } catch (\InvalidArgumentException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return $this->jsonError($response, $e->getMessage(), 400);
        } catch (\Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return $this->jsonError($response, $this->__('build.query_failed') . ': ' . $e->getMessage(), 500);
        }

        return $this->output($response, $this->oidcConfigPayload(), $request);
    }
}
