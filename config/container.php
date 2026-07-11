<?php

use App\Config\AppConfig;
use App\Service\JenkinsService;
use App\Service\GitService;
use App\Service\MapService;
use App\Service\HarborService;
use App\Service\Git\ProviderRegistry;
use App\Service\Git\GitProviderFactory;
use App\Service\Build\BuildProviderRegistry;
use App\Service\Build\JenkinsBuildProvider;
use App\Service\Build\GitlabCiBuildProvider;
use App\Controller\JenkinsController;
use App\Controller\MainController;
use App\Controller\GitController;
use App\Controller\HarborController;
use App\Controller\AdminController;
use App\Controller\BuildController;
use App\Middleware\CorsMiddleware;
use App\Service\Logger;
use GuzzleHttp\Client;
use Psr\Http\Message\ResponseFactoryInterface;
use Slim\Psr7\Factory\ResponseFactory;

$settings = require __DIR__ . '/settings.php';

return [
    // PSR-17 工厂
    ResponseFactoryInterface::class => function () {
        return new ResponseFactory();
    },

    // 全局配置
    AppConfig::class => function () use ($settings) {
        return new AppConfig($settings);
    },

    // ---------- 基础设施 ----------

    // 日志服务
    Logger::class => function (\Psr\Container\ContainerInterface $c) {
        $config = $c->get(AppConfig::class);
        return new Logger(
            $config->getLogPath(),
            $config->getAppEnv() === 'production' ? 'info' : 'debug'
        );
    },

    // CORS 中间件
    CorsMiddleware::class => function (\Psr\Container\ContainerInterface $c) {
        $config = $c->get(AppConfig::class);
        return new CorsMiddleware($config->getCorsConfig());
    },

    // ---------- Git Provider 注册表 ----------

    ProviderRegistry::class => function (\Psr\Container\ContainerInterface $c) {
        $config = $c->get(AppConfig::class);
        $logger  = $c->get(Logger::class);
        $registry = new ProviderRegistry();
        $registry->setLogger($logger);

        // ---- 内置平台（仅已配置的才注册，未配置的不可用也不展示）----

        // GitLab（自建，base_url 为空则跳过）
        if ($config->isPlatformConfigured('gitlab')) {
            $gitlabCfg = $config->getGitlabConfig();
            $registry->register(
                'gitlab',
                fn(string $url) => str_contains($url, 'gitlab'),
                function () use ($gitlabCfg, $logger) {
                    return new \App\Service\Git\GitlabService(
                        $gitlabCfg['base_url'] ?? '',
                        $gitlabCfg['token'] ?? '',
                        $logger
                    );
                }
            );
        }

        // Gitee（SaaS，默认 base_url 始终存在）
        if ($config->isPlatformConfigured('gitee')) {
            $giteeCfg = $config->getGiteeConfig();
            $registry->register(
                'gitee',
                fn(string $url) => str_contains($url, 'gitee.com') || str_contains($url, 'gitee'),
                function () use ($giteeCfg, $logger) {
                    return new \App\Service\Git\GiteeService(
                        $giteeCfg['base_url'] ?? 'https://gitee.com/api/v5',
                        $giteeCfg['token'] ?? '',
                        $logger
                    );
                }
            );
        }

        // GitHub（SaaS，默认 base_url 始终存在）
        if ($config->isPlatformConfigured('github')) {
            $githubCfg = $config->getGithubConfig();
            $registry->register(
                'github',
                fn(string $url) => str_contains($url, 'github.com') || str_contains($url, 'github'),
                function () use ($githubCfg, $logger) {
                    return new \App\Service\Git\GithubService(
                        $githubCfg['base_url'] ?? 'https://api.github.com',
                        $githubCfg['token'] ?? '',
                        $logger
                    );
                }
            );
        }

        // Gitea（自建，base_url 为空则跳过）
        if ($config->isPlatformConfigured('gitea')) {
            $giteaCfg = $config->getGiteaConfig();
            $registry->register(
                'gitea',
                fn(string $url) => str_contains($url, 'gitea'),
                function () use ($giteaCfg, $logger) {
                    return new \App\Service\Git\GiteaService(
                        $giteaCfg['base_url'] ?? '',
                        $giteaCfg['token'] ?? '',
                        $logger
                    );
                }
            );
        }

        // ---- 自定义平台 ----
        foreach ($config->getCustomGitProviders() as $provider) {
            $class  = $provider['class'] ?? '';
            $cfg    = $provider['config'] ?? [];
            $name   = $cfg['name'] ?? '';
            $matcher= $cfg['matcher'] ?? null;

            if (empty($class) || empty($name)) {
                $logger->warning('跳过无效的自定义 Provider 配置', ['provider' => $provider]);
                continue;
            }

            if (!is_callable($matcher)) {
                $logger->warning("自定义 Provider [{$name}] 缺少有效的 matcher 回调", ['class' => $class]);
                continue;
            }

            $registry->register(
                $name,
                $matcher,
                function () use ($class, $cfg, $logger) {
                    if (!class_exists($class)) {
                        throw new \RuntimeException("自定义 Provider 类不存在: {$class}");
                    }
                    return new $class($cfg, $logger);
                }
            );
        }

        return $registry;
    },

    // GitProviderFactory（向后兼容封装）
    GitProviderFactory::class => function (\Psr\Container\ContainerInterface $c) {
        $factory = new GitProviderFactory();
        $factory->setRegistry($c->get(ProviderRegistry::class));
        return $factory;
    },

    // ---------- Build Provider 注册表 ----------

    BuildProviderRegistry::class => function (\Psr\Container\ContainerInterface $c) {
        $config   = $c->get(AppConfig::class);
        $logger   = $c->get(Logger::class);
        $registry = new BuildProviderRegistry();
        $registry->setLogger($logger);

        // Jenkins（始终注册，向后兼容）
        $registry->register('jenkins', function () use ($c, $logger) {
            return new JenkinsBuildProvider($c->get(JenkinsService::class), $logger);
        });

        // GitLab CI（GitLab 已配置且有 token 时注册）
        if ($config->isPlatformConfigured('gitlab')) {
            $glCfg = $config->getGitlabConfig();
            if (!empty($glCfg['base_url']) && !empty($glCfg['token'])) {
                $registry->register('gitlab_ci', function () use ($glCfg, $logger) {
                    return new GitlabCiBuildProvider($glCfg['base_url'], $glCfg['token'], $logger);
                });
            }
        }

        return $registry;
    },

    // ---------- Jenkins ----------
    JenkinsService::class => function (\Psr\Container\ContainerInterface $c) {
        $service = new JenkinsService(
            $c->get(AppConfig::class)->getJenkinsConfig()
        );
        try {
            $service->setLogger($c->get(Logger::class));
        } catch (\Throwable $e) {
            // Logger 不可用时静默降级
        }
        return $service;
    },

    // Map 服务
    MapService::class => function (\Psr\Container\ContainerInterface $c) {
        $config = $c->get(AppConfig::class);
        $service = new MapService(
            $c->get(JenkinsService::class),
            $c->get(ProviderRegistry::class),
            $config->getJobGitMap(),
            $config->getGitlabConfig(),
            __DIR__ . '/gitlab_id_cache.php',
            $config->getDefaultGitPlatform()
        );
        try {
            $service->setLogger($c->get(Logger::class));
        } catch (\Throwable $e) {
            // Logger 不可用时静默降级
        }
        return $service;
    },

    // Git 服务
    GitService::class => function (\Psr\Container\ContainerInterface $c) {
        $service = new GitService(
            $c->get(MapService::class),
            $c->get(ProviderRegistry::class)
        );
        try {
            $service->setLogger($c->get(Logger::class));
        } catch (\Throwable $e) {
            // Logger 不可用时静默降级
        }
        return $service;
    },

    // Jenkins 控制器
    JenkinsController::class => function (\Psr\Container\ContainerInterface $c) {
        return new JenkinsController(
            $c->get(JenkinsService::class),
            $c->get(GitService::class)
        );
    },

    // Main 控制器
    MainController::class => function (\Psr\Container\ContainerInterface $c) {
        return new \App\Controller\MainController(
            $c->get(JenkinsService::class),
            $c->get(MapService::class),
            $c->get(AppConfig::class),
            $c->get(HarborService::class)
        );
    },

    // Admin 控制器
    AdminController::class => function (\Psr\Container\ContainerInterface $c) {
        return new AdminController($c->get(AppConfig::class));
    },

    // Build 控制器
    BuildController::class => function (\Psr\Container\ContainerInterface $c) {
        return new BuildController(
            $c->get(BuildProviderRegistry::class),
            $c->get(AppConfig::class),
            $c->get(HarborService::class)
        );
    },

    // Git 控制器
    GitController::class => function (\Psr\Container\ContainerInterface $c) {
        return new GitController($c->get(GitService::class));
    },

    // ---------- Harbor 模块 ----------

    // Harbor Guzzle 客户端
    'harborClient' => function (\Psr\Container\ContainerInterface $c) {
        $config = $c->get(AppConfig::class);
        $harbor = $config->getHarborConfig();
        return new Client([
            'base_uri' => $harbor['url'] ?? '',
            'auth'     => [
                $harbor['username'] ?? 'admin',
                $harbor['password'] ?? '',
            ],
            'headers'  => ['Accept' => 'application/json'],
            'timeout'  => 5,
            'connect_timeout' => 3,
        ]);
    },

    HarborService::class => function (\Psr\Container\ContainerInterface $c) {
        $service = new HarborService($c->get('harborClient'));
        try {
            $service->setLogger($c->get(Logger::class));
        } catch (\Throwable $e) {
            // Logger 不可用时静默降级
        }
        return $service;
    },

    HarborController::class => function (\Psr\Container\ContainerInterface $c) {
        return new HarborController($c->get(HarborService::class));
    },
];
