<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Config\AppConfig;
use App\Service\HarborService;
use App\Service\JenkinsService;
use App\Service\PlatformVersionRepository;
use App\Service\RegistryProbe;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * 后台平台 API 版本（Harbor / Jenkins / Git 平台版本态与探测）。
 * 方法体从 AdminController 原样搬迁。
 */
class VersionController extends BaseAdminController
{
    private PlatformVersionRepository $platformVersions;
    private ?HarborService $harbor;
    private ?JenkinsService $jenkins = null;

    public function __construct(
        \App\Service\I18nService $i18n,
        \App\Service\Settings $config,
        \App\Service\AppSettingRepository $appSettings,
        \App\Service\OperationLogRepository $operationLog,
        PlatformVersionRepository $platformVersions,
        ?HarborService $harbor = null,
        ?JenkinsService $jenkins = null
    ) {
        parent::__construct($i18n, $config, $appSettings, $operationLog);
        $this->platformVersions = $platformVersions;
        $this->harbor = $harbor;
        $this->jenkins = $jenkins;
    }

    /**
     * GET /api/admin/platform_versions — 获取所有平台 API 版本及配置状态
     */
    public function platformVersionsList(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_PLATFORM_EDIT)) {
            return $resp;
        }
        $versions = $this->config->getPlatformApiVersionsWithSource();

        $gitPlatforms = $this->config->getGitPlatformsConfig();
        $configuredGit = array_column($gitPlatforms, 'name');

        foreach ($versions as $name => &$info) {
            $info['configured'] = in_array($name, $configuredGit) || $name === 'harbor';
        }
        unset($info);

        if (isset($versions['harbor'])) {
            $registryType = $this->config->getRegistryType();
            $versions['harbor']['registry_type'] = $registryType;
            $versions['harbor']['robot_account'] = $registryType === 'registry'
                ? false
                : $this->config->isHarborRobotAccount();
        }

        $hasJenkins = !empty($this->config->getJenkinsConfig()['url']);
        $versions['jenkins'] = [
            'value'      => '',
            'source'     => 'config',
            'configured' => $hasJenkins,
        ];

        return $this->output($response, ['versions' => $versions], $request);
    }

    /**
     * GET /api/admin/platform_versions/probe — 实际探测 Harbor / Jenkins 版本（慢，独立端点）。
     * 与列表接口分离：列表毫秒级返回配置态，探测结果由前端后台补齐，避免平台不可达时整页卡住。
     */
    public function platformVersionsProbe(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_PLATFORM_EDIT)) {
            return $resp;
        }

        $result = ['harbor' => [], 'jenkins' => []];
        if ($this->config->getRegistryType() === 'registry') {
            try {
                $cfg = $this->config->getHarborConfig();
                $probe = new \App\Service\RegistryProbe(
                    (string)($cfg['url'] ?? ''),
                    (string)($cfg['username'] ?? 'admin'),
                    (string)($cfg['password'] ?? '')
                );
                $result['harbor']['detected_version'] = $probe->distributionVersion();
            } catch (\Throwable $e) {
                \App\Helper\Log::error('[版本探测] 镜像仓库连接失败', ['error' => $e->getMessage()]);
            }
        } else {
            try {
                $result['harbor']['detected_version'] = $this->harbor?->getHarborVersion();
                $result['harbor']['robot_support']    = $this->harbor?->getRobotAccountSupport() ?? 'unknown';
            } catch (\Throwable $e) {
                \App\Helper\Log::error('[版本探测] 镜像仓库连接失败', ['error' => $e->getMessage()]);
            }
        }

        if (!empty($this->config->getJenkinsConfig()['url'])) {
            try {
                $result['jenkins']['detected_version'] = $this->jenkins?->getVersion();
            } catch (\Throwable $e) {
                \App\Helper\Log::error('[版本探测] Jenkins 连接失败', ['error' => $e->getMessage()]);
            }
        }

        return $this->output($response, $result, $request);
    }

    /**
     * PUT /api/admin/platform_versions — 更新平台 API 版本
     */
    public function platformVersionsUpdate(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_PLATFORM_EDIT)) {
            return $resp;
        }
        $body = $request->getParsedBody() ?? json_decode($request->getBody()->__toString(), true) ?? [];

        $versions = $body['versions'] ?? [];
        if (!is_array($versions)) {
            return $this->jsonError($response, 'platform.versions_empty', 400);
        }

        if ($versions === []) {
            return $this->output($response, ['success' => true, 'changed' => false, 'versions' => $this->config->getPlatformApiVersions()], $request);
        }

        $this->platformVersions->saveAll($versions);
        $this->opLog()->record($this->currentUser, 'update_platform_version', '', ['versions' => $versions], $this->clientIp($request), 'success');
        return $this->output($response, ['success' => true, 'changed' => true, 'versions' => $this->config->getPlatformApiVersions()], $request);
    }
}
