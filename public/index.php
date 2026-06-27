<?php

use DI\ContainerBuilder;
use Slim\Factory\AppFactory;
use Dotenv\Dotenv;

require __DIR__ . '/../vendor/autoload.php';

// __DIR__ . '/..' 指向项目根目录（即 .env 文件所在目录）
$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

$containerBuilder = new ContainerBuilder();

// 1 先加载 settings，再加载 container 定义
$settings = require __DIR__ . '/../config/settings.php';
$containerBuilder->addDefinitions(['settings' => $settings]);

// 2 再加载服务/控制器定义
$containerBuilder->addDefinitions(__DIR__ . '/../config/container.php');

$container = $containerBuilder->build();
$app = AppFactory::createFromContainer($container);

(require __DIR__ . '/../config/routes.php')($app);

$app->get('/', function ($request, $response, $args) {

    $htmlFile = __DIR__ . '/../templates/index.html';
    
    if (file_exists($htmlFile)) {
        $response->getBody()->write(file_get_contents($htmlFile));
    } else {
        $response->getBody()->write('<h1>首页文件丢失</h1>');
    }
    
    return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
});

$app->addRoutingMiddleware();

$errorMiddleware = $app->addErrorMiddleware(true, true, true);

// 53. 自定义 404 Not Found 处理器
$errorMiddleware->setErrorHandler(
    \Slim\Exception\HttpNotFoundException::class,
    function ($request, $exception, $displayErrorDetails) {
        $response = new \Slim\Psr7\Response();
        
        // 判断是不是 API 请求 (假设您的 API 都在 /api 路径下)
        $path = $request->getUri()->getPath();
        $acceptHeader = $request->getHeaderLine('Accept');
        
        if (str_starts_with($path, '/api') || str_contains($acceptHeader, 'application/json')) {
            // 如果是 API 请求，返回干净的 JSON 404
            $payload = json_encode(['code' => 404, 'message' => 'API 路由不存在', 'data' => null]);
            $response->getBody()->write($payload);
            return $response
                ->withStatus(404)
                ->withHeader('Content-Type', 'application/json; charset=utf-8');
        }

        // 如果是普通网页请求，返回我们漂亮的 HTML 页面
        // __DIR__ 是 public目录，../templates/404.html 就是上级的 templates 目录
        $htmlFile = __DIR__ . '/../templates/404.html'; 
        
        if (file_exists($htmlFile)) {
            $htmlContent = file_get_contents($htmlFile);
            $response->getBody()->write($htmlContent);
        } else {
            $response->getBody()->write('<h1>404 页面丢失啦</h1>');
        }

        return $response
            ->withStatus(404)
            ->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
);
$app->run();