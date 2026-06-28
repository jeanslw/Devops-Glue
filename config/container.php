<?php

use GuzzleHttp\Client;
use App\Service\GitlabService;
use App\Service\JenkinsService;
use App\Service\GitService;
use Psr\SimpleCache\CacheInterface;
use function DI\autowire;
use function DI\create;

/**
 * 🌟 轻量级 PSR-16 文件缓存实现（无需额外依赖）
 */
class FileCache implements CacheInterface
{
    private string $cacheDir;

    public function __construct(string $cacheDir)
    {
        $this->cacheDir = rtrim($cacheDir, '/');
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0755, true);
        }
    }

    public function get($key, $default = null): mixed
    {
        $file = $this->getFilePath($key);
        if (!file_exists($file)) return $default;
        
        $data = @unserialize(file_get_contents($file));
        if ($data === false || !isset($data['expires'], $data['value'])) return $default;
        
        if ($data['expires'] !== 0 && time() > $data['expires']) {
            @unlink($file);
            return $default;
        }
        return $data['value'];
    }

    public function set($key, $value, $ttl = null): bool
    {
        $expires = ($ttl === null || $ttl === 0) ? 0 : time() + (int)$ttl;
        $data = serialize(['value' => $value, 'expires' => $expires]);
        return (bool)@file_put_contents($this->getFilePath($key), $data, LOCK_EX);
    }

    public function delete($key): bool { return @unlink($this->getFilePath($key)); }
    
    public function clear(): bool { 
        foreach (glob($this->cacheDir . '/*.cache') as $f) @unlink($f); 
        return true; 
    }
    
    public function getMultiple($keys, $default = null): iterable { 
        $result = [];
        foreach ((array)$keys as $k) $result[$k] = $this->get($k, $default);
        return $result;
    }
    
    public function setMultiple($values, $ttl = null): bool {
        foreach ((array)$values as $k => $v) $this->set($k, $v, $ttl);
        return true;
    }
    
    public function deleteMultiple($keys): bool {
        foreach ((array)$keys as $k) $this->delete($k);
        return true;
    }
    
    public function has($key): bool { return $this->get($key) !== null; }

    private function getFilePath(string $key): string
    {
        return $this->cacheDir . '/' . md5($key) . '.cache';
    }
}

return [
    // PSR-16 缓存服务
    CacheInterface::class => create(FileCache::class)
        ->constructor($_ENV['CACHE_DIR'] ?? __DIR__ . '/../var/cache/jenkins'),

    // Guzzle HTTP Client
    Client::class => create(Client::class)->constructor([
        'timeout' => 30,
        'verify'  => false,
    ]),

    // GitlabService
    GitlabService::class => autowire()
        ->constructorParameter('baseUrl', $_ENV['GIT_BASE_URL'] ?? $_SERVER['GIT_BASE_URL'] ?? '')
        ->constructorParameter('token',   $_ENV['GIT_TOKEN']   ?? $_SERVER['GIT_TOKEN']   ?? ''),

    // ==========================================
    // JenkinsService 
    // ==========================================
    JenkinsService::class => function (\Psr\Container\ContainerInterface $c) {
        $settings = $c->has('settings') ? $c->get('settings') : [];
        $jenkins  = $settings['jenkins'] ?? [];

        return new \App\Service\JenkinsService(
            $c->get(\GuzzleHttp\Client::class),                          // 1. Client
            $jenkins['url']   ?? $_ENV['JENKINS_BASE_URL'] ?? '',        // 2. baseUrl
            $jenkins['user']  ?? $_ENV['JENKINS_USER']     ?? '',        // 3. username
            $jenkins['token'] ?? $_ENV['JENKINS_TOKEN']    ?? '',        // 4. apiToken
            $c->has(\Psr\SimpleCache\CacheInterface::class)              // 5. Cache
                ? $c->get(\Psr\SimpleCache\CacheInterface::class)
                : null
        );
    },

    // ==========================================
    // GitService
    // ==========================================
    GitService::class => function (\Psr\Container\ContainerInterface $c) {
        return new \App\Service\GitService(
            $c->get(\App\Service\GitlabService::class),                  // 1. GitlabService (查 GitLab API)
            $c->get(\App\Service\JenkinsService::class),                 // 2. JenkinsService (查 Jenkins Job 配置拿 Git URL)
            $c->has(\Psr\SimpleCache\CacheInterface::class)              // 3. Cache
                ? $c->get(\Psr\SimpleCache\CacheInterface::class)
                : null
        );
    },
    // ==========================================
    // Harbor Client & Service
    // ==========================================
    \App\Service\HarborService::class => function (\Psr\Container\ContainerInterface $c) {
        $settings = $c->has('settings') ? $c->get('settings') : [];
        $harbor   = $settings['harbor'] ?? [];

        $baseUrl  = $harbor['url']      ?? $_ENV['HARBOR_BASE_URL'] ?? '';
        $username = $harbor['username']  ?? $_ENV['HARBOR_USER']     ?? 'admin';
        $password = $harbor['password']  ?? $_ENV['HARBOR_PASSWORD'] ?? '';

        // 创建 Harbor 专属的 Guzzle Client (自动带上 Basic Auth 和 Base URI)
        $harborClient = new \GuzzleHttp\Client([
            'base_uri' => rtrim($baseUrl, '/') . '/',
            'timeout'  => 30,
            'verify'   => false, // 兼容内网自签名证书
            'auth'     => [$username, $password], // Harbor 使用 Basic Auth
            'headers'  => [
                'Accept'       => 'application/json',
                'Content-Type' => 'application/json',
            ],
        ]);

        return new \App\Service\HarborService($harborClient);
    },
];