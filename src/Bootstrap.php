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


        \App\Support\EnvFileLoader::load(__DIR__ . '/../config');


        \App\Service\Database::init();

        $containerBuilder = new ContainerBuilder();
        $containerBuilder->addDefinitions(__DIR__ . '/../config/container.php');
        $container = $containerBuilder->build();

        AppFactory::setContainer($container);
        /** @var \Slim\App<\Psr\Container\ContainerInterface> $app 容器已 setContainer，泛型实参非空 */
        $app = AppFactory::create();
        $_SERVER['REQUEST_URI'] = str_replace('%2F', '/', $_SERVER['REQUEST_URI'] ?? '');
        $requestId = (string)($_SERVER['HTTP_X_REQUEST_ID'] ?? '');
        $requestId = preg_replace('/[\x00-\x1F\x7F]/', '', $requestId) ?? '';
        $requestId = mb_substr($requestId, 0, 64);
        if ($requestId === '') {
            $requestId = bin2hex(random_bytes(12));
        }
        \App\Helper\Log::setRequestContext($requestId, $_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD'] ?? '');

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
