<?php

namespace App\Controller;

use App\Config\AppConfig;
use App\Service\I18nService;
use App\Service\IntegratedServiceRepository;
use App\Service\Settings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * 集成服务契约的窄只读端点：`GET /api/integration/services/{service}`。
 *
 * 存在理由（CD/CI 解耦）：集成服务此前要判断「本服务是否被 Glue 授权、以哪种建表方式授权」。本端点把这份
 * 契约搬到 HTTP 上：消费方只读一个窄接口。
 *
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
        private IntegratedServiceRepository $services,
        private Settings $settings
    ) {
        parent::__construct($i18n);
    }

    /**
     * @param array<string,string> $args 路由参数（service）
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_BUILD_RECORDS_PULL)) {
            return $resp;
        }

        $service = strtolower(trim((string) ($args['service'] ?? '')));
        if (!in_array($service, self::KNOWN_SERVICES, true)) {
            return $this->jsonError($response, 'integrated.unknown_service', 404);
        }

        $state = $this->services->getState($service);


        $scopes = $request->getAttribute('apiScopes');
        $canReadRegistry = !is_array($scopes)
            || in_array(AppConfig::API_SCOPE_REGISTRY_READ, $scopes, true);

        $harbor = $this->settings->getHarborConfig();
        return $this->output($response, [
            'service'       => $service,
            'enabled'       => $state['enabled'],
            'provision'     => $state['provision'],
            'token_active'  => $state['token_active'],
            'updated_at'    => $state['updated_at'],
            'registry_type' => $canReadRegistry ? $this->settings->getRegistryType() : '',
            'registry_url'  => $canReadRegistry ? (string) ($harbor['url'] ?? '') : '',
        ], $request);
    }
}
