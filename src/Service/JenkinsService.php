<?php

namespace App\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\GuzzleException;
use Psr\SimpleCache\CacheInterface;

class JenkinsService
{
    private Client $client;
    private ?CacheInterface $cache;
    
    private string $baseUrl;
    private string $username;
    private string $apiToken;

    public function __construct(
        Client $client,
        string $baseUrl,
        string $username,
        string $apiToken,
        ?CacheInterface $cache = null
    ) {
        $this->baseUrl  = rtrim($baseUrl, '/');
        $this->username = $username;
        $this->apiToken = $apiToken;
        
        $cookieJar = new CookieJar();
        $this->client = new Client([
            'base_uri' => $this->baseUrl,
            'timeout'  => 30,
            'verify'   => false,
            'auth'     => [$this->username, $this->apiToken],
            'headers'  => ['Accept' => 'application/json'],
            'cookies'  => $cookieJar, 
        ]);
        
        $this->cache = $cache;
    }

    /**
     * 触发 Jenkins 构建任务
     */
    public function triggerBuild(string $jobName, array $buildParams = []): array
    {
        // 1. 基础校验
        if (empty($jobName)) {
            return $this->errorResponse(400, '任务名称不能为空');
        }

        if (empty($this->baseUrl) || empty($this->username) || empty($this->apiToken)) {
            return $this->errorResponse(500, 'Jenkins 基础配置缺失', '请检查 settings.php 或 .env');
        }

        // 2. 构建相对 URL (因为 client 里已经配置了 base_uri，这里不需要再拼完整域名了)
        $jobPath = 'job/' . str_replace('/', '/job/', trim($jobName, '/'));
        $endpoint = empty($buildParams) ? 'build' : 'buildWithParameters';
        $triggerPath = "{$jobPath}/{$endpoint}";

        // 3. 获取 CSRF Crumb (这里也会自动复用 client 里的 auth 和 cookies)
        $crumbHeaders = $this->getJenkinsCrumb();

        // 4. 组装请求 (做减法：去掉了 auth 和 Accept header，因为 client 里已经有了)
        $requestOptions = [
            'headers' => $crumbHeaders, // 只需要额外传入 crumb header
            'allow_redirects' => false, 
            'http_errors' => false, 
        ];

        if (!empty($buildParams)) {
            $requestOptions['form_params'] = $buildParams;
        }

        // 5. 发送请求 (直接传相对路径)
        try {
            $response = $this->client->post($triggerPath, $requestOptions);
            $statusCode = $response->getStatusCode();

            if ($statusCode === 201) {
                $queueUrl = $response->getHeaderLine('Location');
                $queueId = $this->extractQueueId($queueUrl);

                return [
                    'code' => 200,
                    'message' => '构建任务已成功触发并加入队列',
                    'data' => [
                        'job_name' => $jobName,
                        'queue_id' => $queueId,
                        'queue_url' => $queueUrl,
                    ]
                ];
            }

            if ($statusCode === 403) {
                return $this->errorResponse(403, 'Jenkins 拒绝访问 (权限不足或 CSRF 校验失败)');
            }

            if ($statusCode === 404) {
                return $this->errorResponse(404, 'Jenkins 找不到该任务', "请检查任务名称 [{$jobName}] 是否正确");
            }

            $body = (string) $response->getBody();
            return $this->errorResponse($statusCode, "Jenkins 返回异常状态码: {$statusCode}", $body);

        } catch (\GuzzleHttp\Exception\ConnectException $e) {
            return $this->errorResponse(503, '无法连接到 Jenkins 服务器', $e->getMessage());
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            $errorMsg = $e->hasResponse() ? (string) $e->getResponse()->getBody() : $e->getMessage();
            return $this->errorResponse(500, 'Jenkins 请求发生网络异常', $errorMsg);
        } catch (\Throwable $e) {
            return $this->errorResponse(500, '触发构建时发生未知系统错误', $e->getMessage());
        }
    }

