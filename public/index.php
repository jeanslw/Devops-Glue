<?php
use DI\ContainerBuilder;
use Slim\Factory\AppFactory;
use Dotenv\Dotenv;

require __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

$containerBuilder = new ContainerBuilder();
$containerBuilder->addDefinitions(__DIR__ . '/../config/container.php');
$container = $containerBuilder->build();

AppFactory::setContainer($container);
$app = AppFactory::create();

// 加载路由（直接 require，不调用）
require __DIR__ . '/../config/routes.php';

// 首页
$app->get('/', function ($request, $response, $args) {
    $htmlFile = __DIR__ . '/../templates/index.html';
    if (file_exists($htmlFile)) {
        $response->getBody()->write(file_get_contents($htmlFile));
    } else {
        $response->getBody()->write('<h1>首页文件丢失</h1>');
    }
    return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
});

$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();
$errorMiddleware = $app->addErrorMiddleware(true, true, true);

// 自定义 404
$errorMiddleware->setErrorHandler(
    \Slim\Exception\HttpNotFoundException::class,
    function ($request, $exception, $displayErrorDetails) {
        $response = new \Slim\Psr7\Response();
        $path = $request->getUri()->getPath();
        $acceptHeader = $request->getHeaderLine('Accept');
        if (str_starts_with($path, '/api') || str_contains($acceptHeader, 'application/json')) {
            $payload = json_encode(['code' => 404, 'message' => 'API 路由不存在', 'data' => null]);
            $response->getBody()->write($payload);
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json; charset=utf-8');
        }
        $htmlFile = __DIR__ . '/../templates/404.html';
        if (file_exists($htmlFile)) {
            $response->getBody()->write(file_get_contents($htmlFile));
        } else {
            $response->getBody()->write('<h1>404 页面丢失啦</h1>');
        }
        return $response->withStatus(404)->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
);

$app->run();