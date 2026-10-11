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
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * 集成服务启停管理（后台「数据管理 → 集成服务」）。
 *
 * 信任模型：
 *   - 读状态：ci.system 权限（与系统信息面板同级）；
 *   - 启用/禁用：**仅 super_admin**（与 API token 签发、库结构迁移同级 —— 启用会签发服务凭据，
 *     禁用会撤销凭据并冻结一整个下游系统），API token 与普通管理员一律不可达（/api/admin 组 fail-closed）；
 *   - 启用是一次「签发限定 scope token + 写契约行」的事务；禁用是「冻结 + 撤销 token」的事务；
 *   - 集成服务（CD）侧对 integrated_services 只读，启停权永远在 CI。
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

    private ?OperationLogRepository $operationLog = null;

    public function __construct(
        I18nService $i18n,
        private \PDO $pdo,
        private IntegratedServiceRepository $services,
        private ApiTokenService $apiTokens,
        private Settings $config
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
            return $service; // 404 响应
        }

        return $this->output($response, array_merge($this->services->getState($service), [
            'service'           => $service,
            // 启用时将写入的 provision：跟随 CI 当前迁移模式（界面据此提示下游将自动/手动建表）
            'default_provision' => Database::isAutoMigrateEnabled()
                ? AppConfig::SERVICE_PROVISION_AUTO
                : AppConfig::SERVICE_PROVISION_MANUAL,
            // 前端展示「将签发哪些能力」，后端启用时仍以此常量为准（不信前端入参）
            'token_scopes'      => AppConfig::CD_SERVICE_TOKEN_SCOPES,
        ]), $request);
    }

    /**
     * POST /api/admin/integrated_services/{service}/enable — 启用并自动签发服务 token。
     *
     * 一次性明文 token 仅在响应里返回这一次；已启用时 409（必须先禁用才能重新启用，重新启用必换新凭据）。
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
        if ($state['enabled']) {
            return $this->jsonError($response, 'integrated.already_enabled', 409);
        }

        // provision 默认跟随 CI 当前迁移模式；允许 body 显式覆盖（界面展示后可不传），只接受 auto/manual。
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
            $this->pdo->beginTransaction();
            // 凭据签发与契约行写入同事务：任一步失败整体回滚，不留下「启用了但没 token / 有 token 却未启用」。
            $created = $this->apiTokens->createToken([
                'name'       => $service . '-service',
                'scopes'     => AppConfig::CD_SERVICE_TOKEN_SCOPES,
                'created_by' => $this->currentUser,
                'note'       => 'Auto-issued when enabling integrated service: ' . $service,
            ]);
            $this->services->enable($service, $provision, $created['id'], $this->currentUser);
            $this->pdo->commit();
        } catch (\Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return $this->jsonError($response, $this->__('build.save_failed') . ': ' . $e->getMessage(), 500);
        }

        $this->opLog()->record(
            $this->currentUser,
            'enable_integrated_service',
            $service,
            ['provision' => $provision, 'token_id' => $created['id'], 'token_scopes' => AppConfig::CD_SERVICE_TOKEN_SCOPES],
            $this->clientIp($request)
        );

        return $this->output($response, [
            'success'   => true,
            'service'   => $service,
            'provision' => $provision,
            'token_id'  => $created['id'],
            // 明文仅此一次：DB 只存 sha256 摘要，丢失只能重新启用/重签
            'token'     => $created['token'],
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
            $this->pdo->beginTransaction();
            // 冻结契约行 + 撤销配套 token 同事务：禁用后该凭据对 CI 的 HTTP 调用也立即失效。
            $oldTokenId = $this->services->disable($service, $this->currentUser);
            if ($oldTokenId !== null) {
                $this->apiTokens->revoke($oldTokenId);
            }
            $this->pdo->commit();
        } catch (\Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return $this->jsonError($response, $this->__('build.modify_failed') . ': ' . $e->getMessage(), 500);
        }

        $this->opLog()->record(
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

    private function opLog(): OperationLogRepository
    {
        if ($this->operationLog === null) {
            $this->operationLog = new OperationLogRepository($this->pdo);
        }
        return $this->operationLog;
    }

    private function clientIp(Request $request): string
    {
        return ClientIp::resolve($request->getServerParams(), $this->config->getTrustedProxyHops());
    }
}
