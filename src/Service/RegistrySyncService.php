<?php

namespace App\Service;

use App\Config\AppConfig;
use App\Helper\Log;
use App\Service\Build\BuildProviderRegistry;
use App\Service\AppSettingRepository;
use App\Service\Git\ProviderRegistry as GitProviderRegistry;
use App\Service\Registry\RegistryProviderInterface;

/**
 * 镜像制品回写服务。
 *
 * 回写是基础能力：tag 校验、artifact 写入、审计记录、commit status 回写。
 * 扫描是可选的附加能力，仅当提供方 supportsScanning() 且调用方要求时执行。
 * registry-sync 与 scan-sync 两个端点共用本服务，不再各自维护一套写路径。
 */
class RegistrySyncService
{
    /** artifact 写入的 ci_status 白名单 */
    private const CI_STATUSES = ['success', 'failed', 'running'];

    public function __construct(
        private I18nService $i18n,
        private MappingManager $mapping,
        private BuildProviderRegistry $providers,
        private PipelineArtifactService $artifacts,
        private ?GitProviderRegistry $gitRegistry,
        private ?RegistryProviderInterface $registry,
        private Settings $config,
        private \PDO $pdo,
        private AppSettingRepository $appSettings
    ) {
    }

    /**
     * 执行一次制品回写。
     *
     * @param array<string,mixed> $body
     * @param bool   $withScan 是否尝试漏洞扫描（仅提供方支持时生效）
     * @param string $checkType 审计类型与 commit status context
     * @return array{
     *     error?:string,
     *     http_status?:int,
     *     build_provider?:string,
     *     sha?:string,
     *     tag?:string,
     *     registry_repository?:string,
     *     vulnerability_count?:int,
     *     scan_state?:string,
     *     commit_status?:array{success:bool,message:string},
     *     tag_verified?:bool,
     *     artifact_written?:bool
     * }
     */
    public function sync(string $path, array $body, bool $withScan, string $checkType): array
    {
        $ciStatus = is_string($body['ci_status'] ?? null) ? trim($body['ci_status']) : '';
        if (!in_array($ciStatus, self::CI_STATUSES, true)) {
            return [
                'error'       => $this->i18n->trans('build.scan_sync_ci_status_required', ['statuses' => implode(', ', self::CI_STATUSES)]),
                'http_status' => 400,
            ];
        }


        $row = $this->mapping->resolveMapRow($path);
        if ($row === null) {
            return $this->repoError($path);
        }
        try {
            $provider = MappingManager::requireProvider($row);
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage(), 'http_status' => 400];
        }

        $repository     = trim((string) ($row['registry_repository'] ?? ''));
        $gitPlatform    = (string) ($row['git_platform'] ?? '');
        $gitProjectId   = $row['project_id'] ?? null;
        $gitCurrentPath = (string) ($row['current_path'] ?? '');
        $ciProjectId    = MappingManager::canonicalProjectId($row, $path, $provider);

        if ($repository === '') {
            return $this->repoError($path);
        }
        if (!$this->isValidRepository($repository)) {
            return [
                'error'       => $this->i18n->trans('build.registry_repo_format_error', ['repo' => $repository]),
                'http_status' => 400,
            ];
        }


        $tag = is_string($body['tag'] ?? null) ? trim($body['tag']) : '';

        $tagVerified = false; // tag 是否已通过镜像仓库实际校验（存在）；仅此情况才写权威 artifact
        $tags = null;
        if ($this->registry !== null) {
            $tags = $this->getCachedTags($repository);
            if ($tags === null) {
                try {
                    $tags = $this->registry->getTags($repository);
                    if (!isset($tags['error'])) {
                        $this->appSettings->setCache($this->tagCacheKey($repository), $tags, 30);
                    }
                } catch (\RuntimeException $e) {
                    Log::exception($e);
                    $tags = ['error' => $e->getMessage()];
                }
            }
        }

        if ($tag === '' && is_array($tags) && !isset($tags['error']) && $tags !== []) {
            $first = reset($tags);
            $tag = is_string($first) ? $first : '';
        }


        if ($this->registry !== null && !isset($tags['error']) && $tag !== '') {
            if (in_array($tag, (array) $tags, true)) {
                $tagVerified = true;
            } else {
                // 仓库已配置但 tag 不存在：仅写审计，不写权威 artifact（与 BuildReportController 的严格校验一致）
                Log::warning('registry-sync tag 未在镜像仓库校验通过，跳过权威 artifact 写入', ['tag' => $tag, 'repo' => $repository]);
            }
        }



        $digest = '';
        foreach (['digest', 'image_digest'] as $dk) {
            if (is_string($body[$dk] ?? null) && trim($body[$dk]) !== '') {
                $digest = trim($body[$dk]);
                break;
            }
        }
        if ($digest === '' && $tagVerified && $this->registry !== null && $tag !== '') {
            $digest = $this->resolveDigestFromRegistry($repository, $tag);
        }

