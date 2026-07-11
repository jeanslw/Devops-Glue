<?php
namespace App\Service;

use App\Config\AppConfig;

/**
 * 统一映射查询层 —— 所有 job_git_map 读/写/过滤/BUILD_MODE 控制集中于此
 */
class MappingManager
{
    private AppConfig $config;
    private string $buildMode;

    public function __construct(AppConfig $config)
    {
        $this->config    = $config;
        $this->buildMode = $_ENV['BUILD_MODE'] ?? 'both';
    }

    /** 当前全局 BUILD_MODE */
    public function buildMode(): string
    {
        return $this->buildMode;
    }

    /** 是否注册了某类 Provider */
    public function hasJenkins(): bool
    {
        return in_array($this->buildMode, ['jenkins', 'both']);
    }

    public function hasGitlabCi(): bool
    {
        return in_array($this->buildMode, ['gitlab_ci', 'both']);
    }

    // ── 全量查询（过滤禁用 + 模式筛选） ──

    /** 返回当前模式下活跃的映射条目 */
    public function activeMaps(): array
    {
        $maps = $this->config->getJobGitMap();
        $maps = array_filter($maps, fn($m) => ($m['status'] ?? 'active') === 'active');

        if ($this->buildMode === 'gitlab_ci') {
            $maps = array_filter($maps, fn($m) => ($m['build_provider'] ?? 'jenkins') === 'gitlab_ci');
        } elseif ($this->buildMode === 'jenkins') {
            $maps = array_filter($maps, fn($m) => ($m['build_provider'] ?? 'jenkins') !== 'gitlab_ci');
        }
        return array_values($maps);
    }

    /** 返回当前模式下的 Job 名称列表 */
    public function activeJobNames(): array
    {
        return array_map(fn($m) => $m['job_name'], $this->activeMaps());
    }

    /** 返回活跃条目使用的 Git 平台清单 */
    public function usedGitPlatforms(): array
    {
        $platforms = [];
        foreach ($this->activeMaps() as $m) {
            $p = $m['git_platform'] ?? '';
            if ($p && !in_array($p, $platforms)) $platforms[] = $p;
        }
        return $platforms;
    }

    // ── 单项解析 ──

    /**
     * 按项目路径解析 CI 系统 + 项目 ID
     * @return array{provider: string, projectId: string}
     */
    public function resolveProject(string $projectPath): array
    {
        $provider  = 'jenkins';
        $projectId = $projectPath;

        foreach ($this->activeMaps() as $m) {
            $job = $m['job_name'] ?? '';
            $cp  = $m['current_path'] ?? '';
            if ($job === $projectPath || $cp === $projectPath || $job === str_replace('-', '/', $projectPath)) {
                $bp = $m['build_provider'] ?? 'jenkins';
                if (!empty($bp)) $provider = $bp;
                if ($provider !== 'jenkins' && !empty($m['project_id'])) {
                    $projectId = (string) $m['project_id'];
                }
                break;
            }
        }
        return ['provider' => $provider, 'projectId' => $projectId];
    }
}
