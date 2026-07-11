<?php
namespace App\Service;

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Client;

class JenkinsService
{
    private Client $client;
    private string $baseUrl;
    private ?Logger $logger = null;
    private ?string $cachedVersion = null;

    public function __construct(array $config)
    {
        $this->baseUrl = rtrim($config['url'], '/');
        $this->client = new Client([
            'auth'    => [$config['user'], $config['token']],
            'headers' => ['Content-Type' => 'application/json'],
            'cookies' => true,   // 启用 Cookie 存储（用于 CSRF crumb）
            'timeout' => 5, 'connect_timeout' => 3,
        ]);
    }

    public function setLogger(Logger $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * 获取 Jenkins 版本号（惰性缓存）
     */
    public function getVersion(): ?string
    {
        if ($this->cachedVersion !== null) {
            return $this->cachedVersion;
        }
        try {
            $resp = $this->client->get("{$this->baseUrl}/api/json");
            $data = json_decode($resp->getBody(), true);
            $header = $resp->getHeaderLine('X-Jenkins');
            $this->cachedVersion = $data['version'] ?? ($header ?: null);
        } catch (\Exception $e) {
            $this->logger?->debug('Jenkins 版本获取失败', ['error' => $e->getMessage()]);
            $this->cachedVersion = '';
        }
        return $this->cachedVersion ?: null;
    }

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
        } catch (\Exception $e) {
            $this->logger?->debug('Jenkins resolvePath: 直接路径解析失败', [
                'path'  => $path,
                'error' => $e->getMessage(),
            ]);
        }

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
            } catch (\Exception $e) {
                $this->logger?->debug('Jenkins resolvePath: Folder 内查找失败', [
                    'folder' => $folder,
                    'job'    => $job,
                    'error'  => $e->getMessage(),
                ]);
            }
        }
        return null;
    }

    public function getGitRemotes(string $jobPath): array
    {
        $jobUrl = $this->getJobUrl($jobPath);
        $resp = $this->client->get("{$jobUrl}/api/json?tree=scm[sources[remote],userRemoteConfigs[url]],definition[scm[sources[remote],userRemoteConfigs[url]]]");
        $data = json_decode($resp->getBody(), true);
        $remotes = [];
        if (isset($data['scm']['sources'])) {
            foreach ($data['scm']['sources'] as $src) {
                if (!empty($src['remote'])) $remotes[] = $src['remote'];
            }
        }
        if (isset($data['scm']['userRemoteConfigs'])) {
            foreach ($data['scm']['userRemoteConfigs'] as $cfg) {
                if (!empty($cfg['url'])) $remotes[] = $cfg['url'];
            }
        }
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
        $resp = $this->client->get("{$jobUrl}/api/json?tree=property[parameterDefinitions[*]]");
        $data = json_decode($resp->getBody(), true);
        $params = [];
        foreach ($data['property'] ?? [] as $prop) {
            foreach ($prop['parameterDefinitions'] ?? [] as $def) {
                $name = $def['name'] ?? '';
                $choices = [];
                if (isset($def['choices']) && is_array($def['choices'])) {
                    $choices = $def['choices'];
                } elseif (isset($def['choiceListMetadata']) && is_array($def['choiceListMetadata'])) {
                    $choices = array_column($def['choiceListMetadata'], 'value');
                } elseif (isset($def['value']) && is_string($def['value'])) {
                    $choices = array_filter(array_map('trim', explode("\n", $def['value'])));
                } elseif (isset($def['defaultValue'])) {
                    $choices = [$def['defaultValue']];
                }
                if (!empty($name)) {
                    $params[$name] = array_values($choices);
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

    public function triggerBuild(string $jobPath, array $parameters): array
    {
        $jobUrl = $this->getJobUrl($jobPath);
        $buildUrl = "{$jobUrl}/buildWithParameters";

        try {
            $response = $this->client->post($buildUrl, ['query' => $parameters]);
        } catch (ClientException $e) {
            if ($e->getResponse() && $e->getResponse()->getStatusCode() === 403) {
                $crumb = $this->getCrumb();
                if ($crumb) {
                    try {
                        $response = $this->client->post($buildUrl, [
                            'query'   => $parameters,
                            'headers' => [$crumb['field'] => $crumb['value']]
                        ]);
                    } catch (ClientException $e2) {
                        $body = $e2->getResponse() ? $e2->getResponse()->getBody()->getContents() : '无响应';
                        $this->logger?->error('触发构建失败(CSRF 重试后仍 403)', [
                            'job'    => $jobPath,
                            'params' => array_keys($parameters),
                            'body'   => $body,
                        ]);
                        throw new \RuntimeException(
                            "触发构建失败(权限不足或 Job 配置错误) HTTP 403，Jenkins 响应: " . $body
                        );
                    }
                } else {
                    // 无法获取 CSRF crumb — 可能是 Jenkins 未启用 CSRF 保护但真的权限不足
                    $body = $e->getResponse()->getBody()->getContents();
                    $this->logger?->error('触发构建失败(无法获取 CSRF crumb)', [
                        'job'  => $jobPath,
                        'body' => $body,
                    ]);
                    throw new \RuntimeException(
                        "触发构建失败(权限不足) HTTP 403，且无法获取 CSRF crumb，Jenkins 响应: " . $body
                    );
                }
            } else {
                throw $e;
            }
        }

        $location = $response->getHeaderLine('Location');
        preg_match('#/(\d+)/?$#', $location, $matches);
        $queueId = $matches[1] ?? null;
        return [
            'queueId'  => $queueId,
            'queueUrl' => $location ?: ($this->baseUrl . '/queue/item/' . $queueId),
        ];
    }

    private function getCrumb(): ?array
    {
        try {
            $resp = $this->client->get("{$this->baseUrl}/crumbIssuer/api/json");
            $data = json_decode($resp->getBody(), true);
            return [
                'field' => $data['crumbRequestField'] ?? 'Jenkins-Crumb',
                'value' => $data['crumb'] ?? ''
            ];
        } catch (\Exception $e) {
            $this->logger?->warning('获取 Jenkins CSRF crumb 失败', ['error' => $e->getMessage()]);
            return null;
        }
    }

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

    public function getBuildTimestamp(string $jobPath, int $buildId): string
    {
        try {
            $buildUrl = $this->getBuildUrl($jobPath, $buildId);
            $resp = $this->client->get("{$buildUrl}/api/json?tree=timestamp");
            $data = json_decode($resp->getBody(), true);
            $ts = (int) ($data['timestamp'] ?? 0);
            return $ts > 0 ? date('Y-m-d H:i:s', (int) ($ts / 1000)) : '';
        } catch (\Exception $e) {
            return '';
        }
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
        $success = [];
        foreach ($builds as $b) {
            if (($b['result'] ?? '') === 'SUCCESS') {
                $success[] = $b;
            }
        }
        return $success;
    }

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
