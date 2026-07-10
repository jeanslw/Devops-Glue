<?php
use DI\ContainerBuilder;
use Slim\Factory\AppFactory;
use Dotenv\Dotenv;

require __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv::createImmutable(__DIR__ . '/../config');
$dotenv->load();

// 静态文件直出（PHP 内置服务器用）
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$requestPath = parse_url($requestUri, PHP_URL_PATH);
$staticFile = __DIR__ . $requestPath;
if ($requestPath !== '/' && is_file($staticFile)) {
    $ext = strtolower(pathinfo($staticFile, PATHINFO_EXTENSION));
    $mimeTypes = ['css' => 'text/css', 'js' => 'application/javascript', 'png' => 'image/png', 'html' => 'text/html', 'json' => 'application/json', 'svg' => 'image/svg+xml', 'woff' => 'font/woff', 'woff2' => 'font/woff2'];
    $mime = $mimeTypes[$ext] ?? mime_content_type($staticFile) ?: 'application/octet-stream';
    header('Content-Type: ' . $mime);
    readfile($staticFile);
    exit;
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

// CORS 中间件（最后添加 = 最先执行，确保在路由之前拦截 OPTIONS）
$app->add(\App\Middleware\CorsMiddleware::class);
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