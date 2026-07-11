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
        ProviderRegistry $registry
    ) {
        $this->remoteResolver = $remoteResolver;
        $this->registry = $registry;
    }

    public function setLogger(Logger $logger): void
    {
        $this->logger = $logger;
    }

    public function getBranchesForJob(string $jobPath): array
    {
        $map = $this->remoteResolver->getByJobName($jobPath);
        if (!$map) {
            $this->logger?->warning("Git mapping not found for job", ['job' => $jobPath]);
            return [];
        }

        $platform       = $map['git_platform'];
        $platformSource = $map['platform_source'] ?? 'auto';
        $detectionMethod = $map['detection_method'] ?? 'exact';

        // 平台未注册：区分"手动指定但未配置"和"自动检测失败"
        if ($platform === 'unknown' || !$this->registry->isRegistered($platform)) {
            if ($platformSource === 'manual') {
                // 用户在 job_git_map 中明确指定了 git_platform，但系统未配置该平台
                $registered = implode(', ', $this->registry->getRegisteredNames());
                throw new \RuntimeException(
                    "Job '{$jobPath}' 在 job_git_map 中指定了 git_platform='{$platform}'，" .
                    "但该平台尚未配置（已配置的平台: {$registered}）。" .
                    "请在 .env 中设置 " . strtoupper($platform) . "_BASE_URL 和 " . strtoupper($platform) . "_TOKEN。"
                );
            }
            // 自动检测失败：无法从 URL 识别平台且 default_platform 也未注册
            $this->logger?->warning("Git 平台未注册或无法识别", [
                'job'      => $jobPath,
                'platform' => $platform,
            ]);
            return [];
        }

        $provider = $this->registry->create($platform);
        $repo = $this->parseRepositoryPath($map['git_remote'] ?? '', $platform);

        try {
            return $provider->getBranches($repo);
        } catch (GuzzleException $e) {
            $this->logger?->error("{$platform} 分支查询失败", [
                'job'              => $jobPath,
                'repository'       => $repo,
                'detection_method' => $detectionMethod,
                'error'            => $e->getMessage(),
            ]);

            $message = "{$platform} 分支查询失败: " . $e->getMessage();

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

    private function parseRepositoryPath(string $remoteUrl, string $platform): string
    {
        if (preg_match('#[:/]([^/]+/[^/]+?)(\.git)?$#', $remoteUrl, $matches)) {
            $path = $matches[1];
            return $platform === 'gitlab' ? urlencode($path) : $path;
        }
        return '';
    }
}