        $bodySha = is_string($body['sha'] ?? null) ? trim($body['sha']) : '';
        $bodyIid = is_scalar($body['pipeline_iid'] ?? null) ? (int) $body['pipeline_iid'] : 0;
        $sourceUpdatedAt = is_string($body['source_updated_at'] ?? null) ? trim($body['source_updated_at']) : '';

        if ($bodySha !== '' || $bodyIid > 0) {
            if ($bodySha === '' || $bodyIid <= 0) {
                return ['error' => $this->i18n->trans('build.scan_sync_identity'), 'http_status' => 400];
            }
            $sha = $bodySha;
            $iid = $bodyIid;
        } else {
            $sha = '';
            $iid = 0;
            try {
                if ($this->providers->isRegistered($provider)) {
                    $pipelines = $this->providers->create($provider)->getPipelines($ciProjectId, 1);
                    if ($pipelines !== []) {
                        $sha = (string) ($pipelines[0]['sha'] ?? '');
                        $iid = (int) ($pipelines[0]['iid'] ?? 0);
                        $sourceUpdatedAt = trim((string) ($pipelines[0]['updated_at'] ?? $pipelines[0]['created_at'] ?? ''));
                    }
                }
            } catch (\Throwable $e) {
                Log::exception($e);
            }
        }




        $scanState = 'unknown';
        $vulnCount = 0;
        $result    = ['success' => false, 'message' => ''];
        $desc      = '';
        $scanning  = $withScan && $this->registry !== null && $this->registry->supportsScanning();

        if ($scanning && $tag !== '') {
            try {
                $scan = $this->registry->getScanReport($repository, $tag);
                if (isset($scan['error']) || isset($scan['code'])) {
                    $scanState = 'pending';
                    $result    = ['success' => false, 'message' => $this->scanErrorMessage($scan)];
                } else {
                    $vulns     = $scan['vulnerabilities'] ?? $scan;
                    $vulnCount = is_array($vulns) ? count($vulns) : 0;
                    $scanState = $vulnCount > 0 ? 'failed' : 'success';
                }
            } catch (\RuntimeException $e) {
                Log::exception($e);
                $scanState = 'pending';
                $result    = ['success' => false, 'message' => $this->i18n->trans('build.registry_unreachable') . ': ' . $e->getMessage()];
            }
        }

        // 审计记录的 state 归一到合法集合：未扫描时按 ci_status 映射，与 commit status 一致。
        $auditState = $scanState !== 'unknown'
            ? $scanState
            : match ($ciStatus) {
                'success' => 'success',
                'failed'  => 'failed',
                default   => 'pending',
            };

        // A-02：配置了镜像仓库但未通过 tag 校验时，CI 虽成功也不应记为绿色 success——
        // 否则未验证的 tag 会写绿色 commit status 并进入 SHA→tag 成功映射。
        $commitState = $auditState;
        if ($this->registry !== null && !$tagVerified && $commitState === 'success') {
            $commitState = 'pending';
        }

        $canWriteBack = $sha !== '' && $gitPlatform !== '' && $this->gitRegistry !== null
            && $this->gitRegistry->isRegistered($gitPlatform);

        if ($canWriteBack) {
            $desc = $this->commitDescription($iid, $tag, $scanning, $scanState, $vulnCount);
            $gitRepo = $gitProjectId ? (string) $gitProjectId
                : ($gitCurrentPath !== '' ? $gitCurrentPath : $path);

            $gitState = $commitState;
            try {
                $result = $this->gitRegistry->create($gitPlatform)
                    ->setCommitStatus($gitRepo, $sha, $gitState, $checkType, $desc, $this->registryUrl());
            } catch (\Throwable $e) {
                Log::exception($e);
                $result = ['success' => false, 'message' => $this->i18n->trans('build.writeback_failed') . ': ' . $e->getMessage()];
            }
        } elseif ($this->registry === null) {
            $desc = $this->i18n->trans('build.registry_not_configured') . ' #' . $iid . ' → ' . $tag;
        } else {
            $desc = '#' . $iid . ' → ' . $tag . ' · commit status 跳过';
        }










        $artifactWritten = false;
        $writebackParts = ['ci_status=' . $ciStatus];
        if ($result['message'] !== '') {
            $writebackParts[] = $result['message'];
        }
        if ($ciStatus === 'success' && $iid > 0) {
            // 仅当 tag 已通过镜像仓库校验（或仓库未配置、无法校验时按原行为尽力关联）才写权威 artifact
            if ($this->registry === null || $tagVerified) {
                $artifactOk = $this->recordArtifact($path, $iid, $tag, $repository, $scanState, $sourceUpdatedAt, $digest);
                $artifactWritten = (bool)$artifactOk;
                $writebackParts[] = $artifactOk ? 'artifact_write=success' : 'artifact_write=failed';
            } else {
                $writebackParts[] = 'artifact_write=skipped (tag_not_in_registry)';
            }
        } elseif ($iid > 0) {
            $writebackParts[] = 'artifact_write=skipped (ci_status=' . $ciStatus . ')';
        }
        $this->recordSecurityCheck(
            $path,
            $sha,
            $commitState,
            $checkType,
            $desc,
            $tag,
            ($this->registry === null || $tagVerified) && $ciStatus === 'success' ? 'success' : 'skipped',
            implode(' | ', $writebackParts),
            (string) $iid
        );