    /**
     * 获取 Jenkins CSRF Crumb 令牌
     */
    private function getJenkinsCrumb(): array
    {
        try {
            // 使用相对路径，复用 client 的 base_uri、auth 和 cookies
            $response = $this->client->get('crumbIssuer/api/json', [
                'http_errors' => false,
            ]);

            if ($response->getStatusCode() === 200) {
                $data = json_decode((string) $response->getBody(), true);
                if (isset($data['crumbRequestField']) && isset($data['crumb'])) {
                    return [$data['crumbRequestField'] => $data['crumb']];
                }
            }
        } catch (\Throwable $e) {
            // 忽略获取失败
        }
        
        return [];
    }

    /**
     * 从 Location Header 中提取 Queue ID
     */
    private function extractQueueId(string $queueUrl): ?int
    {
        if (empty($queueUrl)) {
            return null;
        }
        if (preg_match('/\/item\/(\d+)\//', $queueUrl, $matches)) {
            return (int) $matches[1];
        }
        return null;
    }

    /**
     * 格式化错误返回结构
     */
    private function errorResponse(int $code, string $message, string $detail = ''): array
    {
        $result = [
            'code' => $code,
            'message' => $message,
        ];
        if (!empty($detail)) {
            $result['error_detail'] = $detail;
        }
        return $result;
    }

    private function checkConfig(): ?array
    {
        if (empty($this->baseUrl) || empty($this->username) || empty($this->apiToken)) {
            return ['error' => 'Jenkins 配置缺失，请检查 .env 文件'];
        }
        return null;
    }

    private function buildJobPath(string $group, string $project): string
    {
        if ($group === '' || $group === '_') return "/job/" . rawurlencode($project);
        $groups = explode('/', $group);
        $path = '';
        foreach ($groups as $g) $path .= "/job/" . rawurlencode($g);
        return $path . "/job/" . rawurlencode($project);
    }

    private function requestJson(string $path, array $query = []): array
    {
        if ($err = $this->checkConfig()) return $err;
        try {
            $response = $this->client->get($path . '/api/json', ['query' => $query]);
            $data = json_decode($response->getBody()->getContents(), true);
            return (json_last_error() !== JSON_ERROR_NONE) ? ['error' => 'JSON 解析失败'] : $data;
        } catch (GuzzleException $e) {
            return ['error' => 'Jenkins 请求失败: ' . $e->getMessage()];
        }
    }

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

    /**
     * 提取 Git URL (供 GitService 调用)
     */
    public function extractGitUrl(string $group, string $project): string
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
            $response = $this->client->get('/api/json', ['query' => ['tree' => $recursiveTree]]);
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

    /**
     * 获取 Job 的参数列表
     * 
     * @param string $group   分组/文件夹
     * @param string $project Job 名称
     * @param int|null $buildId 如果传 null，获取 Job 配置的默认参数；如果传 ID，获取某次构建的实际参数
     * @return array 返回关联数组，如 ['zone' => ['dev', 'prod'], 'custom_msg' => '默认值']
     */
    public function getParametersList(string $group, string $project, ?int $buildId = null): array
    {
        $this->normalizeJobParams($group, $project); 
        try {
            $jobPath = $this->buildJobPath($group, $project);
            if ($buildId === null) {
                return $this->getJobConfigParameters($jobPath);
            }
            return $this->getBuildHistoryParameterNames($jobPath, $buildId);
        } catch (\Throwable $e) {
            return ['error' => '获取参数列表失败: ' . $e->getMessage()];
        }
    }

