<?php
namespace App\Service;

use App\Service\Git\GitProviderFactory;

class GitService
{
    private MapService $mapService;
    private array $gitSettings;

    public function __construct(MapService $mapService, array $gitSettings)
    {
        $this->mapService = $mapService;
        $this->gitSettings = $gitSettings;
    }

    // src/Service/GitService.php 的 getBranchesForJob 方法
    public function getBranchesForJob(string $jobPath): array
    {
        try {
            $map = $this->mapService->getByJobName($jobPath);
            if (!$map) {
                // 日志记录，但返回空数组，避免接口报错
                error_log("Git mapping not found for job: $jobPath");
                return [];
            }
            $platform = $map['git_platform'];
            $provider = GitProviderFactory::create($platform, $this->gitSettings);
            $repo = $this->parseRepositoryPath($map['remote'], $platform);
            return $provider->getBranches($repo);
        } catch (\Exception $e) {
            error_log("Failed to get branches for $jobPath: " . $e->getMessage());
            return [];
        }
    }

    private function parseRepositoryPath(string $remoteUrl, string $platform): string
    {
        // 从远程 URL 提取 owner/repo 或 group/project
        // 例如: git@gitlab.example.com:group/subgroup/project.git => group/subgroup/project
        //       https://gitee.com/owner/repo.git => owner/repo
        $path = '';
        if (preg_match('#[:/]([^/]+/[^/]+?)(\.git)?$#', $remoteUrl, $matches)) {
            $path = $matches[1];
        }
        if ($platform === 'gitlab') {
            // GitLab API v4 要求 URL 编码项目路径
            return urlencode($path);
        }
        return $path; // Gitee 直接用 owner/repo
    }
}