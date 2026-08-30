<?php
namespace App\Service;

use App\Config\AppConfig;

/**
 * 统一映射查询层 —— 所有 job_git_map 读/写/过滤/BUILD_MODE 控制集中于此
 */
class MappingManager
{
    private AppConfig $config;

    public function __construct(AppConfig $config)
    {
        $this->config = $config;
    }

    /** 当前全局 BUILD_MODE（数据库为唯一来源） */
    public function buildMode(): string
    {
        return $this->config->getBuildMode();
    }

    /** 是否注册了某类 Provider */
    public function hasJenkins(): bool
    {
        return in_array($this->config->getBuildMode(), [AppConfig::BUILD_MODE_JENKINS, AppConfig::BUILD_MODE_BOTH]);
    }

    public function hasGitlabCi(): bool
    {
        return in_array($this->config->getBuildMode(), [AppConfig::BUILD_MODE_GITLAB_CI, AppConfig::BUILD_MODE_BOTH]);
    }

    public function hasCustomPush(): bool
    {
        return $this->config->getCustomPushEnabled();
    }

    // ── 全量查询（过滤禁用 + 模式筛选） ──

    /** 返回当前模式下活跃的映射条目 */
    public function activeMaps(): array
    {
        $maps = $this->config->getJobGitMap();
        $maps = array_filter($maps, fn($m) => ($m['status'] ?? AppConfig::STATUS_ACTIVE) === AppConfig::STATUS_ACTIVE);

        $mode = $this->config->getBuildMode();
        $cpEnabled = $this->config->getCustomPushEnabled();

        if ($mode === AppConfig::BUILD_MODE_GITLAB_CI) {
            // gitlab_ci 模式：保留 gitlab_ci + custom_push（如果开启）
            $maps = array_filter($maps, function($m) use ($cpEnabled) {
                $bp = $m['build_provider'] ?? AppConfig::PROVIDER_JENKINS;
                return $bp === AppConfig::PROVIDER_GITLAB_CI
                    || ($cpEnabled && $bp === AppConfig::PROVIDER_CUSTOM_PUSH);
            });
        } elseif ($mode === AppConfig::BUILD_MODE_JENKINS) {
            // jenkins 模式：保留 jenkins + custom_push（如果开启）
            $maps = array_filter($maps, function($m) use ($cpEnabled) {
                $bp = $m['build_provider'] ?? AppConfig::PROVIDER_JENKINS;
                return $bp !== AppConfig::PROVIDER_GITLAB_CI
                    && !(!$cpEnabled && $bp === AppConfig::PROVIDER_CUSTOM_PUSH);
            });
        } else {
            // both 模式：保留 jenkins + gitlab_ci，custom_push 仅在开启时保留
            if (!$cpEnabled) {
                $maps = array_filter($maps, function($m) {
                    return ($m['build_provider'] ?? AppConfig::PROVIDER_JENKINS) !== AppConfig::PROVIDER_CUSTOM_PUSH;
                });
            }
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
        $provider  = AppConfig::PROVIDER_JENKINS;
        $projectId = $projectPath;

        foreach ($this->activeMaps() as $m) {
            $job = $m['job_name'] ?? '';
            $cp  = $m['current_path'] ?? '';
            if ($job === $projectPath || $cp === $projectPath) {
                $bp = $m['build_provider'] ?? AppConfig::PROVIDER_JENKINS;
                if (!empty($bp)) $provider = $bp;

                if ($provider === AppConfig::PROVIDER_GITLAB_CI && !empty($m['project_id'])) {
                    // GitLab CI：用数字 project_id 调外部 API
                    $projectId = (string) $m['project_id'];
                } elseif ($provider !== AppConfig::PROVIDER_JENKINS) {
                    // 推送式 CI（custom_push 及 settings.php 自定义 push provider）：
                    // 以 job_name（映射主键，跨 build_provider 切换的稳定身份）为规范本地记录键，
                    // current_path 兜底，让推 job_name / current_path 都归一化到同一条记录。
                    // 不用 current_path 当键：jenkins 转 custom_push 时 job_name≠current_path，
                    // 若按 current_path 落库，改回 jenkins 后同一项目会被当成两条（project 分裂）。
                    $projectId = (string) ($job !== '' ? $job : ($cp !== '' ? $cp : $projectPath));
                }
                // jenkins：projectId 保持原始 path（JenkinsService 按路径拼 URL）

                break;
            }
        }
        return ['provider' => $provider, 'projectId' => $projectId];
    }
}
