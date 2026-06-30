<?php
namespace App\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Service\JenkinsService;
use App\Service\MapService;
use App\Config\AppConfig;

class MainController
{
    private JenkinsService $jenkins;
    private MapService $map;
    private AppConfig $config;

    public function __construct(JenkinsService $jenkins, MapService $map, AppConfig $config)
    {
        $this->jenkins = $jenkins;
        $this->map = $map;
        $this->config = $config;
    }

    /**
     * 获取所有 Job 列表
     * GET/POST /api/main/jobs/list
     */
    public function jobsList(Request $request, Response $response): Response
    {
        $jobs = $this->jenkins->getAllJobs();
        $response->getBody()->write(json_encode($jobs));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * 获取三方映射关系（按项目分组）
     * GET/POST /api/main/map/list
     * 返回 JSON 对象，键为项目路径
     */
    public function mapList(Request $request, Response $response): Response
    {
        $maps = $this->map->getAllMaps();
        $grouped = [];
        foreach ($maps as $map) {
            // 使用 current_path 作为分组键
            $key = $map['current_path'] ?? '';
            if (empty($key)) {
                // 从 git_remote 提取
                $key = $this->extractProjectPath($map['git_remote'] ?? '');
            }
            if (empty($key)) {
                $key = $map['job_name'];
            }

            if (!isset($grouped[$key])) {
                $grouped[$key] = [
                    'git_platform'      => $map['git_platform'],
                    'git_remote'        => $map['git_remote'],
                    'gitlab_id'         => $map['gitlab_id'] ?? null,
                    'web_url'           => $map['web_url'] ?? '',
                    'harbor_repository' => $map['harbor_repository'] ?? '',
                    'jobs'              => [],
                ];
            }
            $grouped[$key]['jobs'][] = $map['job_name'];
        }

        // 去重并排序 jobs
        foreach ($grouped as &$item) {
            $item['jobs'] = array_unique($item['jobs']);
            sort($item['jobs']);
        }

        $response->getBody()->write(json_encode($grouped));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * 获取已接入的 Git 平台列表（静态配置）
     * GET/POST /api/main/git/platforms
     */
    public function gitPlatforms(Request $request, Response $response): Response
    {
        $gitPlatforms = $this->config->getGitPlatformsConfig();
        $harbor = $this->config->getHarborApiInfo();
        $data = [
            'git_platforms' => $gitPlatforms,
            'harbor'        => $harbor,
        ];
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * 发现实际使用的 Git 平台与配置差异
     * GET/POST /api/main/git/discovery
     */
    public function gitDiscovery(Request $request, Response $response): Response
    {
        $maps = $this->map->getAllMaps();
        $usedPlatforms = [];
        foreach ($maps as $map) {
            $platform = $map['git_platform'] ?? '';
            if ($platform && !in_array($platform, $usedPlatforms)) {
                $usedPlatforms[] = $platform;
            }
        }

        $configured = [];
        $unconfigured = [];
        foreach ($usedPlatforms as $name) {
            if ($this->config->isPlatformConfigured($name)) {
                $configured[] = [
                    'name' => $name,
                    'api_base_url' => $this->config->getGitPlatformsConfig()[$name]['api_base_url'] ?? '',
                ];
            } else {
                // 找一个示例 remote
                $exampleRemote = '';
                foreach ($maps as $map) {
                    if (($map['git_platform'] ?? '') === $name && !empty($map['git_remote'])) {
                        $exampleRemote = $map['git_remote'];
                        break;
                    }
                }
                $unconfigured[] = [
                    'name' => $name,
                    'example_remote' => $exampleRemote,
                ];
            }
        }

        $data = [
            'configured'   => $configured,
            'unconfigured' => $unconfigured,
        ];
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * 从远程 URL 提取项目路径（如 tools/registry）
     */
    private function extractProjectPath(string $remote): string
    {
        if (preg_match('#[:/]([^/]+/[^/]+?)(\.git)?$#', $remote, $matches)) {
            return $matches[1];
        }
        return '';
    }
}