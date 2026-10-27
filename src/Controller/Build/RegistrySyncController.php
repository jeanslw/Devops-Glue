<?php

declare(strict_types=1);

namespace App\Controller\Build;

use App\Config\AppConfig;
use App\Service\I18nService;
use App\Service\MappingManager;
use App\Service\OperationLogRepository;
use App\Service\RegistrySyncService;
use App\Service\Settings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * 镜像仓库同步：registry-sync 制品回写与 harbor-scan 附加扫描入口。
 */
final class RegistrySyncController extends BaseBuildController
{
    public function __construct(
        I18nService $i18n,
        Settings $config,
        MappingManager $mapping,
        OperationLogRepository $operationLog,
        private ?RegistrySyncService $registrySync = null
    ) {
        parent::__construct($i18n, $config, $mapping, $operationLog);
    }

    /**
     * POST /api/build/{path}/scan-sync — Harbor 存量入口，等同 registry-sync 并附加扫描
     *
     * @param array<string,string> $args
     */
    public function scanSync(Request $request, Response $response, array $args): Response
    {
        return $this->runRegistrySync($request, $response, $args, true, 'harbor-scan');
    }

    /**
     * POST /api/build/{path}/registry-sync — 制品回写基础端点，不依赖扫描能力
     *
     * @param array<string,string> $args
     */
    public function registrySync(Request $request, Response $response, array $args): Response
    {
        return $this->runRegistrySync($request, $response, $args, false, 'registry-sync');
    }

    /**
     * @param array<string,string> $args
     */
    private function runRegistrySync(Request $request, Response $response, array $args, bool $withScan, string $checkType): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_TRIGGER)) {
            return $resp;
        }
        if ($this->registrySync === null) {
            return $this->jsonError($response, $this->__('build.registry_not_configured'), 503);
        }

        $parsedBody = $request->getParsedBody();
        $body       = is_array($parsedBody) ? $parsedBody : [];
        $result     = $this->registrySync->sync($args['path'] ?? '', $body, $withScan, $checkType);

        if (isset($result['error'])) {
            return $this->jsonError($response, $result['error'], (int) ($result['http_status'] ?? 400));
        }

        $sha = (string) ($result['sha'] ?? '');
        return $this->output($response, [
            'build_provider'       => (string) ($result['build_provider'] ?? ''),
            'sha'                  => $sha !== '' ? $sha : 'N/A',
            'tag'                  => (string) ($result['tag'] ?? ''),
            'registry_repository'  => (string) ($result['registry_repository'] ?? ''),
            'vulnerability_count'  => (int) ($result['vulnerability_count'] ?? 0),
            'scan_state'           => (string) ($result['scan_state'] ?? 'unknown'),
            'commit_status'        => $result['commit_status'] ?? ['success' => false, 'message' => ''],
        ], $request);
    }
}
