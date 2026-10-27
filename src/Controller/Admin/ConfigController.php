<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Config\AppConfig;
use App\Service\MappingManager;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * 后台构建模式与平台级配置（build_mode / platform_config）。
 * 方法体从 AdminController 原样搬迁。
 */
class ConfigController extends BaseAdminController
{
    private MappingManager $mapping;

    public function __construct(
        \App\Service\I18nService $i18n,
        \App\Service\Settings $config,
        \App\Service\AppSettingRepository $appSettings,
        \App\Service\OperationLogRepository $operationLog,
        MappingManager $mapping
    ) {
        parent::__construct($i18n, $config, $appSettings, $operationLog);
        $this->mapping = $mapping;
    }

    /**
     * GET /api/admin/build_mode — 获取构建模式及可用状态
     */
    public function getBuildMode(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_MODE_EDIT)) {
            return $resp;
        }
        $modes = $this->appSettings->getBuildModes();

        $jenkinsCfg = $this->config->getJenkinsConfig();
        $hasJenkins = !empty($jenkinsCfg['url']);
        $hasGitlab = $this->config->isPlatformConfigured('gitlab');
        $glCfg = $hasGitlab ? $this->config->getGitlabConfig() : [];
        $hasGitlabCi = $hasGitlab && !empty($glCfg['base_url']) && !empty($glCfg['token']);
        $hasGitea = $this->config->isPlatformConfigured('gitea');
        $giteaCfg = $hasGitea ? $this->config->getGiteaConfig() : [];
        $hasGiteaCi = $hasGitea && !empty($giteaCfg['base_url']) && !empty($giteaCfg['token']);
        $hasGithub = $this->config->isPlatformConfigured('github');
        $githubCfg = $hasGithub ? $this->config->getGithubConfig() : [];
        $hasGithubActions = $hasGithub && !empty($githubCfg['base_url']) && !empty($githubCfg['token']);

        return $this->output($response, [
            'mode'          => $this->appSettings->getBuildMode(),
            'modes'         => $modes,
            'source'        => $this->appSettings->getBuildModeSource(),
            'has_jenkins'   => $hasJenkins,
            'has_gitlab_ci' => $hasGitlabCi,
            'has_gitea_ci'  => $hasGiteaCi,
            'has_github_actions' => $hasGithubActions,
            'custom_push_enabled' => $this->appSettings->getCustomPushEnabled(),
            'custom_providers' => array_column($this->config->getCustomBuildProviders(), 'name'),
        ], $request);
    }

    /**
     * PUT /api/admin/build_mode — 更新构建模式
     */
    public function updateBuildMode(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_MODE_EDIT)) {
            return $resp;
        }
        $body = $request->getParsedBody() ?? json_decode($request->getBody()->__toString(), true) ?? [];
        $modes = $body['modes'] ?? null;
        if (!is_array($modes)) {
            $modes = isset($body['mode']) ? [trim((string) $body['mode'])] : [];
        }
        $modes = array_values(array_unique(array_filter(array_map('trim', $modes), fn($s) => $s !== '')));
        $cpEnabled = !empty($body['custom_push_enabled']);

        foreach ($modes as $m) {
            if (!in_array($m, AppConfig::BUILTIN_PULL_PROVIDERS, true)) {
                return $this->jsonError($response, 'build.mode_required', 400);
            }
        }

        $jenkinsCfg = $this->config->getJenkinsConfig();
        $hasJenkins = !empty($jenkinsCfg['url']);
        $hasGitlab = $this->config->isPlatformConfigured('gitlab');
        $glCfg = $hasGitlab ? $this->config->getGitlabConfig() : [];
        $hasGitlabCi = $hasGitlab && !empty($glCfg['base_url']) && !empty($glCfg['token']);
        $hasGitea = $this->config->isPlatformConfigured('gitea');
        $giteaCfg = $hasGitea ? $this->config->getGiteaConfig() : [];
        $hasGiteaCi = $hasGitea && !empty($giteaCfg['base_url']) && !empty($giteaCfg['token']);
        $hasGithub = $this->config->isPlatformConfigured('github');
        $githubCfg = $hasGithub ? $this->config->getGithubConfig() : [];
        $hasGithubActions = $hasGithub && !empty($githubCfg['base_url']) && !empty($githubCfg['token']);

        if (in_array(AppConfig::PROVIDER_JENKINS, $modes, true) && !$hasJenkins) {
            return $this->jsonError($response, 'build.jenkins_unavail', 400);
        }
        if (in_array(AppConfig::PROVIDER_GITLAB_CI, $modes, true) && !$hasGitlabCi) {
            return $this->jsonError($response, 'build.gitlab_ci_unavail', 400);
        }
        if (in_array(AppConfig::PROVIDER_GITEA_CI, $modes, true) && !$hasGiteaCi) {
            return $this->jsonError($response, 'build.gitea_ci_unavail', 400);
        }
        if (in_array(AppConfig::PROVIDER_GITHUB_ACTIONS, $modes, true) && !$hasGithubActions) {
            return $this->jsonError($response, 'build.github_actions_unavail', 400);
        }
        if ($cpEnabled && empty($this->config->getCustomBuildProviders())) {
            return $this->jsonError($response, 'build.custom_push_no_provider', 400);
        }

        try {
            $this->appSettings->setBuildModes($modes);
            $this->appSettings->setCustomPushEnabled($cpEnabled);

            $removed = array_values(array_diff(AppConfig::BUILTIN_PULL_PROVIDERS, $modes));
            if (!empty($removed)) {
                $maps = $this->mapping->allMaps();
                $changed = false;
                foreach ($maps as &$m) {
                    $bp = (string) ($m['build_provider'] ?? '');
                    if ($bp === '') {
                        continue;
                    }
                    if (in_array($bp, $removed, true) && ($m['status'] ?? AppConfig::STATUS_ACTIVE) !== AppConfig::STATUS_PENDING) {
                        $m['status'] = AppConfig::STATUS_PENDING;
                        $changed = true;
                    }
                }
                if ($changed) {
                    $this->mapping->saveMaps($maps);
                    $this->mapping->invalidateMapListCache();
                }
            }

            if (!$cpEnabled) {
                $maps = $this->mapping->allMaps();
                $changed = false;
                foreach ($maps as &$m) {
                    if (
                        ($m['build_provider'] ?? '') === AppConfig::PROVIDER_CUSTOM_PUSH
                        && ($m['status'] ?? AppConfig::STATUS_ACTIVE) !== AppConfig::STATUS_PENDING
                    ) {
                        $m['status'] = AppConfig::STATUS_PENDING;
                        $changed = true;
                    }
                }
                if ($changed) {
                    $this->mapping->saveMaps($maps);
                    $this->mapping->invalidateMapListCache();
                }
            }

            $this->opLog()->record($this->currentUser, 'update_build_mode', '', ['modes' => $modes, 'custom_push_enabled' => $cpEnabled], $this->clientIp($request), 'success');
            return $this->output($response, ['success' => true, 'modes' => $modes, 'custom_push_enabled' => $cpEnabled], $request);
        } catch (\Exception $e) {
            return $this->jsonError($response, $this->__('build.save_failed') . ': ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/admin/platform_config — 各平台接入配置状态（只返回已配置布尔，不下发 URL/账号/凭证）
     * 权限：ci.platform-config
     */
    public function platformConfig(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_PLATFORM_CONFIG)) {
            return $resp;
        }
        try {
            $c = $this->config;
            $jenkins = $c->getJenkinsConfig();
            $gitlab  = $c->getGitlabConfig();
            $github  = $c->getGithubConfig();
            $gitee   = $c->getGiteeConfig();
            $gitea   = $c->getGiteaConfig();
            $harbor  = $c->getHarborConfig();


            $git = fn(array $cfg) => !empty($cfg['base_url']) && !empty($cfg['token']);

            return $this->output($response, [
                'platforms' => [
                    'jenkins'     => ['configured' => !empty($jenkins['url'])],
                    'gitlab'      => ['configured' => $git($gitlab)],
                    'github'      => ['configured' => $git($github)],
                    'gitee'       => ['configured' => $git($gitee)],
                    'gitea'       => ['configured' => $git($gitea)],
                    'harbor'      => ['configured' => !empty($harbor['url']) && ($c->getRegistryType() === 'registry' || !empty($harbor['password']))],
                ],
                // 平台级 tag 设置（清理/回填/日志关键字）
                'stale_tag_cleanup_enabled' => $this->appSettings->getStaleTagCleanupEnabled(),
                'backfill_tag_enabled'      => $this->appSettings->getBackfillTagEnabled(),
                'tag_log_keyword'           => $this->appSettings->getTagLogKeyword(),
                // API 调用审计日志设置（写入级别 / 保留天数 / 清理开关）
                'api_access_log_level'           => $this->appSettings->getApiAccessLogLevel(),
                'api_access_log_retain_days'     => $this->appSettings->getApiAccessLogRetainDays(),
                'api_access_log_cleanup_enabled' => $this->appSettings->getApiAccessLogCleanupEnabled(),
            ], $request);
        } catch (\Exception $e) {
            return $this->jsonError($response, $this->__('build.query_failed') . ': ' . $e->getMessage(), 500);
        }
    }

    /**
     * PUT /api/admin/platform_config — 更新平台级 tag 设置（清理开关 / 回填开关 / 日志关键字）
     * 权限：ci.platform-config（平台管理）。这些设置与构建模式无关，故从 build_mode 移出。
     * 仅更新请求体显式携带的键，避免互相覆盖。
     */
    public function updatePlatformConfig(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_PLATFORM_CONFIG)) {
            return $resp;
        }
        $body = $request->getParsedBody() ?? json_decode($request->getBody()->__toString(), true) ?? [];

        try {
            if (array_key_exists('stale_tag_cleanup_enabled', $body)) {
                $this->appSettings->setStaleTagCleanupEnabled(!empty($body['stale_tag_cleanup_enabled']));
            }
            if (array_key_exists('backfill_tag_enabled', $body)) {
                $this->appSettings->setBackfillTagEnabled(!empty($body['backfill_tag_enabled']));
            }
            if (array_key_exists('tag_log_keyword', $body)) {
                $this->appSettings->setTagLogKeyword(trim((string) ($body['tag_log_keyword'] ?? '')));
            }
            if (array_key_exists('api_access_log_level', $body)) {
                $this->appSettings->setApiAccessLogLevel((string)($body['api_access_log_level'] ?? ''));
            }
            if (array_key_exists('api_access_log_retain_days', $body)) {
                $this->appSettings->setApiAccessLogRetainDays((int)($body['api_access_log_retain_days'] ?? 0));
            }
            if (array_key_exists('api_access_log_cleanup_enabled', $body)) {
                $this->appSettings->setApiAccessLogCleanupEnabled(!empty($body['api_access_log_cleanup_enabled']));
            }
        } catch (\InvalidArgumentException $e) {
            return $this->jsonError($response, $e->getMessage(), 400);
        } catch (\Exception $e) {
            return $this->jsonError($response, $this->__('build.query_failed') . ': ' . $e->getMessage(), 500);
        }

        return $this->output($response, [
            'stale_tag_cleanup_enabled' => $this->appSettings->getStaleTagCleanupEnabled(),
            'backfill_tag_enabled'      => $this->appSettings->getBackfillTagEnabled(),
            'tag_log_keyword'           => $this->appSettings->getTagLogKeyword(),
            'api_access_log_level'           => $this->appSettings->getApiAccessLogLevel(),
            'api_access_log_retain_days'     => $this->appSettings->getApiAccessLogRetainDays(),
            'api_access_log_cleanup_enabled' => $this->appSettings->getApiAccessLogCleanupEnabled(),
        ], $request);
    }
}
