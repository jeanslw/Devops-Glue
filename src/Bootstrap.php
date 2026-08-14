<?php
namespace App;

use DI\ContainerBuilder;
use Dotenv\Dotenv;
use Slim\Factory\AppFactory;
use Psr\Http\Message\ResponseFactoryInterface;

/**
 * 应用引导类
 * 负责：环境变量加载、SQLite 初始化、DI 容器构建、Slim App 创建
 * 将 index.php 中的 bootstrap 逻辑抽离，便于测试与维护
 */
class Bootstrap
{
    /**
     * 创建并配置 Slim App
     * @return \Slim\App
     */
    public static function createApp(): \Slim\App
    {
        // ── 环境变量：三层加载（顺序固定，后者覆盖前者）──
        // 1. 基础配置 .env（gitignored，放真实密码/密钥）
        //    用 Immutable：OS 真实环境变量优先，.env 只补缺省
        $dotenv = Dotenv::createImmutable(__DIR__ . '/../config');
        $dotenv->load();

        // 2. 环境特定覆盖 .env.{APP_ENV}（.env.production / .env.staging，可选提交）
        //    任何 APP_ENV 都尝试加载并覆盖 .env；文件不存在则跳过
        $appEnv = $_ENV['APP_ENV'] ?? 'production';
        $envFile = __DIR__ . '/../config/.env.' . $appEnv;
        if (file_exists($envFile)) {
            Dotenv::createUnsafeImmutable(__DIR__ . '/../config', '.env.' . $appEnv)->load();
        }

        // 3. 本地覆盖 .env.local（gitignored，开发者个人配置），优先级最高
        $localFile = __DIR__ . '/../config/.env.local';
        if (file_exists($localFile)) {
            Dotenv::createUnsafeImmutable(__DIR__ . '/../config', '.env.local')->load();
        }

        // 初始化 SQLite（自动建表 + JSON 迁移）
        \App\Service\Database::init();

        $containerBuilder = new ContainerBuilder();
        $containerBuilder->addDefinitions(__DIR__ . '/../config/container.php');
        $container = $containerBuilder->build();

        AppFactory::setContainer($container);
        $app = AppFactory::create();

        // 兼容 Swagger UI 等客户端对 job 名称中 / 的编码（php%2Fmyapp → php/myapp）
        $_SERVER['REQUEST_URI'] = str_replace('%2F', '/', $_SERVER['REQUEST_URI'] ?? '');

        return $app;
    }

    /**
     * 获取 PSR-17 Response 工厂（错误处理器统一使用）
     */
    public static function getResponseFactory(\Psr\Container\ContainerInterface $container): ResponseFactoryInterface
    {
        return $container->get(ResponseFactoryInterface::class);
    }
}