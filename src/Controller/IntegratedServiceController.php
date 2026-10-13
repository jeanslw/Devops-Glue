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
 * 集成服务启停管理（后台 API 管理页的集成服务卡片，位于 Token 列表上方）。
 *
 * 信任模型：
 *   - 读状态：ci.system 权限（与系统信息面板同级）；
 *   - 启用/禁用：仅 super_admin（与 API token 签发、库结构迁移同级：启用会签发服务凭据，
 *     禁用会冻结一整个下游系统），API token 与普通管理员不可达（/api/admin 组 fail-closed）；
 *   - 启用是签发新凭据或恢复被冻结的同一枚凭据 + 写契约行的事务；禁用是冻结 + 停用 token 的事务；
 *   - 语义分界：禁用只是冻结，凭据行与外键一直留着，再次启用即恢复同一枚凭据，
 *     原明文继续可用、CD 无需更换 CI_API_TOKEN；只有作废（Token 列表撤销 / 删除 / 已过期）
 *     才需要补发新凭据，此时服务处于已启用但无凭据的故障态：再次启用即在同一事务内补发 + 重绑
 *     （不算重复启用、不返回 409，否则服务卡死且无法自助恢复）；
 *   - 集成服务（CD）侧对 integrated_services 只读，启停权在 CI。
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
            // 前端展示将签发哪些能力，后端启用时仍以此常量为准（不采用前端入参）
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
        // 幂等判断：只有已启用且凭据仍有效才算重复启用。
        // 已启用但凭据失效必须放行到下面的补发路径，否则在 Token 列表里撤销/删除 cd-service
        // 会把服务卡在已启用却无凭据的状态：勾选框点不动、直接调接口又返回 409。
        if ($state['enabled'] && $state['token_active']) {
            return $this->jsonError($response, 'integrated.already_enabled', 409);
        }

        // 冻结与作废分开处理，混成一种就会做错其中一件：
        //   - 冻结：契约行未启用 + 凭据行还在、只是被 enabled=0 停用且未过期，即上一次禁用的现场。
        //     revoke 只改 enabled、sha256 摘要未动，恢复同一枚即可让原明文重新生效，
        //     已配在 CD 里的 CI_API_TOKEN 无需更换；
        //   - 作废：凭据被撤销（契约行仍启用，说明有人显式作废了它）/ 被删除（外键置空、摘要已丢）/ 已过期，
        //     原凭据已不可用或不该复活，只能补发新凭据（CD 必须换明文），故不走恢复。
        $reuseTokenId = !$state['enabled']
            && $state['token_exists']
            && !$state['token_enabled']
            && !$state['token_expired']
            ? $state['token_id']
            : null;
        // 仅在已启用时把契约行上的旧凭据视为补发对象（禁用过的行仍留着旧 id，那条要么被恢复、要么已作废）
        $previousTokenId = $state['enabled'] ? $state['token_id'] : null;

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
            if ($reuseTokenId !== null) {
                // 冻结后恢复：把同一枚凭据重新启用 + 回写契约行（provision 允许在恢复时改写）。
                // 既不签发也不返回明文，CD 手上已有那枚 Token 的明文。
                $this->apiTokens->restore($reuseTokenId);
                $this->services->enable($service, $provision, $reuseTokenId, $this->currentUser);
                $tokenId = $reuseTokenId;
                $plain   = null;
            } else {
                // 作废后补发：把失效的旧凭据彻底关掉（已撤销 / 已被删除的行无副作用，只是关掉过期的那枚），
                // 与新凭据签发 + 契约行重绑同事务，避免留下两枚指向同一服务的凭据。
                if ($previousTokenId !== null) {
                    $this->apiTokens->revoke($previousTokenId);
                }
                // 凭据签发与契约行写入同事务：任一步失败整体回滚，不留下启用了却没 token、有 token 却未启用的中间态。
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
            // true = 本次恢复了原有凭据（禁用后再启用的常规路径）：明文不重发，CD 无需换 Token
            'reused'    => $reuseTokenId !== null,
            // true = 契约行本就启用、只是凭据已作废，本次补发新凭据（旧凭据保持作废，CD 必须换明文）
            'reissued'  => $plain !== null && $state['enabled'],
            // 明文仅此一次：DB 只存 sha256 摘要，丢失只能重新启用/重签；恢复路径没有明文可发（也无需发）
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
