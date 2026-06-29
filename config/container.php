<?php

use App\Service\JenkinsService;
use App\Service\GitService;
use App\Service\MapService;
use Slim\Psr7\Factory\ResponseFactory;

return [

    // ---------- PSR-17 工厂（Slim 必须） ----------
    \Psr\Http\Message\ResponseFactoryInterface::class => function () {
        return new ResponseFactory();
    },

    // ---------- 业务配置 ----------
    'settings' => function () {
        return require __DIR__ . '/settings.php';
    },

    JenkinsService::class => function (\Psr\Container\ContainerInterface $c) {
        $settings = $c->get('settings');
        return new JenkinsService($settings['jenkins']);
        $c->get(JenkinsService::class);
        $c->get(GitService::class);
    },

    MainController::class => function (\Psr\Container\ContainerInterface $c) {
        return new \App\Controller\MainController(
            $c->get(JenkinsService::class),
            $c->get(MapService::class)
        );
    },

    MapService::class => function (\Psr\Container\ContainerInterface $c) {
        return new MapService($c->get(JenkinsService::class));
    },

    GitService::class => function (\Psr\Container\ContainerInterface $c) {
        $settings = $c->get('settings');
        return new GitService(
            $c->get(MapService::class),
            $settings['git']
        );
    },
    JenkinsController::class => function (\Psr\Container\ContainerInterface $c) {
    return new JenkinsController(
        $c->get(JenkinsService::class),
        $c->get(GitService::class)
    );
    },
];