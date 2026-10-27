<?php

declare(strict_types=1);

namespace App\Controller\Build;

use App\Config\AppConfig;
use App\Service\AppSettingRepository;
use App\Service\Build\BuildProviderRegistry;
use App\Service\I18nService;
use App\Service\MappingManager;
use App\Service\OperationLogRepository;
use App\Service\PipelineArtifactService;
use App\Service\Settings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * 构建概览：项目列表、项目 tag 分页列表、构建模式。
 */
final class BuildOverviewController extends BaseBuildController
{
    public function __construct(
        I18nService $i18n,
        Settings $config,
        MappingManager $mapping,
        OperationLogRepository $operationLog,
        private BuildProviderRegistry $registry,
        private AppSettingRepository $appSettings,
        private PipelineArtifactService $artifacts
    ) {
        parent::__construct($i18n, $config, $mapping, $operationLog);
    }

    public function jobsList(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_BUILD_RECORDS_PULL)) {
            return $resp;
        }

        $all = [];
        foreach ($this->mapping->activeMaps() as $m) {
            $all[] = [
                'job_name'         => $m['job_name'] ?? '',
                'ci_provider'      => (string) ($m['build_provider'] ?? ''),
                'project_id'       => $m['project_id'] ?? ($m['current_path'] ?? $m['job_name']),
                'current_path'     => $m['current_path'] ?? '',
                'registry_repository' => $m['registry_repository'] ?? '',
                'git_platform'     => $m['git_platform'] ?? '',
            ];
        }
        if (($request->getQueryParams()['format'] ?? 'raw') === 'raw') {
            return $this->output($response, array_column($all, 'job_name'), $request);
        }
        return $this->output($response, $all, $request);
    }

    public function projectsList(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_BUILD_RECORDS_PULL)) {
            return $resp;
        }

        $maps = array_values(array_filter(
            $this->mapping->allMaps(),
            fn($m) => ($m['status'] ?? AppConfig::STATUS_ACTIVE) === AppConfig::STATUS_ACTIVE
        ));

        $latest = [];
        try {
            $latest = $this->artifacts->latestTagsByProject();
        } catch (\Exception $e) {
            $latest = [];
        }

        $result = [];
        foreach ($maps as $m) {
            $job = $m['job_name'] ?? '';
            $cp  = $m['current_path'] ?? '';
            $lt  = null;
            foreach (array_unique([$job, $cp]) as $k) {
                if ($k === '' || !isset($latest[$k])) {
                    continue;
                }
                if ($lt === null || $latest[$k]['created_at'] > $lt['created_at']) {
                    $lt = $latest[$k];
                }
            }
            $result[] = [
                'job_name'          => $job,
                'build_provider'    => (string) ($m['build_provider'] ?? ''),
                'current_path'      => $cp,
                'registry_repository' => $m['registry_repository'] ?? '',
                'git_platform'      => $m['git_platform'] ?? '',
                'status'            => $m['status'] ?? AppConfig::STATUS_ACTIVE,
                'latest_tag'        => $lt['tag'] ?? '',
                'latest_pipeline'   => $lt['pipeline_iid'] ?? '',
                'tag_time'          => $lt['created_at'] ?? '',
            ];
        }
        return $this->output($response, $result, $request);
    }

    /**
     * GET /api/build/{path}/tags — 项目 tag 列表（分页），keys = job_name + current_path
     *
     * @param array<string,string> $args
     */
    public function tagsList(Request $request, Response $response, array $args): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_BUILD_RECORDS_PULL)) {
            return $resp;
        }

        $path     = $args['path'] ?? '';
        $page     = max(1, (int) ($request->getQueryParams()['page'] ?? 1));
        $pageSize = (int) ($request->getQueryParams()['page_size'] ?? 50);
        if ($pageSize < 1 || $pageSize > 200) {
            $pageSize = 50;
        }

        $keys = [$path];
        foreach ($this->mapping->allMaps() as $m) {
            $job = $m['job_name'] ?? '';
            $cp  = $m['current_path'] ?? '';
            if ($job === $path || $cp === $path) {
                foreach (['job_name', 'current_path'] as $f) {
                    if (!empty($m[$f]) && $m[$f] !== $path) {
                        $keys[] = $m[$f];
                    }
                }
            }
        }
        $keys = array_values(array_unique($keys));

        try {
            $total = $this->artifacts->countByProjectKeys($keys);

            $totalPages = $total ? max(1, (int) ceil($total / $pageSize)) : 1;
            if ($page > $totalPages) {
                $page = $totalPages;
            }
            $offset = ($page - 1) * $pageSize;

            $items = $this->artifacts->listByProjectKeys($keys, $pageSize, $offset);

            return $this->output($response, [
                'items'       => $items,
                'total'       => $total,
                'page'        => $page,
                'page_size'   => $pageSize,
                'total_pages' => $totalPages,
            ], $request);
        } catch (\Exception $e) {
            return $this->output($response, [
                'items'       => [],
                'total'       => 0,
                'page'        => $page,
                'page_size'   => $pageSize,
                'total_pages' => 1,
            ], $request);
        }
    }

    public function configMode(Request $request, Response $response): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_BUILD_RECORDS_PULL)) {
            return $resp;
        }

        $hasJenkins = $this->registry->isRegistered(AppConfig::PROVIDER_JENKINS);
        $hasGitlab  = $this->registry->isRegistered(AppConfig::PROVIDER_GITLAB_CI);
        $hasGitea   = $this->registry->isRegistered(AppConfig::PROVIDER_GITEA_CI);
        $hasGithub  = $this->registry->isRegistered(AppConfig::PROVIDER_GITHUB_ACTIONS);
        $modes  = $this->appSettings->getBuildModes();
        $source = $this->appSettings->getBuildModeSource();

        $customProviders = [];
        foreach ($this->config->getCustomBuildProviders() as $p) {
            $name = (string) ($p['name'] ?? '');
            if ($name !== '' && $this->registry->isRegistered($name)) {
                $customProviders[] = $name;
            }
        }

        return $this->output($response, [
            'mode'                  => $this->appSettings->getBuildMode(),
            'modes'                 => $modes,
            'source'                => $source,
            'has_jenkins'           => $hasJenkins,
            'has_gitlab_ci'         => $hasGitlab,
            'has_gitea_ci'          => $hasGitea,
            'has_github_actions'    => $hasGithub,
            'has_custom_push'       => !empty($customProviders),
            'custom_push_enabled'   => $this->appSettings->getCustomPushEnabled(),
            'custom_providers'      => $customProviders,
        ], $request);
    }
}
