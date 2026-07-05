<?php
namespace App\Service;

use App\Service\Git\GitProviderFactory;

class GitService
{
    private MapService $mapService;
    private array $gitlabConfig;
    private array $giteeConfig;
    private array $githubConfig;
    private ?Logger $logger = null;

    public function __construct(
        MapService $mapService,
        array $gitlabConfig = [],
        array $giteeConfig = [],
        array $githubConfig = []
    ) {
        $this->mapService = $mapService;
        $this->gitlabConfig = $gitlabConfig;
        $this->giteeConfig = $giteeConfig;
        $this->githubConfig = $githubConfig;
    }

    public function setLogger(Logger $logger): void
    {
        $this->logger = $logger;
    }

    public function getBranchesForJob(string $jobPath): array
    {
        $map = $this->mapService->getByJobName($jobPath);
        if (!$map) {
            $this->logger?->warning("Git mapping not found for job", ['job' => $jobPath]);
            return [];
        }
        $platform = $map['git_platform'];
        $provider = GitProviderFactory::create($platform, $this->gitlabConfig, $this->giteeConfig, $this->githubConfig);
        $repo = $this->parseRepositoryPath($map['git_remote'] ?? '', $platform);
        return $provider->getBranches($repo);
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