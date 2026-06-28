<?php

namespace App\Service;

use Psr\SimpleCache\CacheInterface;

class GitService
{
    private GitlabService $gitlabService;
    private JenkinsService $jenkinsService; 
    private ?CacheInterface $cache;

    public function __construct(
        GitlabService $gitlabService, 
        JenkinsService $jenkinsService,
        ?CacheInterface $cache = null
    ) {
        $this->gitlabService = $gitlabService;
        $this->jenkinsService = $jenkinsService;
        $this->cache = $cache;
    }

    /**
     * 获取代码仓库分支列表
     */
    public function getBranchList(string $group, string $project): array
    {
        $this->normalizeJobParams($group, $project); 
        try {
            $jobName = trim($group . '/' . $project, '/');
            $projectId = $this->resolveProviderId($jobName);

            if (empty($projectId)) {
                return ['error' => '未找到该 Job 对应的 GitLab 项目，请检查 Job 名称或 Git 配置'];
            }

            return $this->gitlabService->getBranches((int)$projectId);
        } catch (\Exception $e) {
            return ['error' => '获取分支失败: ' . $e->getMessage()];
        }
    }

    /**
     * 获取 Job 与 Git 映射列表
     */
    public function getJobGitList(): array
    {
        $jobFullNames = $this->jenkinsService->getJobsList();
        if (isset($jobFullNames['error'])) return $jobFullNames;

        $result = [];
        $startTime = time();
        $maxExecutionTime = 25; 
        
        foreach ($jobFullNames as $jobName) { 
            if ((time() - $startTime) > $maxExecutionTime) {
                $result[] = ['job_name' => 'SYSTEM_TIMEOUT_WARNING', 'status' => 'timeout'];
                break;
            }

            $item = [
                'job_name'     => $jobName,
                'gitlab_id'    => null,
                'status'       => 'error',
                'message'      => '',
                'debug'        => [] 
            ];

            try {
                $item['debug']['step1_try_jobname'] = $jobName;
                $meta = $this->gitlabService->getProjectMetaByJobName($jobName);
                $item['debug']['step1_result'] = $meta ? 'SUCCESS' : 'FAILED';

                if (!$meta) {
                    [$group, $project] = $this->splitJobName($jobName);
                    $gitUrl = $this->jenkinsService->extractGitUrl($group, $project); 
                    $item['debug']['step2_git_url'] = $gitUrl ?: '(空)';
                    
                    if ($gitUrl) {
                        $realGitPath = $this->parseGitProjectPath($gitUrl); 
                        if ($realGitPath && $realGitPath !== $jobName) {
                            $meta = $this->gitlabService->getProjectMetaByJobName($realGitPath);
                            if ($meta) {
                                $item['message'] = "Job_config_GitURL路径与GIT_URL不一致,校准未通过! ({$realGitPath})";
                            }
                        }
                    }
                }

                if ($meta) {
                    $item['status']       = 'synced';
                    $item['gitlab_id']    = $meta['project_id'] ?? null;
                    $item['web_url']      = $meta['web_url'] ?? '';
                    $item['current_path'] = $meta['path_with_namespace'] ?? '';

                    if ($this->cache && isset($meta['project_id'])) {
                        $cacheKey = 'jenkins_git_mapping_' . md5($jobName);
                        $this->cache->set($cacheKey, $meta['project_id'], 86400 * 7); 
                    }
                } else {
                    if (empty($item['message'])) $item['message'] = "在 GitLab 中未找到该项目!";
                }
            } catch (\Throwable $e) {
                $item['message'] = "查询异常: " . $e->getMessage();
            }
            $result[] = $item;
        }
        return $result;
    }

    /**
     * 核心算法：智能解析 GitLab ID
     * 💡 未来扩展点：可以在这里加一个 $provider 参数，根据配置决定调用 Gitlab、Github 还是 Gitee
     */
    protected function resolveProviderId(string $jobName): ?int
    {
        $cacheKey = 'jenkins_git_mapping_' . md5($jobName);
        if ($this->cache) {
            $cachedId = $this->cache->get($cacheKey);
            if ($cachedId !== null) return (int)$cachedId;
        }

        try {
            $meta = $this->gitlabService->getProjectMetaByJobName($jobName);
            if (!empty($meta['project_id'])) {
                if ($this->cache) $this->cache->set($cacheKey, $meta['project_id'], 86400 * 7);
                return (int)$meta['project_id'];
            }

            [$group, $project] = $this->splitJobName($jobName);
            $gitUrl = $this->jenkinsService->extractGitUrl($group, $project);
            
            if (!empty($gitUrl)) {
                $realGitPath = $this->parseGitProjectPath($gitUrl);
                if ($realGitPath && $realGitPath !== $jobName) {
                    $meta = $this->gitlabService->getProjectMetaByJobName($realGitPath);
                    if (!empty($meta['project_id'])) {
                        if ($this->cache) $this->cache->set($cacheKey, $meta['project_id'], 86400 * 7);
                        return (int)$meta['project_id'];
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log("[GitService] 解析 Job [{$jobName}] 的 GitLab ID 失败: " . $e->getMessage());
        }
        return null;
    }

    // ==========================================
    // 辅助方法
    // ==========================================
    private function splitJobName(string $jobName): array
    {
        $parts = explode('/', $jobName);
        $project = array_pop($parts);
        $group = implode('/', $parts);
        return [$group, $project];
    }

    private function parseGitProjectPath(string $gitUrl): ?string
    {
        if (empty($gitUrl)) return null;
        $cleanUrl = trim(preg_replace('#\.git\s*$#i', '', $gitUrl));
        $cleanUrl = rtrim($cleanUrl, '/');
        if (preg_match('#^[^/]+:(.+)$#', $cleanUrl, $matches)) $cleanUrl = $matches[1]; 
        $parts = array_values(array_filter(explode('/', $cleanUrl), fn($val) => $val !== ''));
        if (count($parts) >= 2) return implode('/', array_slice($parts, -2));
        return count($parts) === 1 ? $parts[0] : null;
    }

    private function normalizeJobParams(string &$group, string &$project): void
    {
        if (str_contains($project, '/')) {
            $parts = explode('/', $project);
            $project = array_pop($parts);       
            $realGroup = implode('/', $parts);  
            if ($group === '' || $group === '_' || $group === $realGroup) $group = $realGroup;
            elseif ($group !== $realGroup) $group = $realGroup;
        }
        $group = trim($group, '/');
        $project = trim($project, '/');
    }
}