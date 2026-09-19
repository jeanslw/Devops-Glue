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

    /** 当前启用的拉取式构建 provider 集合（数据库为唯一来源） */
    public function activeBuildProviders(): array
    {
        return $this->config->getBuildModes();
    }

    /** 是否启用了某类 Provider */
    public function hasJenkins(): bool
    {
        return in_array(AppConfig::PROVIDER_JENKINS, $this->activeBuildProviders(), true);
    }

    public function hasGitlabCi(): bool
    {
        return in_array(AppConfig::PROVIDER_GITLAB_CI, $this->activeBuildProviders(), true);
    }

    public function hasGiteaCi(): bool
    {
        return in_array(AppConfig::PROVIDER_GITEA_CI, $this->activeBuildProviders(), true);
    }

    public function hasCustomPush(): bool
    {
        return $this->config->getCustomPushEnabled();
    }

    // ── 全量查询（过滤禁用 + 模式筛选） ──

    /** 返回当前启用集合下活跃的映射条目（custom_push 独立开关，开启时一并保留） */
    public function activeMaps(): array
    {
        $maps = $this->config->getJobGitMap();
        $maps = array_filter($maps, fn($m) => ($m['status'] ?? AppConfig::STATUS_ACTIVE) === AppConfig::STATUS_ACTIVE);

        $enabled = $this->config->getBuildModes();
        $cpEnabled = $this->config->getCustomPushEnabled();

        $maps = array_filter($maps, function ($m) use ($enabled, $cpEnabled) {
            $bp = $m['build_provider'] ?? AppConfig::PROVIDER_JENKINS;
            if (in_array($bp, $enabled, true)) {
                return true;
            }
            return $cpEnabled && $bp === AppConfig::PROVIDER_CUSTOM_PUSH;
        });
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
            if ($p && !in_array($p, $platforms)) {
                $platforms[] = $p;
            }
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
        // 防御：入参/存量数据可能带首尾空白（历史脏数据曾导致 Jenkins `job/ foo` 404），
        // 解析前先归一到 canonical 键，与 buildEntry/saveDiscovered 的 trim 写入对齐。
        $projectPath = trim($projectPath);

        // Pass 1：按 job_name（canonical 主键）精确匹配，遍历「全部」映射（含 pending/disabled）。
        // job_name 是各 CI 的唯一身份，绝不能被其它 provider 的 current_path 别名「劫持」——
        // 否则 gitea_ci 未启用时，路径 jeanslw/Devops_CD（gitea 的 job_name）会落到 jenkins 的
        // current_path 别名上，拼出 job/jeanslw/... 404（历史 bug）。
        // 命中未启用 provider 时也返回该 provider，让调用方走 registry 的「未配置/未启用」分支。
        foreach ($this->config->getJobGitMap() as $m) {
            $job = trim((string) ($m['job_name'] ?? ''));
            if ($job !== '' && $job === $projectPath) {
                return $this->resolveMap($projectPath, $m);
            }
        }

        // Pass 2：current_path 别名兜底（仅 active 映射，供 custom_push 推 git 路径归一化用）。
        foreach ($this->activeMaps() as $m) {
            $cp = trim((string) ($m['current_path'] ?? ''));
            if ($cp !== '' && $cp === $projectPath) {
                return $this->resolveMap($projectPath, $m);
            }
        }

        // 完全未命中：保持 jenkins + 原始 path（unmapped 直连，JenkinsService 按路径拼 URL）。
        return ['provider' => AppConfig::PROVIDER_JENKINS, 'projectId' => $projectPath];
    }

    /**
     * 将命中的映射行解析为 [provider, projectId]。
     * projectId 按 provider 归一化：gitlab_ci → 数字 project_id；jenkins → job_name（Job 路径）；
     * 其余（custom_push / gitea_ci）→ job_name（current_path 兜底），保证推 job_name/current_path 收敛到同一条。
     */
    private function resolveMap(string $projectPath, array $m): array
    {
        $provider = $m['build_provider'] ?? AppConfig::PROVIDER_JENKINS;
        if (empty($provider)) {
            $provider = AppConfig::PROVIDER_JENKINS;
        }
        $job = trim((string) ($m['job_name'] ?? ''));
        $cp  = trim((string) ($m['current_path'] ?? ''));

        if ($provider === AppConfig::PROVIDER_GITLAB_CI && !empty($m['project_id'])) {
            // GitLab CI：用数字 project_id 调外部 API
            $projectId = (string) $m['project_id'];
        } elseif ($provider === AppConfig::PROVIDER_JENKINS) {
            // Jenkins：projectId 必须是 Jenkins Job 路径（job_name），而非 current_path（git 路径）。
            $projectId = (string) ($job !== '' ? $job : $projectPath);
        } else {
            // custom_push / gitea_ci：以 job_name 为规范键，current_path 兜底。
            $projectId = (string) ($job !== '' ? $job : ($cp !== '' ? $cp : $projectPath));
        }

        return ['provider' => $provider, 'projectId' => $projectId];
    }

    /**
     * 将项目路径 + pipeline IID 归一成 Glue 内部唯一 Pipeline Identity。
     * 所有持久化层应优先使用此 identity，而不是自行拼接 provider/project key。
     */
    public function pipelineIdentity(string $projectPath, int $pipelineIid): PipelineIdentity
    {
        $resolved = $this->resolveProject($projectPath);
        return new PipelineIdentity($resolved['provider'], $resolved['projectId'], $pipelineIid);
    }
}
