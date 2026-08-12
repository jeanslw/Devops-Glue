<?php

use App\Config\AppConfig;
use App\Service\JenkinsService;
use App\Service\GitService;
use App\Service\GitRemoteResolver;
use App\Service\AutoDiscover;
use App\Service\HarborService;
use App\Service\MappingManager;
use App\Service\I18nService;
use App\Service\TokenService;
use App\Service\AdminAuthService;
use App\Service\AdminUserRepository;
use App\Service\Git\ProviderRegistry;
use App\Service\Git\GitProviderFactory;
use App\Service\Build\BuildProviderRegistry;
use App\Service\Build\JenkinsBuildProvider;
use App\Service\Build\GitlabCiBuildProvider;
use App\Controller\MainController;
use App\Controller\GitController;
use App\Controller\HarborController;
use App\Controller\AdminController;
use App\Controller\BuildController;
use App\Middleware\CorsMiddleware;
use App\Middleware\AuthMiddleware;
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

    // PDO 数据库连接（根据环境变量直接构造，不再依赖 Database::getPdo 单例）
    \PDO::class => function () {
        $driver = strtolower($_ENV['DB_DRIVER'] ?? 'sqlite');
        if (!in_array($driver, ['sqlite', 'mysql'])) {
            throw new \RuntimeException('DB_DRIVER must be sqlite or mysql');
        }
        if ($driver === 'mysql') {
            $host = $_ENV['DB_HOST'] ?? '127.0.0.1';
            $port = $_ENV['DB_PORT'] ?? '3306';
            $db   = $_ENV['DB_NAME'] ?? 'devops_glue';
            $user = $_ENV['DB_USER'] ?? 'root';
            $pass = $_ENV['DB_PASS'] ?? '';
            $charset = $_ENV['DB_CHARSET'] ?? 'utf8mb4';
            $dsn = "mysql:host={$host};port={$port};dbname={$db};charset={$charset}";
            $pdo = new \PDO($dsn, $user, $pass, [\PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$charset}"]);
        } else {
            $path = $_ENV['DB_PATH'] ?? __DIR__ . '/../../config/data/data.db';
            $dir = dirname($path);
            if (!is_dir($dir)) @mkdir($dir, 0777, true);
            $pdo = new \PDO('sqlite:' . $path);
            try { $pdo->exec('PRAGMA journal_mode=WAL'); } catch (\Throwable $e) { }
            try { $pdo->exec('PRAGMA foreign_keys=ON'); } catch (\Throwable $e) { }
        }
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        return $pdo;
    },

    // 全局配置
    AppConfig::class => function (\Psr\Container\ContainerInterface $c) use ($settings) {
        return new AppConfig($settings, $c->get(\PDO::class));
    },

    // ---------- 基础设施 ----------

    // 映射查询（数据层）
    MappingManager::class => function (\Psr\Container\ContainerInterface $c) {
        return new MappingManager($c->get(AppConfig::class));
    },

    // 自动发现
    AutoDiscover::class => function (\Psr\Container\ContainerInterface $c) {
        return new AutoDiscover(
            $c->get(JenkinsService::class),
            $c->get(ProviderRegistry::class),
            $c->get(AppConfig::class),
            $c->get(MappingManager::class),
            $c->get(Logger::class),
            $c->get('gitlabHttpClient')
        );
    },

    // 国际化服务
    I18nService::class => function (\Psr\Container\ContainerInterface $c) {
        $langDir = __DIR__ . '/../lang';
        return new I18nService($langDir, 'zh_CN');
    },

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

    // Token 验证服务（统一封装 cache token / 旧版 base64 token 验证逻辑）
    TokenService::class => function (\Psr\Container\ContainerInterface $c) {
        return new TokenService(
            $c->get(\PDO::class),
            $c->get(AppConfig::class)
        );
    },

    // 管理员用户仓库
    AdminUserRepository::class => function (\Psr\Container\ContainerInterface $c) {
        return new AdminUserRepository(
            $c->get(\PDO::class)
        );
    },

    // 管理认证服务
    AdminAuthService::class => function (\Psr\Container\ContainerInterface $c) {
        return new AdminAuthService(
            $c->get(\PDO::class),
            $c->get(AdminUserRepository::class),
            $c->get(AppConfig::class)
        );
    },

    // 鉴权中间件
    AuthMiddleware::class => function (\Psr\Container\ContainerInterface $c) {
        return new AuthMiddleware(
            $c->get(I18nService::class),
            $c->get(ResponseFactoryInterface::class),
            $c->get(TokenService::class)
        );
    },

    // ---------- Git Provider 注册表 ----------

    ProviderRegistry::class => function (\Psr\Container\ContainerInterface $c) {
        $config = $c->get(AppConfig::class);
        $logger  = $c->get(Logger::class);
        $registry = new ProviderRegistry($logger);

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
        return new GitProviderFactory($c->get(ProviderRegistry::class));
    },

    // ---------- Build Provider 注册表 ----------

    BuildProviderRegistry::class => function (\Psr\Container\ContainerInterface $c) {
        $config   = $c->get(AppConfig::class);
        $logger   = $c->get(Logger::class);
        $registry = new BuildProviderRegistry($logger);

        // 始终注册已配置的 Provider（BUILD_MODE 仅影响展示/偏好，不再限制注册）
        // 这样 UI 切换模式时无需重启即可生效
        $jenkinsCfg = $config->getJenkinsConfig();
        if (!empty($jenkinsCfg['url'])) {
            $registry->register(AppConfig::PROVIDER_JENKINS, function () use ($c, $logger) {
                return new JenkinsBuildProvider($c->get(JenkinsService::class), $c->get(GitService::class), $logger);
            });
        }

        // GitLab CI
        if ($config->isPlatformConfigured('gitlab')) {
            $glCfg = $config->getGitlabConfig();
            if (!empty($glCfg['base_url']) && !empty($glCfg['token'])) {
                $registry->register(AppConfig::PROVIDER_GITLAB_CI, function () use ($glCfg, $logger) {
                    return new GitlabCiBuildProvider($glCfg['base_url'], $glCfg['token'], $logger);
                });
            }
        }

        return $registry;
    },

    // ---------- Jenkins ----------
    JenkinsService::class => function (\Psr\Container\ContainerInterface $c) {
        try {
            $logger = $c->get(Logger::class);
        } catch (\Throwable $e) {
            $logger = null;
        }
        return new JenkinsService(
            $c->get(AppConfig::class)->getJenkinsConfig(),
            $logger
        );
    },

    // Git remote 解析
    GitRemoteResolver::class => function (\Psr\Container\ContainerInterface $c) {
        $config = $c->get(AppConfig::class);
        try {
            $logger = $c->get(Logger::class);
        } catch (\Throwable $e) {
            $logger = null;
        }
        return new GitRemoteResolver(
            $c->get(JenkinsService::class),
            $c->get(ProviderRegistry::class),
            $config->getJobGitMap(),
            $config->getGitlabConfig(),
            __DIR__ . '/gitlab_id_cache.php',
            $config->getDefaultGitPlatform(),
            $logger,
            $c->get('gitlabHttpClient')
        );
    },

    // Git 服务
    GitService::class => function (\Psr\Container\ContainerInterface $c) {
        try {
            $logger = $c->get(Logger::class);
        } catch (\Throwable $e) {
            $logger = null;
        }
        return new GitService(
            $c->get(GitRemoteResolver::class),
            $c->get(ProviderRegistry::class),
            $logger
        );
    },

    // Main 控制器
    MainController::class => function (\Psr\Container\ContainerInterface $c) {
        return new \App\Controller\MainController(
            $c->get(I18nService::class),
            $c->get(JenkinsService::class),
            $c->get(AppConfig::class),
            $c->get(MappingManager::class),
            $c->get(\PDO::class),
            $c->get(HarborService::class),
            $c->get(TokenService::class)
        );
    },

    // Admin 控制器
    AdminController::class => function (\Psr\Container\ContainerInterface $c) {
        return new AdminController(
            $c->get(I18nService::class),
            $c->get(AppConfig::class),
            $c->get(\PDO::class),
            $c->get(AdminAuthService::class),
            $c->get(AdminUserRepository::class),
            $c->get(AutoDiscover::class),
            $c->get(TokenService::class)
        );
    },

    // Build 控制器
    BuildController::class => function (\Psr\Container\ContainerInterface $c) {
        return new BuildController(
            $c->get(I18nService::class),
            $c->get(BuildProviderRegistry::class),
            $c->get(AppConfig::class),
            $c->get(MappingManager::class),
            $c->get(\PDO::class),
            $c->get(HarborService::class),
            $c->get(ProviderRegistry::class)
        );
    },

    // Git 控制器
    GitController::class => function (\Psr\Container\ContainerInterface $c) {
        return new GitController(
            $c->get(I18nService::class),
            $c->get(GitService::class)
        );
    },

    // ---------- GitLab HTTP 客户端（共享，供 AutoDiscover / GitRemoteResolver 复用）----------

    'gitlabHttpClient' => function (\Psr\Container\ContainerInterface $c) {
        $config = $c->get(AppConfig::class);
        $glCfg  = $config->getGitlabConfig();
        $base   = rtrim($glCfg['base_url'] ?? '', '/');
        $token  = $glCfg['token'] ?? '';
        if (empty($base) || empty($token)) {
            return null; // GitLab 未配置时返回 null，消费者自行降级
        }
        return new Client([
            'headers'         => ['PRIVATE-TOKEN' => $token],
            'timeout'         => 10,
            'connect_timeout' => 5,
            'http_errors'     => false,
        ]);
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
            'headers'         => ['Accept' => 'application/json'],
            'connect_timeout' => 5,   // TCP 握手超时，防止 hang
            'timeout'         => 15,  // 整个请求超时
        ]);
    },

    HarborService::class => function (\Psr\Container\ContainerInterface $c) {
        try {
            $logger = $c->get(Logger::class);
        } catch (\Throwable $e) {
            $logger = null;
        }
        return new HarborService($c->get('harborClient'), $c->get(\PDO::class), $logger);
    },

    HarborController::class => function (\Psr\Container\ContainerInterface $c) {
        return new HarborController(
            $c->get(I18nService::class),
            $c->get(HarborService::class)
        );
    },
];
