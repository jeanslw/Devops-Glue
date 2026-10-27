<?php

namespace App\Controller;

use App\Config\AppConfig;
use App\Helper\ClientIp;
use App\Service\ApiTokenService;
use App\Service\Database;
use App\Service\I18nService;
use App\Service\IntegratedServiceRepository;
use App\Service\OperationLogRepository;
use App\Service\Settings;
use App\Service\TransactionManager;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * 集成服务启停管理（后台 API 管理页的集成服务卡片，位于 Token 列表上方）。
 *
 * 信任模型：
 *   - 读状态：ci.system 权限（与系统信息面板同级）；
 *   - 启用/禁用：仅 super_admin（与 API token 签发、库结构迁移同级：启用会签发服务凭据，
 *     禁用会冻结一整个下游系统），API token 与普通管理员不可达（/api/admin 组 fail-closed）；
 *   - 启用是签发新凭据或恢复被冻结的同一枚凭据 + 写契约行的事务；禁用是冻结 + 停用 token 的事务；
 */
final class IntegratedServiceController extends BaseController
{
    /**
     * 当前允许管理的集成服务白名单：路由参数只接受这里的名字，杜绝写入任意服务行。
     *
     * @var list<string>
     */
    private const KNOWN_SERVICES = [
        AppConfig::SERVICE_CD,
    ];

    public function __construct(
        I18nService $i18n,
        private OperationLogRepository $operationLog,
        private IntegratedServiceRepository $services,
        private ApiTokenService $apiTokens,
        private Settings $config,
        private TransactionManager $tx
    ) {
        parent::__construct($i18n);
    }

