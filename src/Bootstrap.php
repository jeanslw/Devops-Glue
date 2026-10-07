<?php

namespace App;

use DI\ContainerBuilder;
use Slim\Factory\AppFactory;
use Psr\Http\Message\ResponseFactoryInterface;

/**
 * 应用引导类
 * 负责：环境变量加载、数据库初始化、DI 容器构建、Slim App 创建
 * 将 index.php 中的 bootstrap 逻辑抽离，便于测试与维护
 */
class Bootstrap
{
    /**
     * 创建并配置 Slim App
     * @return \Slim\App<\Psr\Container\ContainerInterface>
     */
    public static function createApp(): \Slim\App
    {
        // ── 环境变量：三层加载（优先级 app.env.local > 真实环境变量 > app.env.{APP_ENV} > app.env）──
        // 唯一实现在 App\Support\EnvFileLoader（覆盖层必须用 Mutable，否则覆盖无效，详见该类注释）
        \App\Support\EnvFileLoader::load(__DIR__ . '/../config');

        // 初始化数据库（自动建表 + JSON 迁移，SQLite / MySQL 均可）
        \App\Service\Database::init();

        $containerBuilder = new ContainerBuilder();
        $containerBuilder->addDefinitions(__DIR__ . '/../config/container.php');
        $container = $containerBuilder->build();

        AppFactory::setContainer($container);
        /** @var \Slim\App<\Psr\Container\ContainerInterface> $app 容器已 setContainer，泛型实参非空 */
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
