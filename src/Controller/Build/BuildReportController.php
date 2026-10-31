<?php

declare(strict_types=1);

namespace App\Controller\Build;

use App\Config\AppConfig;
use App\Service\Build\BuildProviderRegistry;
use App\Service\Git\ProviderRegistry as GitProviderRegistry;
use App\Service\I18nService;
use App\Service\MappingManager;
use App\Service\OperationLogRepository;
use App\Service\PipelineArtifactService;
use App\Service\PipelineIdentity;
use App\Service\Registry\RegistryProviderInterface;
use App\Service\SecurityCheckRepository;
use App\Service\Settings;
use App\Service\TransactionManager;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * 构建结果上报：commit status 安全扫描回写、custom_push 终态上报。
 */
final class BuildReportController extends BaseBuildController
{
    public function __construct(
        I18nService $i18n,
        Settings $config,
        MappingManager $mapping,
        OperationLogRepository $operationLog,
        private BuildProviderRegistry $registry,
        private GitProviderRegistry $gitRegistry,
        private ?RegistryProviderInterface $registryProvider,
        private PipelineArtifactService $artifacts,
        private SecurityCheckRepository $securityChecks,
        private TransactionManager $tx
    ) {
        parent::__construct($i18n, $config, $mapping, $operationLog);
    }

