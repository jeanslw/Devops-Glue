<?php

use App\Config\AppConfig;
use App\Service\JenkinsService;
use App\Service\GitService;
use App\Service\MapService;
use App\Service\HarborService;
use App\Controller\JenkinsController;
use App\Controller\MainController;
use App\Controller\GitController;
use App\Controller\HarborController;
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

    // Jenkins 服务
    JenkinsService::class => function (\Psr\Container\ContainerInterface $c) {
        return new JenkinsService(
            $c->get(AppConfig::class)->getJenkinsConfig()
        );
    },

    // Map 服务
    MapService::class => function (\Psr\Container\ContainerInterface $c) {
        $config = $c->get(AppConfig::class);
        return new MapService(
            $c->get(JenkinsService::class),
            $config->getJobGitMap(),
            $config->getGitlabConfig(),
            __DIR__ . '/gitlab_id_cache.php'
        );
    },

    // Git 服务
    GitService::class => function (\Psr\Container\ContainerInterface $c) {
        $config = $c->get(AppConfig::class);
        return new GitService(
            $c->get(MapService::class),
            $config->getGitlabConfig(),
            $config->getGiteeConfig()
        );
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
            $c->get(AppConfig::class)   // 新增
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
            'base_uri' => $harbor['url'] ?? 'http://192.168.137.5',
            'auth'     => [
                $harbor['username'] ?? 'admin',
                $harbor['password'] ?? '',
            ],
            'headers'  => ['Accept' => 'application/json'],
            'timeout'  => 15,
        ]);
    },

    HarborService::class => function (\Psr\Container\ContainerInterface $c) {
        return new HarborService($c->get('harborClient'));
    },

    HarborController::class => function (\Psr\Container\ContainerInterface $c) {
        return new HarborController($c->get(HarborService::class));
    },
];