        return [
            'build_provider'      => $provider,
            'sha'                 => $sha,
            'tag'                 => $tag,
            'digest'              => $digest !== '' ? $digest : null,
            'registry_repository' => $repository,
            'vulnerability_count' => $vulnCount,
            'scan_state'          => $scanState,
            'commit_status'       => $result,
            'tag_verified'        => $tagVerified,
            'artifact_written'    => $artifactWritten,
        ];
    }

    /**
     * @return array{error:string, http_status:int}
     */
    private function repoError(string $path): array
    {
        return [
            'error'       => $this->i18n->trans('build.no_registry_repo', ['path' => $path]),
            'http_status' => 400,
        ];
    }

    /**
     * 仓库标识格式：两段及以上的 OCI 名称，禁止 scheme/主机/端口。
     */
    private function isValidRepository(string $repository): bool
    {
        return (bool) preg_match('#^[A-Za-z0-9][A-Za-z0-9._-]*(/[A-Za-z0-9][A-Za-z0-9._-]*)+$#', $repository);
    }

    /** 读缓存的镜像标签列表；未命中返回 null（仅缓存成功列举，不含错误）。
     *
     * @return array<string,mixed>|null
     */
    private function getCachedTags(string $repository): ?array
    {
        return $this->appSettings->getCache($this->tagCacheKey($repository));
    }

    private function recordArtifact(string $path, int $iid, string $tag, string $repository, string $scanState, string $sourceUpdatedAt, string $digest = ''): bool
    {
        if ($tag === '' || $repository === '') {
            return false;
        }
        try {
            $res = $this->artifacts->record(
                $this->mapping->pipelineIdentity($path, $iid),
                $path,
                $tag,
                $repository,
                $scanState,
                $sourceUpdatedAt !== '' ? $sourceUpdatedAt : date('Y-m-d H:i:s'),
                null,
                $digest !== '' ? $digest : null
            );
            return $res['written'] || $res['ignored'];
        } catch (\Throwable $e) {
            Log::exception($e);
            return false;
        }
    }

    /**
     * 从 Registry tag details 解析指定 tag 的 digest；失败返回空串。
     */
    private function resolveDigestFromRegistry(string $repository, string $tag): string
    {
        if ($this->registry === null || $tag === '') {
            return '';
        }
        try {
            $details = $this->registry->getTagDetails($repository);
            if (!is_array($details) || isset($details['error'])) {
                return '';
            }
            foreach ($details as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $name = (string) ($row['name'] ?? $row['tag'] ?? '');
                if ($name !== $tag) {
                    continue;
                }
                $d = trim((string) ($row['digest'] ?? ''));
                if ($d !== '') {
                    return $d;
                }
            }
        } catch (\Throwable $e) {
            Log::exception($e);
        }
        return '';
    }

    private function recordSecurityCheck(string $project, string $sha, string $state, string $checkType, string $description, string $tag, string $writebackStatus, string $writebackMessage, string $pipelineId = ''): void
    {
        try {
            $sql = Database::sqlUpsert(
                AppConfig::TABLE_SECURITY_CHECKS,
                'project, sha, check_type, state, context, description, tag, writeback_status, writeback_message, pipeline_id, created_at',
                '?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ' . Database::sqlNow()
            );
            $this->pdo->prepare($sql)->execute([$project, $sha, $checkType, $state, $checkType, $description, $tag, $writebackStatus, $writebackMessage, $pipelineId]);
        } catch (\Throwable $e) {
            Log::exception($e);
        }
    }

    /**
     * @param array{http_code?:int, error?:string, message?:string} $scan
     */
    private function scanErrorMessage(array $scan): string
    {
        if ((int) ($scan['http_code'] ?? 0) === 412) {
            return $this->i18n->trans('harbor.scan_not_enabled');
        }
        return (string) ($scan['error'] ?? ($scan['message'] ?? $this->i18n->trans('harbor.scan_not_enabled')));
    }

    private function commitDescription(int $iid, string $tag, bool $scanning, string $scanState, int $vulnCount): string
    {
        $head = '#' . $iid . ' → ' . $tag;
        if (!$scanning) {
            return $head . ' · ' . ($tag === '' ? 'tag 未知' : 'artifact 已关联');
        }
        $tail = $vulnCount > 0 ? $vulnCount . ' vulns' : ($scanState === 'pending' ? '扫描未启用' : 'clean');
        return $head . ' · ' . $tail;
    }

    private function registryUrl(): string
    {
        return (string) ($this->config->getHarborConfig()['url'] ?? '');
    }

    /**
     * 镜像标签缓存键：绑定 registry 连接端点（URL），避免不同 registry 同名仓库互相串用缓存（A-03）。
     */
    private function tagCacheKey(string $repository): string
    {
        return 'registry:tags:' . md5($repository . '|' . $this->registryUrl());
    }
}
