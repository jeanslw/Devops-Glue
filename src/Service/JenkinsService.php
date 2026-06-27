<?php

namespace App\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Psr\SimpleCache\CacheInterface;

class JenkinsService
{
    private Client $client;
    private GitlabService $gitlabService;
    private ?CacheInterface $cache;
    
    private string $baseUrl;
    private string $username;
    private string $apiToken;

    public function __construct(
        Client $client,
        string $baseUrl,
        string $username,
        string $apiToken,
        GitlabService $gitlabService,
        ?CacheInterface $cache = null
    ) {
        $this->baseUrl  = rtrim($baseUrl, '/');
        $this->username = $username;
        $this->apiToken = $apiToken;
        
        // 显式创建 CookieJar 并注入到 Client 中
        $cookieJar = new CookieJar();
        
        $this->client = new Client([
            'base_uri' => $this->baseUrl,
            'timeout'  => 30,
            'verify'   => false,
            'auth'     => [$this->username, $this->apiToken],
            'headers'  => ['Accept' => 'application/json'],
            'cookies'  => $cookieJar, 
        ]);
        
        $this->gitlabService = $gitlabService;
        $this->cache = $cache;
    }
    
    // ==========================================
    // 核心区：智能 ID 解析 
    // ==========================================

    protected function resolveProviderId(string $jobName): ?int
    {
        $cacheKey = 'jenkins_git_mapping_' . md5($jobName);

        if ($this->cache) {
            $cachedId = $this->cache->get($cacheKey);
            if ($cachedId !== null) {
                return (int)$cachedId;
            }
        }

        try {
            $meta = $this->gitlabService->getProjectMetaByJobName($jobName);
            if (!empty($meta['project_id'])) {
                if ($this->cache) {
                    $this->cache->set($cacheKey, $meta['project_id'], 86400 * 7);
                }
                return (int)$meta['project_id'];
            }

            [$group, $project] = $this->splitJobName($jobName);
            $gitUrl = $this->extractGitUrl($group, $project);
            
            if (!empty($gitUrl)) {
                $realGitPath = $this->parseGitProjectPath($gitUrl);
                if ($realGitPath && $realGitPath !== $jobName) {
                    $meta = $this->gitlabService->getProjectMetaByJobName($realGitPath);
                    if (!empty($meta['project_id'])) {
                        if ($this->cache) {
                            $this->cache->set($cacheKey, $meta['project_id'], 86400 * 7);
                        }
                        return (int)$meta['project_id'];
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log("[JenkinsService] 解析 Job [{$jobName}] 的 GitLab ID 失败: " . $e->getMessage());
        }

        return null;
    }

    //获取分支和git对应关系
    public function getJobGitList(): array
    {
        $jobFullNames = $this->getJobsList();
        if (isset($jobFullNames['error'])) {
            return $jobFullNames;
        }

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
                'message'      => ''
            ];

            try {
                $item['debug']['step1_try_jobname'] = $jobName;
                $meta = $this->gitlabService->getProjectMetaByJobName($jobName);
                $item['debug']['step1_result'] = $meta ? 'SUCCESS' : 'FAILED (Null/Empty)';

                if (!$meta) {
                    [$group, $project] = $this->splitJobName($jobName);
                    $item['debug']['step2_split'] = "Group: [{$group}], Project: [{$project}]";
                    
                    $gitUrl = $this->extractGitUrl($group, $project); 
                    $item['debug']['step2_git_url'] = $gitUrl ?: '(空!Jenkins 没拿到 URL)';
                    
                    if ($gitUrl) {
                        $realGitPath = $this->parseGitProjectPath($gitUrl); 
                        $item['debug']['step2_parsed_path'] = $realGitPath ?: '(解析失败！)';
                        
                        if ($realGitPath && $realGitPath !== $jobName) {
                            $item['debug']['step2_try_realpath'] = $realGitPath;
                            $meta = $this->gitlabService->getProjectMetaByJobName($realGitPath);
                            $item['debug']['step2_result'] = $meta ? 'SUCCESS' : 'FAILED (Null/Empty)';
                            
                            if ($meta) {
                                $item['message'] = "Job_config_GitURL路径与GIT_URL不一致,校准未通过! ({$realGitPath})";
                            }
                        } else {
                             $item['debug']['step2_skip_reason'] = '解析出的路径与JobName相同,或解析为空!';
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
                    if (empty($item['message'])) {
                        $item['message'] = "在 GitLab 中未找到该项目!";
                    }
                }

            } catch (\Throwable $e) {
                $item['message'] = "查询异常: " . $e->getMessage();
                $item['debug']['exception'] = $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
                error_log("[GitLab Sync] Job {$jobName} 获取 Meta 失败: " . $e->getMessage());
            }

            $result[] = $item;
        }
        
        return $result;
    }

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

    // ==========================================
    // 【基础检查与路径构建】
    // ==========================================

    private function checkConfig(): ?array
    {
        if (empty($this->baseUrl) || empty($this->username) || empty($this->apiToken)) {
            return ['error' => 'Jenkins 配置缺失，请检查 .env 文件'];
        }
        return null;
    }

    private function buildJobPath(string $group, string $project): string
    {
        if ($group === '' || $group === '_') {
            return "/job/" . rawurlencode($project);
        }
        $groups = explode('/', $group);
        $path = '';
        foreach ($groups as $g) {
            $path .= "/job/" . rawurlencode($g);
        }
        return $path . "/job/" . rawurlencode($project);
    }

    private function splitJobName(string $jobName): array
    {
        $parts = explode('/', $jobName);
        $project = array_pop($parts);
        $group = implode('/', $parts);
        return [$group, $project];
    }

    // 移除 $this->baseUrl 拼接，只传相对路径给 Guzzle
    private function requestJson(string $path, array $query = []): array
    {
        if ($err = $this->checkConfig()) return $err;
        try {
            $response = $this->client->get($path . '/api/json', [
                'query' => $query,
            ]);
            $data = json_decode($response->getBody()->getContents(), true);
            return (json_last_error() !== JSON_ERROR_NONE) ? ['error' => 'JSON 解析失败'] : $data;
        } catch (GuzzleException $e) {
            return ['error' => 'Jenkins 请求失败: ' . $e->getMessage()];
        }
    }

    // 移除 $this->baseUrl 拼接，只传相对路径给 Guzzle
    private function requestText(string $path): string
    {
        if ($err = $this->checkConfig()) return json_encode($err);
        try {
            $response = $this->client->get($path);
            return $response->getBody()->getContents();
        } catch (GuzzleException $e) {
            return "请求失败: " . $e->getMessage();
        }
    }

    private function fetchRawBuilds(string $group, string $project): array
    {
        $jobPath = $this->buildJobPath($group, $project);
        $data = $this->requestJson($jobPath, ['tree' => 'builds[number,timestamp]']);
        return isset($data['error']) ? $data : ($data['builds'] ?? []);
    }

    private function extractGitUrl(string $group, string $project): string
    {
        $jobPath = $this->buildJobPath($group, $project);
        $data = $this->requestJson($jobPath, ['tree' => 'scm[userRemoteConfigs[urls]]']);
        if (!isset($data['error'])) {
            $urls = $data['scm']['userRemoteConfigs'][0]['urls'] ?? [];
            if (!empty($urls)) return $urls[0];
        }
        $configXml = $this->requestText($jobPath . '/config.xml');
        if (preg_match('/<url>(https?:\/\/[^<]+\.git)<\/url>/', $configXml, $matches)) return $matches[1];
        if (preg_match('/<url>(git@[^<]+)<\/url>/', $configXml, $matches)) return $matches[1];
        return '';
    }

    public function getJobsList(): array
    {
        try {
            $recursiveTree = 'jobs[fullName,jobs[fullName,jobs[fullName,jobs[fullName]]]]';
            
            $response = $this->client->get('/api/json', [
                'query' => ['tree' => $recursiveTree]
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            $result = [];
            $this->flattenJobs($data['jobs'] ?? [], $result);
            
            return $result;
            
        } catch (\Exception $e) {
            throw new \RuntimeException("获取 Jenkins Job 列表失败: " . $e->getMessage());
        }
    }

    private function flattenJobs(array $jobs, array &$result): void
    {
        foreach ($jobs as $job) {
            $fullName = $job['fullName'] ?? ($job['name'] ?? '');
            if (isset($job['jobs']) && is_array($job['jobs'])) {
                $this->flattenJobs($job['jobs'], $result);
            } else {
                if ($fullName !== '') $result[] = $fullName;
            }
        }
    }

    public function getParametersList(string $group, string $project, ?int $buildId = null): array
    {
        $this->normalizeJobParams($group, $project); 
        
        try {
            $jobPath = $this->buildJobPath($group, $project);
            
            // ==========================================
            // 场景 1：build_id 留空 (获取 Job 配置参数及可选值)
            // 输出格式：{"zone":["b1","b2"],"branches":["main","master"]}
            // ==========================================
            if ($buildId === null) {
                // 1. 获取基础参数 (注意：这里 branches 被跳过了)
                $result = $this->getJobConfigParameters($jobPath, $group, $project);
                
                // 2. 【关键修复】把 branches 补回来，组成完整的字典！
                try {
                    $branchList = $this->getBranchList($group, $project);
                    // 确保拿到的是数组且没有错误
                    $result['branches'] = (is_array($branchList) && !isset($branchList['error'])) ? $branchList : [];
                } catch (\Throwable $e) {
                    error_log("[JenkinsService] 补充 branches 失败: " . $e->getMessage());
                    $result['branches'] = [];
                }
                
                // 3. 返回完整的字典
                return $result;
            }

            // ==========================================
            // 场景 2 & 3：build_id = 0 或 > 0 (获取构建历史的参数名)
            // 输出格式：["zone", "branches"]
            // ==========================================
            return $this->getBuildHistoryParameterNames($jobPath, $buildId);

        } catch (\Throwable $e) {
            error_log("[JenkinsService] getParametersList 致命错误: " . $e->getMessage());
            return ['error' => '获取参数列表失败: ' . $e->getMessage()];
        }
    }

    /**
     * 【内部方法】获取 Job 配置参数及可选值 (原逻辑提取)
     */
    private function getJobConfigParameters(string $jobPath, string $group, string $project): array
    {
        $response = $this->client->get($jobPath . '/api/json', [
            'query' => ['tree' => 'property[parameterDefinitions[name,type,choices]]']
        ]);

        $data = json_decode($response->getBody()->getContents(), true);
        $result = [];

        if (isset($data['property']) && is_array($data['property'])) {
            foreach ($data['property'] as $property) {
                if (!isset($property['parameterDefinitions']) || !is_array($property['parameterDefinitions'])) {
                    continue;
                }
                
                foreach ($property['parameterDefinitions'] as $param) {
                    $name = $param['name'] ?? '';
                    if ($name === '' || $name === 'branches') {
                        continue; // 跳过 branches，后面单独获取
                    }

                    $result[$name] = (isset($param['choices']) && is_array($param['choices'])) 
                        ? $param['choices'] 
                        : [];
                }
            }
        }
        
        // 获取 branches (保持您原有的逻辑)
        try {
            $branchResult = $this->getBranchList($group, $project);
            
            if (is_array($branchResult) && isset($branchResult['error'])) {
                error_log("[JenkinsService] 获取 branches 列表失败: " . $branchResult['error']);
                $result['branches'] = [];
            } else {
                $result['branches'] = is_array($branchResult) ? $branchResult : [];
            }
        } catch (\Throwable $e) {
            error_log("[JenkinsService] 获取 branches 异常: " . $e->getMessage());
            $result['branches'] = [];
        }

        return $result;
    }

    /**
     * 【内部方法】获取构建历史的参数名 (新逻辑)
     */
    private function getBuildHistoryParameterNames(string $jobPath, int $buildId): array
    {
        // 如果 buildId 为 0，查询 lastBuild；否则查询指定 buildId
        $buildPath = ($buildId === 0) ? 'lastBuild' : (string)$buildId;
        
        $url = sprintf('%s/%s/api/json', $jobPath, $buildPath);
        
        // 只获取 actions 中的 parameters 的 name
        $response = $this->client->get($url, [
            'query' => ['tree' => 'actions[parameters[name]]']
        ]);

        $data = json_decode($response->getBody()->getContents(), true);
        $paramNames = [];

        if (isset($data['actions']) && is_array($data['actions'])) {
            foreach ($data['actions'] as $action) {
                if (isset($action['parameters']) && is_array($action['parameters'])) {
                    foreach ($action['parameters'] as $param) {
                        if (isset($param['name']) && !in_array($param['name'], $paramNames)) {
                            $paramNames[] = $param['name'];
                        }
                    }
                }
            }
        }

        return $paramNames; // 返回一维数组，如 ["zone", "branches"]
    }

    public function triggerBuild(string $group, string $project, array $urlParams, array $bodyParams = []): array
    {

        $this->normalizeJobParams($group, $project);

        try {
            $jobPath = $this->buildJobPath($group, $project);
            $hasParams = !empty($urlParams) || !empty($bodyParams);
            $endpoint = $hasParams ? 'buildWithParameters' : 'build';

            // 获取 Crumb
            $crumbData = $this->getCrumb();

            $options = [];
            
            $allParams = array_merge($urlParams, $bodyParams);
            if (!empty($allParams)) {
                $options['form_params'] = $allParams;
            }

            // 注入 Crumb Header
            if (!empty($crumbData['headerName']) && !empty($crumbData['crumb'])) {
                $options['headers'] = [
                    $crumbData['headerName'] => $crumbData['crumb'],
                ];
            } else {
                error_log("⚠️ Jenkins Crumb 获取异常: " . json_encode($crumbData));
            }

            $relativeUrl = '/' . trim(rtrim($jobPath, '/') . '/' . $endpoint, '/');
            
            error_log(sprintf(
                "Jenkins Trigger | URL: %s | FormParams: %s | Crumb: %s",
                $relativeUrl,
                json_encode($allParams),
                $crumbData['crumb'] ?? 'MISSING'
            ));

            // 允许重定向（Jenkins 201 后常跟 302）
            $options['allow_redirects'] = false;

            $response = $this->client->post($relativeUrl, $options);
            $statusCode = $response->getStatusCode();

            if ($statusCode === 201 || $statusCode === 200 || $statusCode === 302) {
                return [
                    'code'             => 200,
                    'message'          => '构建触发成功',
                    'job'              => $project,
                    'triggered_params' => $allParams,
                ];
            }

            return ['error' => "Jenkins 返回异常状态码: {$statusCode}"];

        } catch (\GuzzleHttp\Exception\RequestException $e) {
            // 安全读取响应体（rewind 确保从头读）
            $statusCode = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 0;
            $responseBody = '';
            if ($e->hasResponse()) {
                $body = $e->getResponse()->getBody();
                $body->rewind(); // 👈 关键：重置指针
                $responseBody = $body->getContents();
            }
            
            if ($statusCode === 403) {
                error_log(sprintf(
                    "❌ Jenkins 403 Forbidden | URL: %s | Response: %s",
                    $e->getRequest()->getUri()->__toString(),
                    $responseBody
                ));
            }

            return [
                'error'       => '请求 Jenkins 失败: ' . $e->getMessage(),
                'status_code' => $statusCode,
                'response'    => $responseBody,
            ];
        } catch (\GuzzleHttp\Exception\GuzzleException $e) {
            // 捕获所有 Guzzle 异常（包括 ConnectException、TimeoutException 等）
            error_log("❌ Guzzle 网络级异常: " . $e->getMessage());
            return [
                'error'   => 'Jenkins 连接失败: ' . $e->getMessage(),
                'type'    => get_class($e),
            ];
        } catch (\Throwable $e) {
            // 捕获所有 PHP 错误（TypeError、ArgumentCountError 等）
            error_log("❌ triggerBuild 致命错误: " . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            return ['error' => '触发构建发生内部错误: ' . $e->getMessage()];
        }
    }
    public function getBuildStatus(string $group, string $project, int $buildId): array
    {
        $jobPath = $this->buildJobPath($group, $project);
        $data = $this->requestJson("{$jobPath}/{$buildId}", ['tree' => 'number,result,building,timestamp,duration']);
        if (isset($data['error'])) return $data;
        $status = ($data['building'] ?? false) ? 'BUILDING' : ($data['result'] ?? 'UNKNOWN');
        return [$status];
    }

    public function getBuildIdList(string $group, string $project): array
    {
        $builds = $this->fetchRawBuilds($group, $project);
        if (isset($builds['error'])) return $builds;
        return array_map(fn($b) => (string)($b['number'] ?? ''), $builds);
    }

    public function getBuildTimeList(string $group, string $project): array
    {
        $builds = $this->fetchRawBuilds($group, $project);
        if (isset($builds['error'])) return $builds;
        $result = [];
        foreach ($builds as $build) {
            $ts = (int)(($build['timestamp'] ?? 0) / 1000);
            $result[] = "#" . ($build['number'] ?? '') . " [" . ($ts > 0 ? date('Y-m-d H:i:s', $ts) : '未知') . "]";
        }
        return $result;
    }

    public function getBuildList(string $group, string $project): array
    {
        $builds = $this->fetchRawBuilds($group, $project);
        if (isset($builds['error'])) return $builds;
        return array_map(fn($b) => "#" . ($b['number'] ?? ''), $builds);
    }

    private function parseGitProjectPath(string $gitUrl): ?string
    {
        if (empty($gitUrl)) return null;

        $cleanUrl = trim(preg_replace('#\.git\s*$#i', '', $gitUrl));
        $cleanUrl = rtrim($cleanUrl, '/');

        if (preg_match('#^[^/]+:(.+)$#', $cleanUrl, $matches)) {
            $cleanUrl = $matches[1]; 
        }

        $parts = explode('/', $cleanUrl);
        
        $parts = array_filter($parts, function($val) {
            return $val !== '';
        });
        
        $parts = array_values($parts);

        if (count($parts) >= 2) {
            return implode('/', array_slice($parts, -2));
        }

        return count($parts) === 1 ? $parts[0] : null;
    }

    /**
     * ✅ 新增：自动校正 group/project 参数
     * 处理调用方传入 "java", "java/registry" 或 "", "java/registry" 等不规范情况
     */
    private function normalizeJobParams(string &$group, string &$project): void
    {
        // 如果 project 中包含 /，说明 group 信息已经被包含在 project 里了
        if (str_contains($project, '/')) {
            $parts = explode('/', $project);
            $project = array_pop($parts);       // 最后一段才是真正的 project
            $realGroup = implode('/', $parts);  // 前面的都是 group
            
            // 只有当传入的 group 为空或与真实 group 不一致时才覆盖
            if ($group === '' || $group === '_' || $group === $realGroup) {
                $group = $realGroup;
            }
            // 如果传入的 group 和解析出的 group 不同，以解析出的为准（因为 project 里的路径更可信）
            elseif ($group !== $realGroup) {
                error_log("[JenkinsService] 参数校正: group={$group}, project含/{$realGroup}/{$project}, 已修正group为{$realGroup}");
                $group = $realGroup;
            }
        }
        
        // 清理边界值
        $group = trim($group, '/');
        $project = trim($project, '/');
    }

    /**
     * 获取 Jenkins CSRF Crumb
     */
    private function getCrumb(): array
    {
        try {
            $response = $this->client->get('/crumbIssuer/api/json');
            $data = json_decode($response->getBody()->getContents(), true);

            if (isset($data['crumb'], $data['crumbRequestField'])) {
                return [
                    'headerName' => $data['crumbRequestField'], // Jenkins-Crumb
                    'crumb'      => $data['crumb'],             // 5b8ff59a...
                ];
            }
        } catch (\Throwable $e) {
            error_log("[JenkinsService] 获取 Crumb 失败: " . $e->getMessage());
        }

        return [];
    }

    public function getConsoleOutput(string $group, string $project, int $buildNumber): string
    {
        $this->normalizeJobParams($group, $project); 
        $jobPath = $this->buildJobPath($group, $project);
        $text = $this->requestText("{$jobPath}/{$buildNumber}/consoleText");
        if (empty($text)) return "获取日志失败或日志为空";
        
        $lines = explode("\n", $text);
        $html = '<pre style="font-family: monospace; font-size: 12px;  color: #000000; padding: 15px; border-radius: 5px; overflow-x: auto;">';
        foreach ($lines as $line) {
            $html .= htmlspecialchars($line) . "<br>";
        }
        return $html . '</pre>';
    }
}