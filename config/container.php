<?php

use App\Config\AppConfig;
use App\Service\Settings;
use App\Service\AppSettingRepository;
use App\Service\PlatformVersionRepository;
use App\Service\JenkinsService;
use App\Service\GitService;
use App\Service\GitRemoteResolver;
use App\Service\AutoDiscover;
use App\Service\HarborService;
use App\Service\Registry\RegistryProviderInterface;
use App\Service\Registry\HarborProvider;
use App\Service\Registry\DockerRegistryProvider;
use App\Service\RegistrySyncService;
use App\Service\MappingManager;
use App\Service\PipelineTagService;
use App\Service\PipelineArtifactService;
use App\Service\I18nService;
use App\Service\TokenService;
use App\Service\ApiTokenService;
use App\Service\IntegratedServiceRepository;
use App\Service\AdminAuthService;
use App\Service\AdminUserRepository;
use App\Service\ApiAccessLogRepository;
use App\Service\LdapService;
use App\Service\UserIdentityRepository;

use App\Service\Git\ProviderRegistry;
use App\Service\Git\GitProviderFactory;
use App\Service\Build\BuildProviderRegistry;
use App\Service\Build\JenkinsBuildProvider;
use App\Service\Build\GitlabCiBuildProvider;
use App\Service\Build\GiteaCiBuildProvider;
use App\Service\Build\GithubActionsBuildProvider;
use App\Controller\MainController;
use App\Controller\GitController;
use App\Controller\HarborController;
use App\Controller\RegistryController;
use App\Controller\AdminController;
use App\Controller\ServiceContractController;
use App\Controller\IntegratedServiceController;
use App\Controller\RbacController;
use App\Controller\BuildController;
use App\Controller\OAuthController;
use App\Service\OAuthService;
use App\Service\OidcService;
use App\Service\OAuthClientRepository;
use App\Service\PlatformConnectionRepository;
use App\Service\Encryptor;

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

    // PDO 数据库连接
    \PDO::class => function () {
        return \App\Service\Database::getPdo();
    },

    // 运行时配置
    Settings::class => function (\Psr\Container\ContainerInterface $c) use ($settings) {
        $conns = $c->get(PlatformConnectionRepository::class)->resolveConnections();
        $settings['git'] = array_merge($settings['git'], [
            'gitlab' => $conns['gitlab'],
            'github' => $conns['github'],
            'gitee'  => $conns['gitee'],
            'gitea'  => $conns['gitea'],
        ]);
        $settings['harbor']  = $conns['harbor'];
        $settings['jenkins'] = $conns['jenkins'];
        
        $settings['harbor']['registry_type'] = $c->get(AppSettingRepository::class)->getRegistryType();
        return new Settings($settings, $c->get(PlatformVersionRepository::class));
    },

    // ci_app_settings 运行时开关/配置
    AppSettingRepository::class => function (\Psr\Container\ContainerInterface $c) {
        return new AppSettingRepository($c->get(\PDO::class));
    },

    // 敏感字段对称加密（AES-256-GCM）
    Encryptor::class => function () {
        return new Encryptor();
    },

    // OIDC/OAuth 客户端白名单
    OAuthClientRepository::class => function (\Psr\Container\ContainerInterface $c) {
        return new OAuthClientRepository($c->get(\PDO::class), $c->get(Encryptor::class));
    },

    // Git/Harbor/Jenkins 连接配置
    PlatformConnectionRepository::class => function (\Psr\Container\ContainerInterface $c) {
        return new PlatformConnectionRepository($c->get(\PDO::class), $c->get(Encryptor::class));
    },

    // 平台 API 版本覆盖
    PlatformVersionRepository::class => function (\Psr\Container\ContainerInterface $c) {
        return new PlatformVersionRepository($c->get(\PDO::class));
    },

    // API token 调用审计日志
    ApiAccessLogRepository::class => function (\Psr\Container\ContainerInterface $c) {
        return new ApiAccessLogRepository($c->get(\PDO::class));
    },

    // ---------- 基础设施 ----------

    // 映射查询（数据层）
    MappingManager::class => function (\Psr\Container\ContainerInterface $c) {
        return new MappingManager($c->get(AppSettingRepository::class), $c->get(\PDO::class));
    },

    // 自动发现
    AutoDiscover::class => function (\Psr\Container\ContainerInterface $c) {
        return new AutoDiscover(
            $c->get(JenkinsService::class),
            $c->get(ProviderRegistry::class),
            $c->get(Settings::class),
            $c->get(MappingManager::class),
            $c->get(Logger::class),
            $c->get('gitlabHttpClient'),
            $c->get('giteaHttpClient'),
            $c->get('githubHttpClient')
        );
    },

    // 国际化服务
    I18nService::class => function (\Psr\Container\ContainerInterface $c) {
        $langDir = __DIR__ . '/../lang';
        return new I18nService($langDir, 'zh_CN');
    },

    // 日志服务
    Logger::class => function (\Psr\Container\ContainerInterface $c) {
        $config = $c->get(Settings::class);
        return new Logger(
            $config->getLogPath(),
            $config->isDebug() ? 'debug' : 'info'
        );
    },

    // CORS 中间件：只读纯配置，不经过 Settings
    CorsMiddleware::class => function () use ($settings) {
        return new CorsMiddleware($settings['cors'] ?? ['allowed_origins' => ['*']]);
    },

    // Token 验证服务
    TokenService::class => function (\Psr\Container\ContainerInterface $c) {
        return new TokenService(
            $c->get(\PDO::class),
            $c->get(Settings::class),
            $c->get(ApiTokenService::class)
        );
    },

    // API Token 服务
    ApiTokenService::class => function (\Psr\Container\ContainerInterface $c) {
        return new ApiTokenService($c->get(\PDO::class));
    },

    // 集成服务启用契约仓储
    IntegratedServiceRepository::class => function (\Psr\Container\ContainerInterface $c) {
        return new IntegratedServiceRepository($c->get(\PDO::class));
    },

    // 管理员用户仓库
    AdminUserRepository::class => function (\Psr\Container\ContainerInterface $c) {
        return new AdminUserRepository(
            $c->get(\PDO::class)
        );
    },

    // 用户身份源（user_identities）仓储
    UserIdentityRepository::class => function (\Psr\Container\ContainerInterface $c) {
        return new UserIdentityRepository($c->get(\PDO::class));
    },

    // LDAP 认证客户端
    LdapService::class => function (\Psr\Container\ContainerInterface $c) {
        return new LdapService($c->get(Settings::class));
    },

    // 管理认证服务
    AdminAuthService::class => function (\Psr\Container\ContainerInterface $c) {
        return new AdminAuthService(
            $c->get(\PDO::class),
            $c->get(AdminUserRepository::class),
            $c->get(Settings::class),
            $c->get(LdapService::class),
            $c->get(UserIdentityRepository::class)
        );
    },


    // 鉴权中间件
    AuthMiddleware::class => function (\Psr\Container\ContainerInterface $c) {
        return new AuthMiddleware(
            $c->get(I18nService::class),
            $c->get(ResponseFactoryInterface::class),
            $c->get(TokenService::class),
            $c->get(ApiAccessLogRepository::class),
            $c->get(Logger::class),
            $c->get(Settings::class)->getTrustedProxyHops(),
            $c->get(AppSettingRepository::class)
        );
    },

    // ---------- Git Provider 注册表 ----------

    ProviderRegistry::class => function (\Psr\Container\ContainerInterface $c) {
        $config = $c->get(Settings::class);
        $logger  = $c->get(Logger::class);
        $registry = new ProviderRegistry($logger);

        

        
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

        // Gitee
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

        // GitHub
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

        // Gitea
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
        $config   = $c->get(Settings::class);
        $logger   = $c->get(Logger::class);
        $registry = new BuildProviderRegistry($logger);
		
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

        // Gitea Actions
        if ($config->isPlatformConfigured('gitea')) {
            $giteaCfg = $config->getGiteaConfig();
            if (!empty($giteaCfg['base_url']) && !empty($giteaCfg['token'])) {
                $registry->register(AppConfig::PROVIDER_GITEA_CI, function () use ($giteaCfg, $logger) {
                    return new GiteaCiBuildProvider($giteaCfg['base_url'], $giteaCfg['token'], $logger);
                });
            }
        }

        // GitHub Actions
        if ($config->isPlatformConfigured('github')) {
            $githubCfg = $config->getGithubConfig();
            if (!empty($githubCfg['base_url']) && !empty($githubCfg['token'])) {
                $registry->register(AppConfig::PROVIDER_GITHUB_ACTIONS, function () use ($githubCfg, $logger) {
                    return new GithubActionsBuildProvider($githubCfg['base_url'], $githubCfg['token'], $logger);
                });
            }
        }

        // ---- 自定义 Build Provider----
        foreach ($config->getCustomBuildProviders() as $provider) {
            $name  = $provider['name'] ?? '';
            $class = $provider['class'] ?? '';
            $cfg   = $provider['config'] ?? [];

            if (empty($name) || empty($class)) {
                $logger->warning('跳过无效的自定义 Build Provider 配置', ['provider' => $provider]);
                continue;
            }

            $registry->register($name, function () use ($class, $cfg, $c, $logger) {
                if (!class_exists($class)) {
                    throw new \RuntimeException("自定义 Build Provider 类不存在: {$class}");
                }
                return new $class(
                    $cfg,
                    $c->get(\PDO::class),
                    $c->get(GitService::class),
                    $logger
                );
            });
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
            $c->get(Settings::class)->getJenkinsConfig(),
            $logger
        );
    },

    // Git remote 解析
    GitRemoteResolver::class => function (\Psr\Container\ContainerInterface $c) {
        $config = $c->get(Settings::class);
        try {
            $logger = $c->get(Logger::class);
        } catch (\Throwable $e) {
            $logger = null;
        }
        $gitlabIdCache = (isset($_ENV['GITLAB_ID_CACHE']) && $_ENV['GITLAB_ID_CACHE'] !== '')
            ? $_ENV['GITLAB_ID_CACHE']
            : (getenv('GITLAB_ID_CACHE') ?: __DIR__ . '/gitlab_id_cache.php');
        return new GitRemoteResolver(
            $c->get(JenkinsService::class),
            $c->get(ProviderRegistry::class),
            $c->get(MappingManager::class)->allMaps(),
            $config->getGitlabConfig(),
            $gitlabIdCache,
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
            $c->get(Settings::class),
            $c->get(MappingManager::class),
            $c->get(AppSettingRepository::class),
            $c->get(\PDO::class),
            $c->get(HarborService::class),
            $c->get(TokenService::class)
        );
    },

    // Admin 控制器
    AdminController::class => function (\Psr\Container\ContainerInterface $c) {
        return new AdminController(
            $c->get(I18nService::class),
            $c->get(Settings::class),
            $c->get(MappingManager::class),
            $c->get(AppSettingRepository::class),
            $c->get(PlatformVersionRepository::class),
            $c->get(OAuthClientRepository::class),
            $c->get(PlatformConnectionRepository::class),
            $c->get(\PDO::class),
            $c->get(AdminAuthService::class),
            $c->get(AdminUserRepository::class),
            $c->get(AutoDiscover::class),
            $c->get(TokenService::class),
            $c->get(ApiTokenService::class),
            $c->get(HarborService::class),
            $c->get(JenkinsService::class),
            $c->get('registryProvider')
        );
    },

    // 集成服务启停控制器
    IntegratedServiceController::class => function (\Psr\Container\ContainerInterface $c) {
        return new IntegratedServiceController(
            $c->get(I18nService::class),
            $c->get(\PDO::class),
            $c->get(IntegratedServiceRepository::class),
            $c->get(ApiTokenService::class),
            $c->get(Settings::class)
        );
    },

    // 集成服务契约窄只读控制器
    ServiceContractController::class => function (\Psr\Container\ContainerInterface $c) {
        return new ServiceContractController(
            $c->get(I18nService::class),
            $c->get(IntegratedServiceRepository::class),
            $c->get(Settings::class)
        );
    },

    // RBAC 用户写控制器
    RbacController::class => function (\Psr\Container\ContainerInterface $c) {
        return new RbacController(
            $c->get(I18nService::class),
            $c->get(Settings::class),
            $c->get(AdminUserRepository::class),
            $c->get(TokenService::class)
        );
    },

    // Build 控制器
    BuildController::class => function (\Psr\Container\ContainerInterface $c) {
        return new BuildController(
            $c->get(I18nService::class),
            $c->get(BuildProviderRegistry::class),
            $c->get(Settings::class),
            $c->get(MappingManager::class),
            $c->get(AppSettingRepository::class),
            $c->get(\PDO::class),
            $c->get(PipelineTagService::class),
            $c->get(PipelineArtifactService::class),
            $c->get('registryProvider'),
            $c->get(ProviderRegistry::class),
            $c->get(RegistrySyncService::class)
        );
    },

    // Git 控制器
    GitController::class => function (\Psr\Container\ContainerInterface $c) {
        return new GitController(
            $c->get(I18nService::class),
            $c->get(GitService::class)
        );
    },

    // ---------- GitLab HTTP 客户端----------

    'gitlabHttpClient' => function (\Psr\Container\ContainerInterface $c) {
        $config = $c->get(Settings::class);
        $glCfg  = $config->getGitlabConfig();
        $base   = rtrim($glCfg['base_url'] ?? '', '/');
        $token  = $glCfg['token'] ?? '';
        if (empty($base) || empty($token)) {
            return null;
        }
        return new Client([
            'headers'         => ['PRIVATE-TOKEN' => $token],
            'timeout'         => 10,
            'connect_timeout' => 5,
            'http_errors'     => false,
        ]);
    },

    // Gitea HTTP 客户端
    'giteaHttpClient' => function (\Psr\Container\ContainerInterface $c) {
        $config = $c->get(Settings::class);
        $giteaCfg = $config->getGiteaConfig();
        $base   = rtrim($giteaCfg['base_url'] ?? '', '/');
        $token  = $giteaCfg['token'] ?? '';
        if (empty($base) || empty($token)) {
            return null;
        }
        return new Client([
            'headers'         => ['Authorization' => 'token ' . $token],
            'timeout'         => 10,
            'connect_timeout' => 5,
            'http_errors'     => false,
        ]);
    },

    // GitHub HTTP 客户端（共享，供 AutoDiscover 扫描 GitHub 项目复用）
    'githubHttpClient' => function (\Psr\Container\ContainerInterface $c) {
        $config = $c->get(Settings::class);
        $githubCfg = $config->getGithubConfig();
        $base   = rtrim($githubCfg['base_url'] ?? 'https://api.github.com', '/');
        $token  = $githubCfg['token'] ?? '';
        if (empty($base) || empty($token)) {
            return null;
        }
        return new Client([
            'headers'         => [
                'Authorization'        => 'token ' . $token,
                'Accept'               => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
            ],
            'timeout'         => 10,
            'connect_timeout' => 5,
            'http_errors'     => false,
        ]);
    },

    // ---------- Harbor 模块 ----------

    // Harbor Guzzle 客户端
    'harborClient' => function (\Psr\Container\ContainerInterface $c) {
        $config = $c->get(Settings::class);
        $harbor = $config->getHarborConfig();
        return new Client([
            'base_uri' => $harbor['url'] ?? '',
            'auth'     => [
                $harbor['username'] ?? 'admin',
                $harbor['password'] ?? '',
            ],
            'headers'         => ['Accept' => 'application/json'],
            'connect_timeout' => 5,  
            'timeout'         => 15,
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

    'registryClient' => function (\Psr\Container\ContainerInterface $c) {
        $config = $c->get(Settings::class);
        $registry = $config->getHarborConfig();
        $url = rtrim((string) ($registry['url'] ?? ''), '/');
        return new Client([
            'base_uri'        => $url === '' ? '' : $url . '/',
            'headers'         => ['Accept' => 'application/json'],
            'connect_timeout' => 5,
            'timeout'         => 15,
            'http_errors'     => false,
        ]);
    },

    // 镜像仓库提供方：按 ci_app_settings.registry_type（全局键）单实例构造。
    'registryProvider' => function (\Psr\Container\ContainerInterface $c): ?RegistryProviderInterface {
        $config = $c->get(Settings::class);
        $registry = $config->getHarborConfig();
        if (trim((string) ($registry['url'] ?? '')) === '') {
            return null;
        }
        if ($config->getRegistryType() === 'registry') {
            $logger = null;
            try {
                $logger = $c->get(Logger::class);
            } catch (\Throwable $e) {
            }
            return new DockerRegistryProvider(
                $c->get('registryClient'),
                (string) ($registry['username'] ?? ''),
                (string) ($registry['password'] ?? ''),
                $logger
            );
        }
        return new HarborProvider($c->get(HarborService::class));
    },

    PipelineTagService::class => function (\Psr\Container\ContainerInterface $c) {
        return new PipelineTagService($c->get(\PDO::class), $c->get('registryProvider'));
    },

    RegistrySyncService::class => function (\Psr\Container\ContainerInterface $c) {
        return new RegistrySyncService(
            $c->get(I18nService::class),
            $c->get(MappingManager::class),
            $c->get(BuildProviderRegistry::class),
            $c->get(PipelineArtifactService::class),
            $c->has(ProviderRegistry::class) ? $c->get(ProviderRegistry::class) : null,
            $c->get('registryProvider'),
            $c->get(Settings::class),
            $c->get(\PDO::class)
        );
    },

    PipelineArtifactService::class => function (\Psr\Container\ContainerInterface $c) {
        return new PipelineArtifactService($c->get(\PDO::class));
    },

    HarborController::class => function (\Psr\Container\ContainerInterface $c) {
        return new HarborController(
            $c->get(I18nService::class),
            $c->get(HarborService::class)
        );
    },

    // 镜像仓库通用列表
    RegistryController::class => function (\Psr\Container\ContainerInterface $c) {
        return new RegistryController(
            $c->get(I18nService::class),
            $c->get('registryProvider')
        );
    },

    // ---------- OAuth2 / OIDC Provider（Jenkins / Harbor / GitLab ）----------

    OAuthService::class => function (\Psr\Container\ContainerInterface $c) {
        return new OAuthService(
            $c->get(\PDO::class),
            $c->get(OAuthClientRepository::class)->resolveClients([])
        );
    },

    OidcService::class => function (\Psr\Container\ContainerInterface $c) use ($settings) {
        $repo = $c->get(AppSettingRepository::class);
        $oidc = $settings['oidc'] ?? [];
        $issuer = $repo->getOidcIssuer();
        if ($issuer !== null) {
            $oidc['issuer'] = $issuer;
        }
        $ttl = $repo->getOidcIdTokenTtl();
        if ($ttl !== null) {
            $oidc['id_token_ttl'] = $ttl;
        }
        return new OidcService($oidc);
    },

    OAuthController::class => function (\Psr\Container\ContainerInterface $c) {
        return new OAuthController(
            $c->get(I18nService::class),
            $c->get(\PDO::class),
            $c->get(OAuthService::class),
            $c->get(AdminAuthService::class),
            $c->get(AdminUserRepository::class),
            $c->get(OidcService::class),
            $c->get(Settings::class)
        );
    },
];