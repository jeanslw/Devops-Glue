<?php
namespace App\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Service\JenkinsService;
use App\Service\MapService;
use App\Service\HarborService;
use App\Config\AppConfig;

class MainController extends BaseController
{
    private JenkinsService $jenkins;
    private MapService $map;
    private AppConfig $config;
    private ?HarborService $harbor;

    public function __construct(JenkinsService $jenkins, MapService $map, AppConfig $config, ?HarborService $harbor = null)
    {
        $this->jenkins = $jenkins;
        $this->map = $map;
        $this->config = $config;
        $this->harbor = $harbor;
    }

    /**
     * 获取所有 Job 列表
     */
    public function jobsList(Request $request, Response $response): Response
    {
        $jobs = $this->jenkins->getAllJobs();
        return $this->output($response, $jobs, $request);
    }

    /**
     * 获取三方映射关系（按项目分组）
     */
    public function mapList(Request $request, Response $response): Response
    {
        $maps = $this->map->getAllMaps();
        $grouped = [];
        foreach ($maps as $map) {
            $key = $map['current_path'] ?? '';
            if (empty($key)) {
                $key = $this->extractProjectPath($map['git_remote'] ?? '');
            }
            if (empty($key)) {
                $key = $map['job_name'];
            }

            if (!isset($grouped[$key])) {
                $grouped[$key] = [
                    'git_platform'      => $map['git_platform'],
                    'git_remote'        => $map['git_remote'],
                    'project_id'        => $map['project_id'] ?? null,
                    'web_url'           => $map['web_url'] ?? '',
                    'harbor_repository' => $map['harbor_repository'] ?? '',
                    'jobs'              => [],
                ];
            }
            $grouped[$key]['jobs'][] = $map['job_name'];
        }

        foreach ($grouped as &$item) {
            $item['jobs'] = array_unique($item['jobs']);
            sort($item['jobs']);
        }

        return $this->output($response, $grouped, $request);
    }

    /**
     * 获取已接入的 Git 平台列表（静态配置）
     */
    public function gitPlatforms(Request $request, Response $response): Response
    {
        $data = [
            'git_platforms' => $this->config->getGitPlatformsConfig(),
            'harbor'        => $this->config->getHarborApiInfo(),
        ];
        return $this->output($response, $data, $request);
    }

    /**
     * 发现实际使用的 Git 平台与配置差异
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
    // 从配置数组中找出该平台的 api_base_url
    $apiBaseUrl = '';
        foreach ($this->config->getGitPlatformsConfig() as $cfg) {
            if (($cfg['name'] ?? '') === $name) {
                $apiBaseUrl = $cfg['api_base_url'] ?? '';
                break;
            }
        }
        $configured[] = [
            'name' => $name,
            'api_base_url' => $apiBaseUrl,
        ];
    } else {
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
        return $this->output($response, $data, $request);
    }

    /**
     * 健康检查端点
     */
    public function health(Request $request, Response $response): Response
    {
        $checks = [
            'jenkins' => false,
            'harbor'  => false,
        ];

        try {
            $this->jenkins->getAllJobs();
            $checks['jenkins'] = true;
        } catch (\Exception $e) {
            $checks['jenkins'] = false;
        }

        if ($this->harbor) {
            try {
                $projects = $this->harbor->getProjects();
                $checks['harbor'] = !isset($projects['error']);
            } catch (\Exception $e) {
                $checks['harbor'] = false;
            }
        } else {
            $checks['harbor'] = null; // 未配置
        }

        $allOk = $checks['jenkins'] && ($checks['harbor'] === true || $checks['harbor'] === null);
        $status = $allOk ? 'ok' : 'degraded';

        $data = [
            'status'   => $status,
            'checks'   => $checks,
            'app_env'  => $this->config->getAppEnv(),
            'time'     => date('Y-m-d H:i:s'),
        ];

        if (!$allOk) {
            return $this->jsonError($response, '服务降级: ' . json_encode($checks), 503);
        }

        return $this->output($response, $data, $request);
    }

    /**
     * 从远程 URL 提取项目路径
     */
    private function extractProjectPath(string $remote): string
    {
        if (preg_match('#[:/]([^/]+/[^/]+?)(\.git)?$#', $remote, $matches)) {
            return $matches[1];
        }
        return '';
    }
}