<?php

declare(strict_types=1);

namespace App\Controller\Build;

use App\Config\AppConfig;
use App\Controller\BaseController;
use App\Helper\ClientIp;
use App\Service\I18nService;
use App\Service\MappingManager;
use App\Service\OperationLogRepository;
use App\Service\Settings;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * 构建模块控制器基类：映射解析、审计日志、客户端 IP 与操作人类型。
 */
abstract class BaseBuildController extends BaseController
{
    protected Settings $config;
    protected MappingManager $mapping;

    public function __construct(
        I18nService $i18n,
        Settings $config,
        MappingManager $mapping,
        private OperationLogRepository $operationLog
    ) {
        parent::__construct($i18n);
        $this->config  = $config;
        $this->mapping = $mapping;
    }

    protected function opLog(): OperationLogRepository
    {
        return $this->operationLog;
    }

    /** 客户端 IP（TRUSTED_PROXY_HOPS 门控）。 */
    protected function clientIp(Request $request): string
    {
        return ClientIp::resolve($request->getServerParams(), $this->config->getTrustedProxyHops());
    }

    /** 操作人类型：API token（服务账号）与后台登录用户区分。 */
    protected function operatorType(): string
    {
        return $this->currentRole === AppConfig::ROLE_API_TOKEN ? 'api_token' : 'admin';
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function resolve(string $projectPath): array
    {
        $r = $this->mapping->resolveProject($projectPath);
        return [$r['provider'], $r['projectId']];
    }
}
