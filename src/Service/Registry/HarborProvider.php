<?php

namespace App\Service\Registry;

use App\Service\HarborService;

/**
 * Harbor 提供方：把 Harbor REST API 适配到 RegistryProviderInterface。
 *
 * 仓库标识为 project/repo 两段式，内部按 Harbor 的项目/仓库两级拆分。
 * 项目与仓库列表直接透传 Harbor 的 /projects、/projects/{project}/repositories。
 */
class HarborProvider implements RegistryProviderInterface
{
    public function __construct(private HarborService $harbor)
    {
    }

    public function type(): string
    {
        return 'harbor';
    }

    public function supportsScanning(): bool
    {
        return true;
    }

    public function getTags(string $repository): array
    {
        [$project, $repoName] = $this->split($repository);
        return $this->harbor->getTags($project, $repoName);
    }

    public function repositoryExists(string $repository): bool
    {
        [$project, $repoName] = $this->split($repository);
        $repos = $this->harbor->getRepositories($project);
        if (isset($repos['error'])) {
            throw new \RuntimeException('查询 Harbor 项目仓库失败：' . (string) $repos['error']);
        }

        foreach ($repos as $name) {
            if (is_string($name) && ($name === $repoName || $name === $repository)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array<int|string,mixed>
     */
    public function getProjects(): array
    {
        return $this->harbor->getProjects();
    }

    /**
     * @return array<int|string,mixed>
     */
    public function getRepositories(string $project): array
    {
        return $this->harbor->getRepositories($project);
    }

    public function getScanReport(string $repository, string $tag): array
    {
        [$project, $repoName] = $this->split($repository);
        return $this->harbor->getScanReport($project, $repoName, $tag);
    }

    /**
     * @return array{0:string,1:string}
     */
    private function split(string $repository): array
    {
        $parts = explode('/', $repository, 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new \InvalidArgumentException('Harbor 仓库标识必须是 project/repo 两段式：' . $repository);
        }
        return [$parts[0], $parts[1]];
    }
}
