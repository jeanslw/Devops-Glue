<?php

declare(strict_types=1);

namespace App\Controller\Build;

use App\Config\AppConfig;
use App\Service\AppSettingRepository;
use App\Service\Build\BuildProviderRegistry;
use App\Service\BuildTagCacheRepository;
use App\Service\I18nService;
use App\Service\MappingManager;
use App\Service\OperationLogRepository;
use App\Service\PipelineArtifactService;
use App\Service\PipelineTagService;
use App\Service\Settings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * pipeline 到镜像 tag 的查询与日志兜底懒解析。
 */
final class PipelineTagController extends BaseBuildController
{
    public function __construct(
        I18nService $i18n,
        Settings $config,
        MappingManager $mapping,
        OperationLogRepository $operationLog,
        private BuildProviderRegistry $registry,
        private AppSettingRepository $appSettings,
        private PipelineTagService $pipelineTags,
        private PipelineArtifactService $artifacts,
        private BuildTagCacheRepository $buildTagCache
    ) {
        parent::__construct($i18n, $config, $mapping, $operationLog);
    }

    /**
     * GET /api/build/{path}/tag?pipeline=10 — 查 pipeline 对应的 tag
     *
     * @param array<string,string> $args
     */
    public function tagQuery(Request $request, Response $response, array $args): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_BUILD_RECORDS_PULL)) {
            return $resp;
        }

        $path     = $args['path'] ?? '';
        $pipeline = $request->getQueryParams()['pipeline'] ?? '';

        $tags  = $this->loadPipelineTags();
        $entry = $tags[$path] ?? [];

        foreach ($this->mapping->allMaps() as $m) {
            $job = $m['job_name'] ?? '';
            $cp  = $m['current_path'] ?? '';
            if ($job === $path || $cp === $path) {
                foreach (['job_name', 'current_path'] as $f) {
                    $alias = $m[$f] ?? '';
                    if ($alias !== '' && $alias !== $path) {
                        $entry = ($tags[$alias] ?? []) + $entry;
                    }
                }
            }
        }
        $tagInfo = $pipeline ? ($entry[$pipeline] ?? null) : null;
        $tag     = is_array($tagInfo) ? ($tagInfo['tag'] ?? '') : $tagInfo;
        $repo    = is_array($tagInfo) ? ($tagInfo['registry'] ?? '') : '';
        $status  = is_array($tagInfo) ? ($tagInfo['status'] ?? '') : '';

        if ($pipeline && !$tag) {
            if (($request->getQueryParams()['format'] ?? 'raw') === 'raw') {
                return $this->output($response, [], $request);
            }

            $resolved = $this->mapping->resolveProject($path);
            return $this->output($response, [
                'build_provider' => $resolved['provider'],
                'project_id'     => $resolved['projectId'],
                'pipeline'       => $pipeline,
                'tag'            => null,
                'all'            => null,
            ], $request);
        }

        if ($pipeline && ($request->getQueryParams()['format'] ?? 'raw') === 'raw') {
            return $this->output($response, $repo ? [$repo . ':' . $tag] : [$tag], $request);
        }

        [$provider, $projectId] = $this->resolve($path);
        return $this->output($response, [
            'build_provider' => $provider,
            'project_id'     => $projectId,
            'pipeline'       => $pipeline ?: null,
            'tag'            => $tag,
            'status'         => $status,
            'registry_repository' => $repo,
            'all'            => $pipeline ? null : $entry,
        ], $request);
    }

    /**
     * 镜像 Tag 的日志兜底懒解析：遍历 jobs 日志提取 tag 并写 ci_build_tag_cache；force=1 跳过缓存重解析。
     *
     * @param array<string,string> $args
     */
    public function resolveTag(Request $request, Response $response, array $args): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_BUILD_RECORDS_PULL)) {
            return $resp;
        }

        $path       = $args['path'] ?? '';
        $pipelineId = (int) ($args['id'] ?? 0);
        $parsedBody = $request->getParsedBody();
        $body       = is_array($parsedBody) ? $parsedBody : [];
        $sha        = trim((string) ($body['sha'] ?? ''));
        $force      = (bool) ($body['force'] ?? false);
        if ($pipelineId <= 0) {
            return $this->jsonError($response, $this->__('build.resolve_tag_missing_id'), 400);
        }

        [$provider, $projectId] = $this->resolve($path);
        if (!$this->registry->isRegistered($provider)) {
            return $this->jsonError($response, $this->__('build.provider_not_configured', ['{provider}' => $provider]), 400);
        }

        $registryRepo = '';
        foreach ($this->mapping->allMaps() as $m) {
            $job = $m['job_name'] ?? '';
            $cp  = $m['current_path'] ?? '';
            if ($job === $path || $cp === $path) {
                if (!empty($m['registry_repository'])) {
                    $registryRepo = trim((string) $m['registry_repository']);
                }
                break;
            }
        }
        if ($registryRepo === '') {
            return $this->output($response, ['tag' => '', 'resolved' => false, 'reason' => 'no_repository'], $request);
        }

        if (!$force && $sha !== '') {
            try {
                $cached = $this->buildTagCache->findTag($sha);
                if ($cached !== null) {
                    return $this->output($response, ['tag' => $cached, 'resolved' => true, 'cached' => true], $request);
                }
            } catch (\Throwable $e) {
                \App\Helper\Log::exception($e);
            }
        }

        $keyword = $this->appSettings->getTagLogKeyword();
        $tag = '';
        try {
            $p = $this->registry->create($provider);
            $jobs = $p->getJobs($projectId, $pipelineId);
            foreach ($jobs as $j) {
                $jobId = (int) ($j['id'] ?? 0);
                if ($jobId <= 0) {
                    continue;
                }
                $trace = (string) $p->getJobTrace($projectId, $jobId);
                $tag = $this->extractTagFromTrace($trace, $registryRepo, $keyword, $pipelineId);
                if ($tag !== '') {
                    break;
                }
            }
        } catch (\Throwable $e) {
            \App\Helper\Log::exception($e);
            $tag = '';
        }

        if ($sha !== '') {
            try {
                $this->buildTagCache->upsert($path, $provider, (string) $projectId, $sha, (string) $pipelineId, $tag, $registryRepo, 'log');
            } catch (\Throwable $e) {
                \App\Helper\Log::exception($e);
            }
        }

        return $this->output($response, ['tag' => $tag, 'resolved' => true, 'cached' => false], $request);
    }

    /**
     * 从单条 job 日志提取镜像 tag：命中推送关键字 + 项目仓库路径后，按 repo:tag 优先、-t/--tag 次之提取。
     */
    private function extractTagFromTrace(string $trace, string $registryRepo, string $keyword, int $pipelineId = 0): string
    {
        $trace      = (string) $trace;
        $registryRepo = trim((string) $registryRepo);
        $keyword    = trim((string) $keyword);
        if ($trace === '' || $registryRepo === '') {
            return '';
        }

        if ($keyword !== '') {
            $keywords = array_values(array_filter(array_map('trim', explode('|', $keyword)), fn($k) => $k !== ''));
            if (!empty($keywords)) {
                $hit = false;
                foreach ($keywords as $kw) {
                    if (stripos($trace, $kw) !== false) {
                        $hit = true;
                        break;
                    }
                }
                if (!$hit) {
                    return '';
                }
            }
        }
        if (stripos($trace, $registryRepo) === false) {
            return '';
        }
        $candidates = [];
        $quoted = preg_quote($registryRepo, '/');
        if (preg_match_all('/' . $quoted . ':([^\s\'"\\\\]+)/i', $trace, $m)) {
            foreach ($m[1] as $raw) {
                $tag = $this->sanitizeTag($raw);
                if ($tag !== '') {
                    $candidates[] = $tag;
                }
            }
        }
        if (preg_match_all('/-(?:-tag\s+|-t\s+)([^\s]+)/i', $trace, $m)) {
            foreach ($m[1] as $raw) {
                $tag = $this->sanitizeTag($raw);
                if ($tag !== '') {
                    $candidates[] = $tag;
                }
            }
        }
        return $this->pickTag($candidates, $pipelineId);
    }

    /**
     * 从候选 tag 中优先选尾号等于当前构建编号的，避免误采上一条构建的 tag；无命中取第一条。
     *
     * @param list<string> $candidates
     */
    private function pickTag(array $candidates, int $pipelineId): string
    {
        if (empty($candidates)) {
            return '';
        }
        if ($pipelineId > 0) {
            foreach ($candidates as $tag) {
                if (preg_match('/(\d+)$/', $tag, $m) && (int) $m[1] === $pipelineId) {
                    return $tag;
                }
            }
        }
        return $candidates[0];
    }

    private function sanitizeTag(string $raw): string
    {
        $tag = trim($raw, " \t\n\r\0\x0B\"'");
        $tag = rtrim($tag, ".,;)'\"");
        if (($pos = strrpos($tag, ':')) !== false) {
            $tag = substr($tag, $pos + 1);
        }
        if (preg_match('/^[A-Za-z0-9_][A-Za-z0-9._-]{0,127}$/', $tag) !== 1) {
            return '';
        }
        return $tag;
    }

    /**
     * @return array<string, array<string, array<string,mixed>>>
     */
    private function loadPipelineTags(): array
    {
        try {
            if ($this->appSettings->getStaleTagCleanupEnabled()) {
                $this->pipelineTags->cleanupStaleTags();
            }

            return $this->artifacts->tagIndex();
        } catch (\Exception $e) {
            return [];
        }
    }
}