    /**
     * 通用安全扫描回写端点：把扫描结果以 commit status 形式回写 Git 平台。
     *
     * @param array<string,string> $args
     */
    public function commitStatus(Request $request, Response $response, array $args): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_TRIGGER)) {
            return $resp;
        }

        $path = $args['path'] ?? '';
        $parsedBody = $request->getParsedBody();
        $body       = is_array($parsedBody) ? $parsedBody : [];


        $sha         = trim($body['sha'] ?? '');
        $state       = trim($body['state'] ?? '');
        $context     = trim($body['context'] ?? '');
        $description = trim($body['description'] ?? '');
        $targetUrl   = trim($body['target_url'] ?? '');
        $checkType   = trim($body['check_type'] ?? $context);
        $tag         = trim($body['tag'] ?? '');

        if (empty($sha) || empty($state) || empty($context) || empty($description)) {
            return $this->jsonError($response, $this->__('build.commit_status_missing_fields'), 400);
        }

        $validStates = ['pending', 'success', 'failed', 'error'];
        if (!in_array($state, $validStates, true)) {
            return $this->jsonError($response, $this->__('build.commit_status_invalid_state', ['states' => implode(', ', $validStates)]), 400);
        }

        if (!preg_match('/^[0-9a-fA-F]{7,64}$/', $sha)) {
            return $this->jsonError($response, 'build.commit_status_invalid_sha', 400);
        }

        $row            = $this->mapping->resolveMapRow($path) ?? [];
        $gitPlatform    = (string) ($row['git_platform'] ?? '');
        $gitProjectId   = $row['project_id'] ?? null;
        $gitCurrentPath = (string) ($row['current_path'] ?? '');

        if (empty($gitPlatform)) {
            return $this->jsonError($response, $this->__('build.commit_status_no_git_platform', ['path' => $path]), 400);
        }

        if (!$this->gitRegistry->isRegistered($gitPlatform)) {
            return $this->jsonError($response, $this->__('build.git_platform_not_available', ['platform' => $gitPlatform]), 400);
        }

        try {
            $gitProvider = $this->gitRegistry->create($gitPlatform);
            $gitRepo = $gitProjectId ? (string) $gitProjectId
                : (!empty($gitCurrentPath) ? $gitCurrentPath : $path);
            $result = $gitProvider->setCommitStatus($gitRepo, $sha, $state, $context, $description, $targetUrl);
        } catch (\Exception $e) {
            if ($e instanceof \RuntimeException) {
                throw $e;
            }
            $result = ['success' => false, 'message' => $e->getMessage()];
        }

        $writebackStatus = $result['success'] ? 'success' : 'failed';
        $this->securityChecks->record($path, $sha, $state, $context, $description, $checkType, $tag, $writebackStatus, $result['message']);

        return $this->output($response, [
            'project'       => $path,
            'sha'           => $sha,
            'state'         => $state,
            'context'       => $context,
            'description'   => $description,
            'check_type'    => $checkType,
            'git_platform'  => $gitPlatform,
            'commit_status' => $result,
        ], $request);
    }

    /**
     * 自定义推送式 CI（custom_push）一次性上报构建终态结果。
     *
     * @param array<string,string> $args
     */
    public function report(Request $request, Response $response, array $args): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_TRIGGER)) {
            return $resp;
        }

        $path        = $args['path'] ?? '';
        $parsedBody  = $request->getParsedBody();
        $body        = is_array($parsedBody) ? $parsedBody : [];
        $pipelineIid = (int) ($body['pipeline_iid'] ?? 0);
        $status      = trim((string) ($body['status'] ?? ''));
        $finishedAt  = trim((string) ($body['finished_at'] ?? ''));
        $tag         = trim((string) ($body['tag'] ?? ''));
        $sha         = trim((string) ($body['sha'] ?? ''));

        if ($pipelineIid <= 0) {
            return $this->jsonError($response, 'build.report_missing_pipeline_iid', 400);
        }
        if ($status === '') {
            return $this->jsonError($response, 'build.report_missing_status', 400);
        }
        if (!in_array($status, ['success', 'failed', 'aborted'], true)) {
            return $this->jsonError($response, 'build.report_invalid_status', 400);
        }
        if ($finishedAt === '') {
            return $this->jsonError($response, 'build.report_missing_finished_at', 400);
        }
        if ($status === 'success' && $tag === '') {
            return $this->jsonError($response, 'build.report_success_requires_tag', 400);
        }

        [$provider, $projectId] = $this->resolve($path);
        if (!$this->registry->isRegistered($provider)) {
            return $this->jsonError($response, $this->__('build.provider_not_configured', ['{provider}' => $provider]), 400);
        }

        $p = $this->registry->create($provider);
        if (!$p instanceof \App\Service\Build\CustomPushBuildProvider) {
            return $this->jsonError($response, 'build.report_only_custom_push', 400);
        }

        $registryRepo = '';
        if ($status === 'success') {
            $row = $this->mapping->resolveMapRow($path);
            if ($row === null) {
                return $this->jsonError($response, 'build.report_success_missing_mapping', 400);
            }
            $registryRepo = trim((string) ($row['registry_repository'] ?? ''));
            if ($registryRepo === '') {
                return $this->jsonError($response, 'build.report_success_missing_registry_repo', 400);
            }
            if (!$this->isValidRegistryRepo($registryRepo)) {
                return $this->jsonError($response, 'build.report_invalid_registry_repo', 400);
            }
            if (($verifyErr = $this->verifyRegistryTag($registryRepo, $tag)) !== null) {
                return $this->jsonError($response, $verifyErr, 400);
            }
        }

        $txStarted = false;
        if ($status === 'success' && !$this->tx->inTransaction()) {
            $this->tx->begin();
            $txStarted = true;
        }
        try {
            $result = $p->report($projectId, $body);

            if (!empty($result['success']) && $status === 'success' && ($result['action'] ?? '') !== 'ignored_stale') {
                if (
                    !$this->recordPipelineTag(
                        $projectId,
                        $this->mapping->pipelineIdentity($path, $pipelineIid),
                        $tag,
                        $registryRepo,
                        'success',
                        $finishedAt
                    )
                ) {
                    if ($txStarted) {
                        $this->tx->rollBack();
                    }
                    $this->recordReportAudit($path, $sha, $status, $tag, ['success' => false, 'message' => $this->__('build.report_artifact_db_write_failed')]);
                    return $this->jsonError($response, 'build.report_artifact_write_failed', 500);
                }
            }

            if ($txStarted) {
                $this->tx->commit();
            }
        } catch (\Throwable $e) {
            if ($txStarted) {
                $this->tx->rollBack();
            }
            \App\Helper\Log::exception($e);
            $this->recordReportAudit($path, $sha, $status, $tag, ['success' => false, 'message' => $e->getMessage()]);
            return $this->jsonError($response, 'build.report_write_failed', 500);
        }

        $this->recordReportAudit($path, $sha, $status, $tag, $result);

        return $this->output($response, [
            'build_provider' => $provider,
            'pipeline_iid'   => $pipelineIid,
        ] + $result, $request);
    }

    private function isValidRegistryRepo(string $repo): bool
    {
        return (bool) preg_match('#^[A-Za-z0-9][A-Za-z0-9._-]*(/[A-Za-z0-9][A-Za-z0-9._-]*)+$#', $repo);
    }

    /**
     * 校验镜像仓库与 tag 真实存在：null 表示命中，否则返回错误消息（分两步：先仓库后 tag）。
     */
    private function verifyRegistryTag(string $repo, string $tag): ?string
    {
        if (!$this->registryProvider) {
            return '镜像仓库未配置，无法校验仓库与 tag 的真实性';
        }
        if (!$this->isValidRegistryRepo($repo)) {
            return 'registry_repository 格式错误（应为至少两段的 OCI 仓库名）';
        }

        try {
            if (!$this->registryProvider->repositoryExists($repo)) {
                return '镜像仓库不存在：' . $repo . '（请核对 job_git_map 的 registry_repository）';
            }
            $tags = $this->registryProvider->getTags($repo);
        } catch (\Throwable $e) {
            return '镜像仓库不可达（' . $e->getMessage() . '），无法校验仓库与 tag';
        }

        if (isset($tags['error'])) {
            return '查询镜像仓库 tag 失败：' . $this->registryErrorMessage($tags) . '（仓库 ' . $repo . '）';
        }
        if (!in_array($tag, $tags, true)) {
            return '镜像仓库 ' . $repo . ' 中不存在 tag「' . $tag . '」：该 tag 的镜像尚未推送，或 tag 名拼写有误';
        }
        return null;
    }

    /**
     * 把仓库提供方错误数组翻译成对用户明确的错误描述（据 http_code 区分不可达/认证失败/资源不存在）。
     *
     * @param array{http_code?: int, error?: string} $err
     */
    private function registryErrorMessage(array $err): string
    {
        $httpCode = $err['http_code'] ?? 0;
        $msg      = $err['error'] ?? '未知错误';
        if ($httpCode === 0) {
            if (str_contains($msg, '不存在')) {
                return $msg;
            }
            return '镜像仓库不可达（' . $msg . '）';
        }
        if ($httpCode === 404) {
            return '资源不存在（HTTP 404）';
        }
        if ($httpCode === 401 || $httpCode === 403) {
            return '镜像仓库认证失败（HTTP ' . $httpCode . '），请检查后台「平台连接配置」里镜像仓库账号的权限';
        }
        if ($httpCode >= 500) {
            return '镜像仓库服务端异常（HTTP ' . $httpCode . '）';
        }
        return '镜像仓库返回 HTTP ' . $httpCode . '（' . $msg . '）';
    }

    private function recordPipelineTag(
        string $projectKey,
        PipelineIdentity $identity,
        string $tag,
        string $registryRepo = '',
        string $status = '',
        ?string $sourceUpdatedAt = null,
        ?string $createdAt = null
    ): bool {
        if (empty($projectKey) || $identity->pipelineIid <= 0 || empty($tag) || empty($registryRepo)) {
            return false;
        }
        if (mb_strlen($tag) > 255 || mb_strlen($projectKey) > 255 || mb_strlen($identity->projectId) > 255) {
            return false;
        }

        try {
            $artifact = $this->artifacts->record(
                $identity,
                $projectKey,
                $tag,
                $registryRepo,
                $status,
                $sourceUpdatedAt,
                $createdAt
            );
            if (!$artifact['written'] && !$artifact['ignored']) {
                return false;
            }
            return true;
        } catch (\Throwable $e) {
            \App\Helper\Log::exception($e);
            return false;
        }
    }

    /**
     * 把 custom_push 构建终态（成功 / 失败 / 中止）写入 ci_security_checks 审计表，
     * 与 commitStatus / registry-sync 等端点保持一致的审计覆盖。
     *
     * @param array<string,mixed> $result
     */
    private function recordReportAudit(string $path, string $sha, string $status, string $tag, array $result): void
    {
        $description = 'custom_push 构建终态: ' . $status . ($tag !== '' ? ' → ' . $tag : '');
        // 审计 state 归一到 failed：审计页 state 过滤只有 success/failed/pending/error，
        // 中止（aborted）视作非成功归入 failed；原始中止语义保留在 description，且与 writeback_status 的失败口径一致。
        $auditState = $status === 'aborted' ? 'failed' : $status;
        $this->securityChecks->record(
            $path,
            $sha,
            $auditState,
            'custom_push',
            $description,
            'custom_push',
            $tag,
            !empty($result['success']) ? 'success' : 'failed',
            (string) ($result['message'] ?? '')
        );
    }
}
