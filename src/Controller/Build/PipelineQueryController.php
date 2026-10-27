<?php

declare(strict_types=1);

namespace App\Controller\Build;

use App\Config\AppConfig;
use App\Service\Build\BuildProviderRegistry;
use App\Service\BuildTagCacheRepository;
use App\Service\I18nService;
use App\Service\MappingManager;
use App\Service\OperationLogRepository;
use App\Service\SecurityCheckRepository;
use App\Service\Settings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * 构建查询：流水线列表/详情、日志、变量、分支，附带 pull 式 tag 投影。
 */
final class PipelineQueryController extends BaseBuildController
{
    public function __construct(
        I18nService $i18n,
        Settings $config,
        MappingManager $mapping,
        OperationLogRepository $operationLog,
        private BuildProviderRegistry $registry,
        private SecurityCheckRepository $securityChecks,
        private BuildTagCacheRepository $buildTagCache
    ) {
        parent::__construct($i18n, $config, $mapping, $operationLog);
    }

    /**
     * GET /api/build/{path}/pipelines — raw: 流水线数组, json/xml: 完整元数据
     *
     * @param array<string,string> $args
     */
    public function pipelines(Request $request, Response $response, array $args): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_BUILD_RECORDS_PULL)) {
            return $resp;
        }

        $path = $args['path'] ?? '';
        [$provider, $projectId] = $this->resolve($path);

        if (!$this->registry->isRegistered($provider)) {
            return $this->jsonError($response, $this->__('build.provider_not_configured_registered', [
                '{provider}' => $provider,
                '{registered}' => implode(', ', $this->registry->getRegisteredNames()),
            ]), 400);
        }

        $p = $this->registry->create($provider);
        $perPage = (int) ($request->getQueryParams()['per_page'] ?? 0);
        $data = $p->getPipelines($projectId, $perPage > 0 ? $perPage : 20);
        $data = $this->attachPullTags($path, $data);
        $format = $request->getQueryParams()['format'] ?? 'raw';

        $listFormat = $request->getQueryParams()['list'] ?? '';
        if (in_array($listFormat, ['id', 'build', 'time', 'success'], true)) {
            $filtered = $data;
            if (in_array($listFormat, ['success', 'build', 'time'], true)) {
                $filtered = array_values(array_filter($data, fn($p) => ($p['status'] ?? '') === 'success'));
            }
            $iidKey = 'iid';
            $idx = fn($p) => $p[$iidKey] ?? $p['id'] ?? 0;
            $result = match ($listFormat) {
                'id'      => array_map($idx, $filtered),
                'success' => array_map($idx, $filtered),
                'build'   => array_map(fn($p) => '#' . $idx($p), $filtered),
                'time'    => array_map(fn($p) => '#' . $idx($p) . ' [' . ($p['created_at'] ?? '') . ']', $filtered),
            };
            return $this->output($response, $result, $request);
        }

        if ($format === 'raw') {
            return $this->output($response, $data, $request);
        }

        return $this->output($response, [
            'build_provider' => $provider,
            'project_id'     => $projectId,
            'pipelines'      => $data,
        ], $request);
    }

    /**
     * GET /api/build/{path}/pipelines/{id}
     *
     * @param array<string,string> $args
     */
    public function pipelineDetail(Request $request, Response $response, array $args): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_BUILD_RECORDS_PULL)) {
            return $resp;
        }

        $path = $args['path'] ?? '';
        $pipelineId = (int) ($args['id'] ?? 0);
        [$provider, $projectId] = $this->resolve($path);

        if (!$this->registry->isRegistered($provider)) {
            return $this->jsonError($response, $this->__('build.provider_not_configured', ['{provider}' => $provider]), 400);
        }

        $p  = $this->registry->create($provider);
        $jobs = $p->getJobs($projectId, $pipelineId);

        foreach ($jobs as $i => $j) {
            $jobId = (int) ($j['id'] ?? 0);
            $jobs[$i]['log_url'] = $jobId > 0 ? "/api/build/{$path}/logs/{$jobId}" : null;
        }

        if (($request->getQueryParams()['format'] ?? '') === 'raw') {
            $statuses = array_map(fn($j) => $j['status'] ?? 'unknown', $jobs);
            return $this->output($response, $statuses, $request);
        }

        return $this->output($response, [
            'build_provider' => $provider,
            'project_id'     => $projectId,
            'pipeline_id'    => $pipelineId,
            'jobs'           => $jobs,
        ], $request);
    }

    /**
     * GET /api/build/{path}/logs/{id} — 统一日志入口（Jenkins/GitLab CI）
     *
     * @param array<string,string> $args
     */
    public function logs(Request $request, Response $response, array $args): Response
    {
        return $this->jobTrace($request, $response, $args);
    }

    /**
     * GET /api/build/{path}/jobs/{id}/trace
     *
     * @param array<string,string> $args
     */
    public function jobTrace(Request $request, Response $response, array $args): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_BUILD_RECORDS_PULL)) {
            return $resp;
        }

        $path  = $args['path'] ?? '';
        $jobId = (int) ($args['id'] ?? 0);
        [$provider, $projectId] = $this->resolve($path);

        if (!$this->registry->isRegistered($provider)) {
            return $this->jsonError($response, $this->__('build.provider_not_configured', ['{provider}' => $provider]), 400);
        }

        $p     = $this->registry->create($provider);
        $trace = $p->getJobTrace($projectId, $jobId);
        return $this->output($response, $trace, $request, true);
    }

    /**
     * GET /api/build/{path}/pipelines/{id}/logs — 输入 run id 直接返回该 run 下全部 job 日志（多 job 拼接）
     *
     * @param array<string,string> $args
     */
    public function pipelineLogs(Request $request, Response $response, array $args): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_BUILD_RECORDS_PULL)) {
            return $resp;
        }

        $path       = $args['path'] ?? '';
        $pipelineId = (int) ($args['id'] ?? 0);
        [$provider, $projectId] = $this->resolve($path);

        if (!$this->registry->isRegistered($provider)) {
            return $this->jsonError($response, $this->__('build.provider_not_configured', ['{provider}' => $provider]), 400);
        }

        $p    = $this->registry->create($provider);
        $jobs = $p->getJobs($projectId, $pipelineId);

        $chunks = [];
        foreach ($jobs as $j) {
            $jobId = (int) ($j['id'] ?? 0);
            if ($jobId <= 0) {
                continue;
            }
            $name     = $j['name'] ?? ('job ' . $jobId);
            $trace    = $p->getJobTrace($projectId, $jobId);
            $chunks[] = "===== Job #{$jobId} {$name} =====\n" . $trace;
        }

        $text = $chunks ? implode("\n\n", $chunks) : '日志不可用';
        return $this->output($response, $text, $request, true);
    }

    /**
     * GET /api/build/{path}/variables — raw: 参数名数组, json/xml: 完整元数据
     *
     * @param array<string,string> $args
     */
    public function variables(Request $request, Response $response, array $args): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_BUILD_RECORDS_PULL)) {
            return $resp;
        }

        $path = $args['path'] ?? '';
        [$provider, $projectId] = $this->resolve($path);

        if (!$this->registry->isRegistered($provider)) {
            return $this->jsonError($response, $this->__('build.provider_not_configured', ['{provider}' => $provider]), 400);
        }

        $p    = $this->registry->create($provider);
        $vars = $p->getVariables($projectId);
        $format = $request->getQueryParams()['format'] ?? 'raw';

        if ($format === 'raw') {
            $names = array_column($vars, 'key');
            return $this->output($response, $names, $request);
        }

        return $this->output($response, [
            'build_provider' => $provider,
            'project_id'     => $projectId,
            'variables'      => $vars,
        ], $request);
    }

    /**
     * GET /api/build/{path}/branches — raw: 分支名数组, json/xml: 完整元数据
     *
     * @param array<string,string> $args
     */
    public function branches(Request $request, Response $response, array $args): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_BUILD_RECORDS_PULL)) {
            return $resp;
        }

        $path = $args['path'] ?? '';
        [$provider, $projectId] = $this->resolve($path);

        if (!$this->registry->isRegistered($provider)) {
            return $this->jsonError($response, $this->__('build.provider_not_configured', ['{provider}' => $provider]), 400);
        }

        $p        = $this->registry->create($provider);
        $branches = $p->getBranches($projectId);
        $format   = $request->getQueryParams()['format'] ?? 'raw';

        if ($format === 'raw') {
            return $this->output($response, $branches, $request);
        }

        return $this->output($response, [
            'build_provider' => $provider,
            'project_id'     => $projectId,
            'branches'       => $branches,
        ], $request);
    }

    /**
     * 拉取式记录镜像 Tag 列的数据源投影：仅回写审计成功（writeback_status=success）的 sha 才投影 tag。
     *
     * @param array<int, array<string,mixed>> $pipelines
     * @return array<int, array<string,mixed>>
     */
    private function attachPullTags(string $path, array $pipelines): array
    {
        if (empty($pipelines)) {
            return $pipelines;
        }
        try {
            $aliases = [$path];
            $registryRepo = '';
            foreach ($this->mapping->allMaps() as $m) {
                $job = $m['job_name'] ?? '';
                $cp  = $m['current_path'] ?? '';
                $matched = ($job === $path || $cp === $path);
                if ($matched && $registryRepo === '' && !empty($m['registry_repository'])) {
                    $registryRepo = (string) $m['registry_repository'];
                }
                if ($matched && ($job !== '' || $cp !== '')) {
                    foreach (['job_name', 'current_path'] as $f) {
                        $v = $m[$f] ?? '';
                        if ($v !== '' && $v !== $path && !in_array($v, $aliases, true)) {
                            $aliases[] = $v;
                        }
                    }
                }
            }

            $tagBySha = [];
            $tagSourceBySha = [];
            foreach ($this->securityChecks->tagsByShaForProjects($aliases) as $sha => $tag) {
                $tagBySha[$sha] = $tag;
                $tagSourceBySha[$sha] = 'scan';
            }

            $shas = array_values(array_unique(array_filter(
                array_map(fn($p) => (string) ($p['sha'] ?? ''), $pipelines),
                fn($s) => $s !== ''
            )));
            if ($shas) {
                foreach ($this->buildTagCache->tagsBySha($shas) as $sha => $tag) {
                    if (!isset($tagBySha[$sha])) {
                        $tagBySha[$sha] = $tag;
                        $tagSourceBySha[$sha] = 'log';
                    }
                }
            }

            foreach ($pipelines as $i => $p) {
                $sha = (string) ($p['sha'] ?? '');
                $tag = $sha !== '' && isset($tagBySha[$sha]) ? $tagBySha[$sha] : '';
                $pipelines[$i]['tag'] = $tag;
                $pipelines[$i]['tag_source'] = $sha !== '' && isset($tagBySha[$sha]) ? ($tagSourceBySha[$sha] ?? '') : '';
                $pipelines[$i]['can_resolve_tag'] = $tag === ''
                    && (($p['status'] ?? '') === 'success')
                    && $registryRepo !== '';
            }
            return $pipelines;
        } catch (\Throwable $e) {
            \App\Helper\Log::exception($e);
            return $pipelines;
        }
    }
}
