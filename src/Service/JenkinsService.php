<?php
namespace App\Service;

use GuzzleHttp\Client;

class JenkinsService
{
    private Client $client;
    private string $baseUrl;

    public function __construct(array $config)
    {
        $this->baseUrl = rtrim($config['url'], '/');  // 注意这里是 url
        $this->client = new Client([
            'auth'    => [$config['user'], $config['token']],
            'headers' => ['Content-Type' => 'application/json'],
            'timeout' => 30,
        ]);
    }

    // ---------- 所有 Job 列表 ----------
    public function getAllJobs(): array
    {
        $url = "{$this->baseUrl}/api/json?tree=jobs[name,jobs[name,jobs[name]]]";
        $resp = $this->client->get($url);
        $data = json_decode($resp->getBody(), true);
        return $this->flattenJobs($data['jobs'] ?? []);
    }

    private function flattenJobs(array $jobs, string $prefix = ''): array
    {
        $result = [];
        foreach ($jobs as $job) {
            $name = $job['name'];
            $fullName = $prefix ? "{$prefix}/{$name}" : $name;
            if (isset($job['jobs']) && is_array($job['jobs'])) {
                $result = array_merge($result, $this->flattenJobs($job['jobs'], $fullName));
            } else {
                $result[] = $fullName;
            }
        }
        return $result;
    }

    // ---------- 解析路径是 folder 还是 job ----------
    public function resolvePath(string $path): ?array
    {
        $jobUrl = $this->getJobUrl($path);
        try {
            $resp = $this->client->get("{$jobUrl}/api/json");
            $info = json_decode($resp->getBody(), true);
            $class = $info['_class'] ?? '';
            if (preg_match('/(WorkflowJob|WorkflowMultiBranchProject|FreeStyleProject)/', $class)) {
                return ['type' => 'job', 'fullName' => $path];
            }
            if (str_contains($class, 'Folder')) {
                return ['type' => 'folder', 'fullName' => $path];
            }
        } catch (\Exception $e) {}

        // 尝试解析为 folder/job
        $parts = explode('/', $path);
        if (count($parts) >= 2) {
            $job = array_pop($parts);
            $folder = implode('/', $parts);
            $folderUrl = $this->getJobUrl($folder);
            try {
                $resp = $this->client->get("{$folderUrl}/api/json?tree=jobs[name,_class]");
                $data = json_decode($resp->getBody(), true);
                foreach ($data['jobs'] ?? [] as $item) {
                    if ($item['name'] === $job && preg_match('/(WorkflowJob|WorkflowMultiBranchProject|FreeStyleProject)/', $item['_class'])) {
                        return ['type' => 'job', 'fullName' => "{$folder}/{$job}"];
                    }
                }
            } catch (\Exception $e) {}
        }
        return null;
    }

    // ---------- 获取 Job 的 Git 远程地址（用于自动映射）----------
    public function getGitRemotes(string $jobPath): array
    {
        $jobUrl = $this->getJobUrl($jobPath);
        $resp = $this->client->get("{$jobUrl}/api/json?tree=scm[sources[remote],userRemoteConfigs[url]],definition[scm[sources[remote],userRemoteConfigs[url]]]");
        $data = json_decode($resp->getBody(), true);
        $remotes = [];
        
        // 情况1：普通 Pipeline / FreeStyle (scm.sources.remote)
        if (isset($data['scm']['sources'])) {
            foreach ($data['scm']['sources'] as $src) {
                if (!empty($src['remote'])) $remotes[] = $src['remote'];
            }
        }
        
        // 情况2：GitSCM 的 userRemoteConfigs (较老的 Git 插件)
        if (isset($data['scm']['userRemoteConfigs'])) {
            foreach ($data['scm']['userRemoteConfigs'] as $cfg) {
                if (!empty($cfg['url'])) $remotes[] = $cfg['url'];
            }
        }
        
        // 情况3：Multibranch Pipeline / Organization Folder (definition.scm)
        if (isset($data['definition']['scm']['sources'])) {
            foreach ($data['definition']['scm']['sources'] as $src) {
                if (!empty($src['remote'])) $remotes[] = $src['remote'];
            }
        }
        if (isset($data['definition']['scm']['userRemoteConfigs'])) {
            foreach ($data['definition']['scm']['userRemoteConfigs'] as $cfg) {
                if (!empty($cfg['url'])) $remotes[] = $cfg['url'];
            }
        }
        
        return $remotes;
    }

    // ---------- 参数相关 ----------
    public function getParameters(string $jobPath, ?int $buildId = null): array
    {
        if ($buildId === null) {
            return $this->getCurrentParameters($jobPath);
        }
        if ($buildId === 0) {
            return $this->getLatestBuildParametersList($jobPath);
        }
        return $this->getBuildParameters($jobPath, $buildId);
    }

