<?php

namespace App\Service;

use App\Service\Git\ProviderRegistry;
use GuzzleHttp\Exception\GuzzleException;

class GitService
{
    private GitRemoteResolver $remoteResolver;
    private ProviderRegistry $registry;
    private ?Logger $logger = null;

    public function __construct(
        GitRemoteResolver $remoteResolver,
        ProviderRegistry $registry,
        ?Logger $logger = null
    ) {
        $this->remoteResolver = $remoteResolver;
        $this->registry = $registry;
        $this->logger = $logger;
    }

    /**
     * Job 绑定仓库的分支列表。
     *
     * @return list<string>
     */
    public function getBranchesForJob(string $jobPath): array
    {
        return $this->fetchListForJob($jobPath, 'branches');
    }

    /**
     * Job 绑定仓库的 tag 列表（CD 构建页可选 tag 作为构建 ref）。
     *
     * 语义与 getBranchesForJob 完全对称：无映射 / 平台无法识别 → 空数组；
     * 平台连接级失败 → 上抛 RuntimeException（不吞成空数组，否则与「仓库确实没有 tag」无法区分）。
     *
     * @return list<string>
     */
    public function getTagsForJob(string $jobPath): array
    {
        return $this->fetchListForJob($jobPath, 'tags');
    }

    /**
     * 一次解析 job 映射，同时取回 branches + tags。
     *
     * 供 CD 构建页 ref 下拉使用：一个请求拿全候选值，省掉一次 job→仓库映射解析与一次平台客户端构造，
     * 且两组值必然来自同一个仓库快照（不会出现分支取 A 仓库、tag 取 B 仓库的错配）。
     * 任一类型查询失败即整体上抛：下拉需要的是完整候选集，只回一半会让用户误判「这个仓库没有 tag」。
     *
     * @return array{branches: list<string>, tags: list<string>}
     */
    public function getRefsForJob(string $jobPath): array
    {
        $map = $this->resolveJobMap($jobPath);
        if ($map === null) {
            return ['branches' => [], 'tags' => []];
        }

        return [
            'branches' => $this->fetchListFromMap($jobPath, $map, 'branches'),
            'tags'     => $this->fetchListFromMap($jobPath, $map, 'tags'),
        ];
    }

    /**
     * @param 'branches'|'tags' $kind 列表类型
     * @return list<string>
     */
    private function fetchListForJob(string $jobPath, string $kind): array
    {
        $map = $this->resolveJobMap($jobPath);
        if ($map === null) {
            return [];
        }

        return $this->fetchListFromMap($jobPath, $map, $kind);
    }

    /**
     * 解析 Job 的 Git 映射，并判定平台是否可用。
     *
     * 返回 null = 调用方按空集处理（无映射记录，或自动识别出的平台未注册）；
     * 抛 RuntimeException = 配置错误必须让用户看见（job_git_map 手动指定了未配置的平台）。
     *
     * @return array<string,mixed>|null
     */
    private function resolveJobMap(string $jobPath): ?array
    {
        $map = $this->remoteResolver->getByJobName($jobPath);
        if (!$map) {
            $this->logger?->warning("Git mapping not found for job", ['job' => $jobPath]);
            return null;
        }

        $platform       = $map['git_platform'];
        $platformSource = $map['platform_source'] ?? 'auto';

        // 平台未注册：区分"手动指定但未配置"和"自动检测失败"
        if ($platform === 'unknown' || !$this->registry->isRegistered($platform)) {
            if ($platformSource === 'manual') {
                // 用户在 job_git_map 中明确指定了 git_platform，但系统未配置该平台
                $registered = implode(', ', $this->registry->getRegisteredNames());
                throw new \RuntimeException(
                    "Job '{$jobPath}' 在 job_git_map 中指定了 git_platform='{$platform}'，" .
                    "但该平台尚未配置（已配置的平台: {$registered}）。" .
                    "请在后台「平台接入状态」里配置该平台的连接（URL 与 Token）。"
                );
            }
            // 自动检测失败：无法从 URL 识别平台且 default_platform 也未注册
            $this->logger?->warning("Git 平台未注册或无法识别", [
                'job'      => $jobPath,
                'platform' => $platform,
            ]);
            return null;
        }

        return $map;
    }

    /**
     * 按已确认可用的映射查询列表：branches / tags 共用平台客户端构造与错误处理，只差一个 provider 方法。
     *
     * @param array<string,mixed> $map  GitRemoteResolver 解析出的映射
     * @param 'branches'|'tags'   $kind 列表类型
     * @return list<string>
     */
    private function fetchListFromMap(string $jobPath, array $map, string $kind): array
    {
        $platform        = (string) $map['git_platform'];
        $detectionMethod = $map['detection_method'] ?? 'exact';

        $provider = $this->registry->create($platform);
        $repo     = $this->parseRepositoryPath((string) ($map['git_remote'] ?? ''), $platform);

        try {
            $list = $kind === 'tags' ? $provider->getTags($repo) : $provider->getBranches($repo);
            return array_values($list);
        } catch (GuzzleException $e) {
            $failure = $this->failureText($platform, $kind);

            $this->logger?->error($failure, [
                'job'              => $jobPath,
                'repository'       => $repo,
                'detection_method' => $detectionMethod,
                'error'            => $e->getMessage(),
            ]);

            $message = $failure . ': ' . $e->getMessage();

            if ($detectionMethod === 'fallback') {
                $message .= "。提示：此平台由 DEFAULT_GIT_PLATFORM 兜底识别，"
                          . "Git remote URL 中不含任何平台关键词。"
                          . "如果你实际部署的是其他平台（如 Gitea），"
                          . "请在 .env 中修改 DEFAULT_GIT_PLATFORM 为正确的平台名。"
                          . "当前已注册平台: " . implode(', ', $this->registry->getRegisteredNames());
            }

            throw new \RuntimeException($message, $e->getCode(), $e);
        }
    }

    /**
     * 查询失败文案。
     *
     * 分支沿用既有措辞「GitLab 分支查询失败」不变（日志与排障文档按它检索）；
     * tag 是英文词，与平台名之间留一个空格。
     *
     * @param 'branches'|'tags' $kind 列表类型
     */
    private function failureText(string $platform, string $kind): string
    {
        return $kind === 'tags' ? "{$platform} tag 查询失败" : "{$platform} 分支查询失败";
    }

    private function parseRepositoryPath(string $remoteUrl, string $platform): string
    {
        $path = \App\Helper\GitRemote::extractPath($remoteUrl);
        if ($path === null) {
            return '';
        }
        // GitLab 的 projects API 需接受 urlencode 后的 path 作为项目标识（子群组的
        // group/sub/repo 会被整体编码为 group%2Fsub%2Frepo），
        // 故在此编码后再下传 GitlabService::getBranches/getTags（下游不得再编码）。
        return $platform === 'gitlab' ? urlencode($path) : $path;
    }
}
