<?php

declare(strict_types=1);

namespace App\Controller\Build;

use App\Config\AppConfig;
use App\Service\Build\BuildProviderRegistry;
use App\Service\I18nService;
use App\Service\MappingManager;
use App\Service\OperationLogRepository;
use App\Service\Settings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * 构建操作：触发、重试、取消流水线。
 */
final class BuildActionController extends BaseBuildController
{
    public function __construct(
        I18nService $i18n,
        Settings $config,
        MappingManager $mapping,
        OperationLogRepository $operationLog,
        private BuildProviderRegistry $registry
    ) {
        parent::__construct($i18n, $config, $mapping, $operationLog);
    }

    /**
     * POST /api/build/{path}/trigger（兼容 GET Query String 触发）
     *
     * @param array<string,string> $args
     */
    public function trigger(Request $request, Response $response, array $args): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_TRIGGER)) {
            return $resp;
        }

        $path = $args['path'] ?? '';
        $parsedBody = $request->getParsedBody();
        $body       = is_array($parsedBody) ? $parsedBody : [];
        $qs   = $request->getQueryParams();
        [$provider, $projectId] = $this->resolve($path);

        if (!$this->registry->isRegistered($provider)) {
            return $this->jsonError($response, $this->__('build.provider_not_configured', ['{provider}' => $provider]), 400);
        }

        $ref  = $body['ref'] ?? $qs['ref'] ?? '';
        $vars = $body['variables'] ?? [];
        foreach ($body as $k => $v) {
            if (!in_array($k, ['ref','variables','format','token']) && !isset($vars[$k])) {
                $vars[$k] = $v;
            }
        }
        foreach ($qs as $k => $v) {
            if (!in_array($k, ['format','token','ref']) && !isset($vars[$k])) {
                $vars[$k] = $v;
            }
        }

        $p      = $this->registry->create($provider);
        if ($p instanceof \App\Service\Build\CustomPushBuildProvider) {
            return $this->jsonError($response, 'build.custom_push_no_trigger', 400);
        }
        $result = $p->trigger($projectId, $ref, $vars);
        $this->opLog()->record($this->currentUser, 'build_trigger', $path, ['provider' => $provider, 'ref' => $ref], $this->clientIp($request), 'success', $this->operatorType());
        return $this->output($response, [
            'build_provider' => $provider,
            'project_id'     => $projectId,
        ] + $result, $request);
    }

    /**
     * POST /api/build/{path}/pipelines/{id}/retry
     *
     * @param array<string,string> $args
     */
    public function retry(Request $request, Response $response, array $args): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_TRIGGER)) {
            return $resp;
        }

        $path       = $args['path'] ?? '';
        $pipelineId = (int) ($args['id'] ?? 0);
        [$provider, $projectId] = $this->resolve($path);

        if (!$this->registry->isRegistered($provider)) {
            return $this->jsonError($response, $this->__('build.provider_not_configured', ['{provider}' => $provider]), 400);
        }

        $p      = $this->registry->create($provider);
        $result = $p->retry($projectId, $pipelineId);
        $this->opLog()->record($this->currentUser, 'build_retry', $path, ['provider' => $provider, 'pipeline_id' => $pipelineId], $this->clientIp($request), 'success', $this->operatorType());
        return $this->output($response, [
            'build_provider' => $provider,
            'project_id'     => $projectId,
        ] + $result, $request);
    }

    /**
     * POST /api/build/{path}/pipelines/{id}/cancel
     *
     * @param array<string,string> $args
     */
    public function cancel(Request $request, Response $response, array $args): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_TRIGGER)) {
            return $resp;
        }

        $path       = $args['path'] ?? '';
        $pipelineId = (int) ($args['id'] ?? 0);
        [$provider, $projectId] = $this->resolve($path);

        if (!$this->registry->isRegistered($provider)) {
            return $this->jsonError($response, $this->__('build.provider_not_configured', ['{provider}' => $provider]), 400);
        }

        $p      = $this->registry->create($provider);
        $result = $p->cancel($projectId, $pipelineId);
        $this->opLog()->record($this->currentUser, 'build_cancel', $path, ['provider' => $provider, 'pipeline_id' => $pipelineId], $this->clientIp($request), 'success', $this->operatorType());
        return $this->output($response, [
            'build_provider' => $provider,
            'project_id'     => $projectId,
        ] + $result, $request);
    }
}