    /**
     * 🚨 核心修复：精准解析 Job 配置中的参数定义
     */
    private function getJobConfigParameters(string $jobPath): array
    {
        // 💡 优化 1：增加 defaultParameterValue，兼容 String/Boolean 等非下拉框参数
        // 💡 优化 2：复用您自己写的 requestJson 方法，自动处理异常和 JSON 解析，代码更简洁
        $data = $this->requestJson($jobPath, [
            'tree' => 'property[parameterDefinitions[name,type,choices,defaultParameterValue[value]]]'
        ]);

        if (isset($data['error'])) {
            return $data; // 如果请求失败，直接向上层抛出错误数组
        }

        $result = [];

        if (isset($data['property']) && is_array($data['property'])) {
            foreach ($data['property'] as $property) {
                // 精准定位：只处理包含参数定义的 property
                if (!isset($property['parameterDefinitions']) || !is_array($property['parameterDefinitions'])) {
                    continue;
                }
                
                foreach ($property['parameterDefinitions'] as $param) {
                    $name = $param['name'] ?? '';
                    if ($name === '') continue; 

                    // 情况 A：标准下拉框 (ChoiceParameterDefinition)
                    if (isset($param['choices']) && is_array($param['choices']) && !empty($param['choices'])) {
                        $result[$name] = $param['choices'];
                        continue;
                    }

                    // 情况 B：动态参数插件 (Active Choices Parameter / Scriptler)
                    // 这类插件的 _class 通常包含 "ChoiceParameter"，且可能没有 choices 字段或为空
                    $class = $param['_class'] ?? '';
                    if (str_contains($class, 'ChoiceParameter') || str_contains($class, 'CascadeChoiceParameter')) {
                        // 对于动态参数，由于无法在配置阶段预知选项，给空数组让 Controller 直接放行校验
                        $result[$name] = []; 
                        continue;
                    }

                    // 情况 C：普通参数 (String, Boolean, Text 等)
                    // 提取默认值，如果没有默认值则给空数组（Controller 遇到空数组会跳过严格校验）
                    if (isset($param['defaultParameterValue']['value'])) {
                        $result[$name] = $param['defaultParameterValue']['value'];
                    } else {
                        $result[$name] = [];
                    }
                }
            }
        }
        
        return $result;
    }
    private function getBuildHistoryParameterNames(string $jobPath, int $buildId): array
    {
        $buildPath = ($buildId === 0) ? 'lastBuild' : (string)$buildId;
        $url = sprintf('%s/%s/api/json', $jobPath, $buildPath);
        $response = $this->client->get($url, ['query' => ['tree' => 'actions[parameters[name]]']]);
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
        return $paramNames;
    }


    public function getBuildStatus(string $group, string $project, int $buildId): array
    {
        $jobPath = $this->buildJobPath($group, $project);
        $data = $this->requestJson("{$jobPath}/{$buildId}", ['tree' => 'number,result,building,timestamp,duration']);
        if (isset($data['error'])) return $data;
        $status = ($data['building'] ?? false) ? 'BUILDING' : ($data['result'] ?? 'UNKNOWN');
        return [$status];
    }

    public function getBuildListByType(string $group, string $project, string $type = 'build'): array
    {
        $builds = $this->fetchRawBuilds($group, $project);
        if (isset($builds['error'])) return $builds;

        return array_map(function (array $build) use ($type): string {
            $number = (string)($build['number'] ?? '');
            return match ($type) {
                'build_id'   => $number,
                'build_time' => sprintf("#%s [%s]", $number, ($ts = (int)(($build['timestamp'] ?? 0) / 1000)) > 0 ? date('Y-m-d H:i:s', $ts) : '未知'),
                default      => "#{$number}",
            };
        }, $builds);
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

    public function getConsoleOutput(string $group, string $project, int $buildNumber): string
    {
        $this->normalizeJobParams($group, $project); 
        $jobPath = $this->buildJobPath($group, $project);
        $text = $this->requestText("{$jobPath}/{$buildNumber}/consoleText");
        if (empty($text)) return "获取日志失败或日志为空";
        
        $lines = explode("\n", $text);
        $html = '<pre style="font-family: monospace; font-size: 12px;  color: #000000; padding: 15px; border-radius: 5px; overflow-x: auto;">';
        foreach ($lines as $line) $html .= htmlspecialchars($line) . "<br>";
        return $html . '</pre>';
    }
}