    private function getCurrentParameters(string $jobPath): array
    {
        $jobUrl = $this->getJobUrl($jobPath);
        // 拉取所有可能存选项的字段
        $resp = $this->client->get(
            "{$jobUrl}/api/json?tree=property[parameterDefinitions[name,choices,choiceType,choiceListMetadata[value],value,defaultValue,multiSelectDelimiter]]"
        );
        $data = json_decode($resp->getBody(), true);

        $params = ['zone' => [], 'branches' => []];

        foreach ($data['property'] ?? [] as $prop) {
            foreach ($prop['parameterDefinitions'] ?? [] as $def) {
                $name = $def['name'] ?? '';
                $choices = [];

                // 1. 标准 ChoiceParameterDefinition (choices 是数组)
                if (isset($def['choices']) && is_array($def['choices'])) {
                    $choices = $def['choices'];
                }
                // 2. Active Choices 插件 (choiceListMetadata 里存选项)
                elseif (isset($def['choiceListMetadata']) && is_array($def['choiceListMetadata'])) {
                    $choices = array_column($def['choiceListMetadata'], 'value');
                }
                // 3. Extended Choice Parameter (value 字段用换行分隔)
                elseif (isset($def['value']) && is_string($def['value'])) {
                    $choices = array_filter(array_map('trim', explode("\n", $def['value'])));
                }
                // 4. 某些插件把 defaultValue 当唯一选项 (不太常见，但可兜底)
                elseif (isset($def['defaultValue']) && !empty($def['defaultValue'])) {
                    $choices = [$def['defaultValue']];
                }

                if (in_array($name, ['zone', 'branches'])) {
                    $params[$name] = array_values($choices); // 确保索引连续
                }
            }
        }

        return $params;
    }

    private function getLatestBuildParametersList(string $jobPath): array
    {
        $lastBuild = $this->getLastBuild($jobPath);
        if (!$lastBuild) return [];

        $resp = $this->client->get("{$lastBuild['url']}/api/json?tree=actions[parameters[name]]");
        $data = json_decode($resp->getBody(), true);
        $names = [];
        foreach ($data['actions'] ?? [] as $action) {
            foreach ($action['parameters'] ?? [] as $p) {
                $names[] = $p['name'];
            }
        }
        // 去重并重建索引
        return array_values(array_unique($names));
    }

    private function getBuildParameters(string $jobPath, int $buildId): array
    {
        $buildUrl = $this->getBuildUrl($jobPath, $buildId);
        $resp = $this->client->get("{$buildUrl}/api/json?tree=actions[parameters[name]]");
        $data = json_decode($resp->getBody(), true);
        $names = [];
        foreach ($data['actions'] ?? [] as $action) {
            foreach ($action['parameters'] ?? [] as $p) {
                $names[] = $p['name'];
            }
        }
        return array_values(array_unique($names));
    }

    // ---------- 触发构建 ----------
    public function triggerBuild(string $jobPath, array $parameters): array
    {
        $jobUrl = $this->getJobUrl($jobPath);
        $buildUrl = "{$jobUrl}/buildWithParameters";
        $response = $this->client->post($buildUrl, ['query' => $parameters]);
        $location = $response->getHeaderLine('Location');
        preg_match('#/(\d+)/?$#', $location, $matches);
        return ['queueId' => $matches[1] ?? null, 'location' => $location];
    }

    // ---------- 构建列表、状态、控制台 ----------
    public function getBuildIds(string $jobPath): array
    {
        $jobUrl = $this->getJobUrl($jobPath);
        $resp = $this->client->get("{$jobUrl}/api/json?tree=builds[id]");
        $data = json_decode($resp->getBody(), true);
        return array_column($data['builds'] ?? [], 'id');
    }

    public function getBuildStatus(string $jobPath, int $buildId): string
    {
        $buildUrl = $this->getBuildUrl($jobPath, $buildId);
        $resp = $this->client->get("{$buildUrl}/api/json?tree=result");
        $data = json_decode($resp->getBody(), true);
        return $data['result'] ?? 'UNKNOWN';
    }

    public function getConsoleOutput(string $jobPath, int $buildId): string
    {
        $buildUrl = $this->getBuildUrl($jobPath, $buildId);
        $resp = $this->client->get("{$buildUrl}/consoleText");
        return $resp->getBody()->getContents();
    }

    public function getSuccessfulBuilds(string $jobPath): array
    {
        $jobUrl = $this->getJobUrl($jobPath);
        $resp = $this->client->get("{$jobUrl}/api/json?tree=builds[id,result,timestamp]");
        $builds = json_decode($resp->getBody(), true)['builds'] ?? [];
        return array_filter($builds, fn($b) => ($b['result'] ?? '') === 'SUCCESS');
    }

    // ---------- 工具方法 ----------
    public function getJobUrl(string $path): string
    {
        $parts = explode('/', $path);
        $segments = array_map(fn($p) => "job/{$p}", $parts);
        return $this->baseUrl . '/' . implode('/', $segments);
    }

    private function getBuildUrl(string $jobPath, int $buildId): string
    {
        return $this->getJobUrl($jobPath) . "/{$buildId}";
    }

    private function getLastBuild(string $jobPath): ?array
    {
        $jobUrl = $this->getJobUrl($jobPath);
        $resp = $this->client->get("{$jobUrl}/api/json?tree=lastBuild[url]");
        $data = json_decode($resp->getBody(), true);
        return $data['lastBuild'] ?? null;
    }
}