    /**
     * GET /api/admin/integrated_services/{service} — 集成服务状态（行不存在即未启用，无种子）。
     *
     * @param array<string,string> $args
     */
    public function status(Request $request, Response $response, array $args): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_SYSTEM)) {
            return $resp;
        }
        $service = $this->knownService($response, (string) ($args['service'] ?? ''));
        if (!is_string($service)) {
            return $service;
        }

        return $this->output($response, array_merge($this->services->getState($service), [
            'service'           => $service,

            'default_provision' => Database::isAutoMigrateEnabled()
                ? AppConfig::SERVICE_PROVISION_AUTO
                : AppConfig::SERVICE_PROVISION_MANUAL,

            'token_scopes'      => AppConfig::CD_SERVICE_TOKEN_SCOPES,
        ]), $request);
    }

    /**
     * POST /api/admin/integrated_services/{service}/enable — 启用并签发 / 恢复服务 token。
     *
     * 一次性明文 token 仅在新签发时随响应返回；恢复路径不重发明文（见下）。
     *
     * 三条路径（由契约行与凭据的状态决定，调用方据 `reused` / `reissued` 分辨）：
     *   - 首次启用：签发新凭据，返回 `token` 明文，reused / reissued 均为 false；
     *   - 冻结恢复：契约行未启用、凭据只是随服务被停用且未过期，恢复同一枚凭据，
     *     原明文重新生效（CD 无需改 CI_API_TOKEN），reused=true 且不返回 `token`；
     *   - 作废补发：凭据被撤销 / 删除 / 已过期，同事务签发新凭据并作废旧凭据，reissued=true，`token` 为新明文。
     *
     * 幂等边界：契约行已启用且凭据仍有效时返回 409（无意义的重复启用）。
     *
     * @param array<string,string> $args
     */
    public function enable(Request $request, Response $response, array $args): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requireSuperAdmin($response)) {
            return $resp;
        }
        $service = $this->knownService($response, (string) ($args['service'] ?? ''));
        if (!is_string($service)) {
            return $service;
        }

        $state = $this->services->getState($service);



        if ($state['enabled'] && $state['token_active']) {
            return $this->jsonError($response, 'integrated.already_enabled', 409);
        }







        $reuseTokenId = !$state['enabled']
            && $state['token_exists']
            && !$state['token_enabled']
            && !$state['token_expired']
            ? $state['token_id']
            : null;

        $previousTokenId = $state['enabled'] ? $state['token_id'] : null;


        $body      = $request->getParsedBody() ?? json_decode((string) $request->getBody(), true) ?? [];
        $provision = Database::isAutoMigrateEnabled()
            ? AppConfig::SERVICE_PROVISION_AUTO
            : AppConfig::SERVICE_PROVISION_MANUAL;
        if (isset($body['provision'])) {
            $requested = trim((string) $body['provision']);
            if (!in_array($requested, [AppConfig::SERVICE_PROVISION_AUTO, AppConfig::SERVICE_PROVISION_MANUAL], true)) {
                return $this->jsonError($response, 'integrated.invalid_provision', 400);
            }
            $provision = $requested;
        }

        try {
            $this->tx->begin();
            if ($reuseTokenId !== null) {
                $this->apiTokens->restore($reuseTokenId);
                $this->apiTokens->addScopes($reuseTokenId, AppConfig::CD_SERVICE_TOKEN_SCOPES);
                $this->services->enable($service, $provision, $reuseTokenId, $this->currentUser);
                $tokenId = $reuseTokenId;
                $plain   = null;
            } else {
                if ($previousTokenId !== null) {
                    $this->apiTokens->revoke($previousTokenId);
                }

                $created = $this->apiTokens->createToken([
                    'name'       => $service . '-service',
                    'scopes'     => AppConfig::CD_SERVICE_TOKEN_SCOPES,
                    'created_by' => $this->currentUser,
                    'note'       => 'Auto-issued when enabling integrated service: ' . $service,
                ]);
                $this->services->enable($service, $provision, $created['id'], $this->currentUser);
                $tokenId = $created['id'];
                $plain   = $created['token'];
            }
            $this->tx->commit();
        } catch (\Exception $e) {
            if ($this->tx->inTransaction()) {
                $this->tx->rollBack();
            }
            return $this->jsonError($response, $this->__('build.save_failed') . ': ' . $e->getMessage(), 500);
        }

        $this->operationLog->record(
            $this->currentUser,
            'enable_integrated_service',
            $service,
            ['provision' => $provision, 'token_id' => $tokenId, 'token_scopes' => AppConfig::CD_SERVICE_TOKEN_SCOPES,
                'reused' => $reuseTokenId !== null, 'reissued' => $plain !== null && $state['enabled'],
                'previous_token_id' => $previousTokenId],
            $this->clientIp($request)
        );

        return $this->output($response, [
            'success'   => true,
            'service'   => $service,
            'provision' => $provision,
            'token_id'  => $tokenId,

            'reused'    => $reuseTokenId !== null,

            'reissued'  => $plain !== null && $state['enabled'],

            'token'     => $plain,
        ], $request);
    }

    /**
     * POST /api/admin/integrated_services/{service}/disable — 禁用（冻结服务 + 撤销服务 token）。
     *
     * 不删除下游任何数据表/数据；从未启用时 409。
     *
     * @param array<string,string> $args
     */
    public function disable(Request $request, Response $response, array $args): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requireSuperAdmin($response)) {
            return $resp;
        }
        $service = $this->knownService($response, (string) ($args['service'] ?? ''));
        if (!is_string($service)) {
            return $service;
        }

        $state = $this->services->getState($service);
        if (!$state['enabled']) {
            return $this->jsonError($response, 'integrated.not_enabled', 409);
        }

        try {
            $this->tx->begin();

            $oldTokenId = $this->services->disable($service, $this->currentUser);
            if ($oldTokenId !== null) {
                $this->apiTokens->revoke($oldTokenId);
            }
            $this->tx->commit();
        } catch (\Exception $e) {
            if ($this->tx->inTransaction()) {
                $this->tx->rollBack();
            }
            return $this->jsonError($response, $this->__('build.modify_failed') . ': ' . $e->getMessage(), 500);
        }

        $this->operationLog->record(
            $this->currentUser,
            'disable_integrated_service',
            $service,
            ['token_id' => $oldTokenId],
            $this->clientIp($request)
        );

        return $this->output($response, ['success' => true, 'service' => $service], $request);
    }

    /**
     * 路由参数白名单校验：未知服务返回 404 响应，已知返回服务名。
     *
     * @return string|Response 字符串=合法服务名；Response=已构造的 404
     */
    private function knownService(Response $response, string $raw): string|Response
    {
        $service = strtolower(trim($raw));
        if (!in_array($service, self::KNOWN_SERVICES, true)) {
            return $this->jsonError($response, 'integrated.unknown_service', 404);
        }
        return $service;
    }

    private function requireSuperAdmin(Response $response): ?Response
    {
        if ($this->currentRole !== AppConfig::ROLE_SUPER_ADMIN) {
            return $this->jsonError($response, 'api_token.super_admin_only', 403);
        }
        return null;
    }

    private function clientIp(Request $request): string
    {
        return ClientIp::resolve($request->getServerParams(), $this->config->getTrustedProxyHops());
    }
}
