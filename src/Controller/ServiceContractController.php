<?php

namespace App\Controller;

use App\Config\AppConfig;
use App\Service\I18nService;
use App\Service\IntegratedServiceRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * 集成服务契约的窄只读端点：`GET /api/integration/services/{service}`。
 *
 * 存在理由（CD/CI 解耦）：集成服务此前要判断「本服务是否被 Glue 授权、以哪种建表方式授权」，
 * 只能**直读共享库的 integrated_services 表** —— 这把集成服务与 CI 的库结构绑死（表名、列名、
 * 甚至驱动都是耦合面），CI 改库结构即可能弄坏 CD，将来拆库独立部署更是直接失效。本端点把这份
 * 契约搬到 HTTP 上：契约仍在 CI 手里（CI 是唯一写入方），消费方只读一个窄接口。
 *
 * 与 `/api/admin/integrated_services/{service}`（管理面）的分工：
 *   - 管理面：`ci.system` 权限，返回 token_id / token_scopes / default_provision 等后台渲染所需
 *     字段；且 `/api/admin/*` 对 API token 一律 fail-closed，服务账号天然不可达；
 *   - 本端点：API token 通道，scope 复用 `build.read`（CD 已持有，不为一个只读自检端点扩权），
 *     只回契约四要素 service / enabled / provision / token_active —— 刻意不回 token_id、
 *     token_name、updated_by：集成服务没有理由知道 CI 内部的凭据行号与操作人。
 *
 * 响应语义与 CD 的判定口径（写死在这里，避免消费方各自发明解释）：
 *   - 200 + enabled=false：**未启用**（契约行不存在，或 enabled=0，两者对外同义）→ 服务应拒绝
 *     对外服务并零 DDL；`provision` 此时归一化为 `auto`，仅为日志措辞，**不得**据此建表；
 *   - 200 + enabled=true + provision=auto：被授权自动建表 / 补齐既有表缺失列；
 *   - 200 + enabled=true + provision=manual：禁止建表，只认运维预建的 CD 表；
 *   - 404：服务名不在白名单（配置写错）。**与「未启用」严格区分**：CD 若把 404 当成未启用，
 *     一个拼错的服务名会静默退化成「一直未启用」而无人察觉；
 *   - token_active 是契约行绑定凭据的活性，**仅供展示**（如提示「凭据已失效，请在 CI 重新启用
 *     集成服务」）。它与调用方自己的 token 通常是同一枚（否则本次调用已被拒），故不可当闸门条件；
 *     升级兼容：v2.8.x 的 CI 没有本端点（404/405）、网络异常时，消费方应回退到旧口径（直读契约表），
 *     不得把「端点不可用」当成「未启用」。
 */
final class ServiceContractController extends BaseController
{
    /**
     * 允许查询的服务白名单：与 IntegratedServiceController::KNOWN_SERVICES 对齐。
     * 白名单化（而非直接透传参数）才能让「未知服务」与「未启用」用状态码区分开。
     *
     * @var list<string>
     */
    private const KNOWN_SERVICES = [
        AppConfig::SERVICE_CD,
    ];

    public function __construct(
        I18nService $i18n,
        private IntegratedServiceRepository $services
    ) {
        parent::__construct($i18n);
    }

    /**
     * @param array<string,string> $args 路由参数（service）
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        $this->initAuthFromRequest($request);
        // 与控制层其它只读端点同一权限点：持有 build.read 的 API token 由
        // AppConfig::API_SCOPE_PERMS 注入 ci.build-records.pull，因此无需为 CD 新增权限或 scope。
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_BUILD_RECORDS_PULL)) {
            return $resp;
        }

        $service = strtolower(trim((string) ($args['service'] ?? '')));
        if (!in_array($service, self::KNOWN_SERVICES, true)) {
            return $this->jsonError($response, 'integrated.unknown_service', 404);
        }

        $state = $this->services->getState($service);

        // 字段名与 `/api/admin/integrated_services/{service}` 的同名子集保持一致：
        // 两个通道读同一份契约，键名漂移会让同时用两种通道的消费方各写一套映射。
        return $this->output($response, [
            'service'      => $service,
            'enabled'      => $state['enabled'],
            'provision'    => $state['provision'],
            'token_active' => $state['token_active'],
            'updated_at'   => $state['updated_at'],
        ], $request);
    }
}
