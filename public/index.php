<?php
use DI\ContainerBuilder;
use Slim\Factory\AppFactory;
use Dotenv\Dotenv;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;

require __DIR__ . '/../vendor/autoload.php';

// ── 环境变量：三层加载（后加载的覆盖前面的）──
// 1. 基础配置（.env，gitignored，放真实密码）
$dotenv = Dotenv::createImmutable(__DIR__ . '/../config');
$dotenv->load();

// 2. 环境特定覆盖（.env.production / .env.staging，可选提交）
$appEnv = $_ENV['APP_ENV'] ?? 'production';
$envFile = __DIR__ . '/../config/.env.' . $appEnv;
if ($appEnv !== 'production' && file_exists($envFile)) {
    Dotenv::createUnsafeImmutable(__DIR__ . '/../config', '.env.' . $appEnv)->load();
}

// 3. 本地覆盖（.env.local，gitignored，开发者个人配置）
$localFile = __DIR__ . '/../config/.env.local';
if (file_exists($localFile)) {
    Dotenv::createUnsafeImmutable(__DIR__ . '/../config', '.env.local')->load();
}

// 静态文件直出（PHP 内置服务器用）
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$requestPath = parse_url($requestUri, PHP_URL_PATH);
$staticFile = realpath(__DIR__ . $requestPath);
if ($requestPath !== '/' && $staticFile && str_starts_with($staticFile, realpath(__DIR__)) && is_file($staticFile)) {
    $ext = strtolower(pathinfo($staticFile, PATHINFO_EXTENSION));
    $mimeTypes = ['css' => 'text/css', 'js' => 'application/javascript', 'png' => 'image/png', 'html' => 'text/html', 'json' => 'application/json', 'svg' => 'image/svg+xml', 'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ico' => 'image/x-icon'];
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

// PSR-17 Response 工厂（错误处理器统一使用，不直接 new 具体实现）
$responseFactory = $container->get(ResponseFactoryInterface::class);

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

// ── 中间件（LIFO 栈：后添加先执行）──
// 执行顺序：Error → CORS → Routing → BodyParsing → Route Handler
$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();

// CORS 中间件（在路由之前执行，确保 OPTIONS 预检请求被拦截）
$app->add(\App\Middleware\CorsMiddleware::class);
$appDebug = ($_ENV['APP_DEBUG'] ?? 'false') === 'true';
$errorMiddleware = $app->addErrorMiddleware($appDebug, true, true);

// ── 判断是否 API 请求 ──
// 浏览器直接访问（Accept 包含 text/html）时返回友好 HTML 页面；
// AJAX/Fetch（Accept: application/json 或 X-Requested-With: XMLHttpRequest）或显式 API 路径返回 JSON。
$isApiRequest = function ($request): bool {
    $accept = $request->getHeaderLine('Accept');
    if (str_contains($accept, 'text/html')) {
        return false;
    }
    $path = $request->getUri()->getPath();
    return str_starts_with($path, '/api')
        || str_contains($accept, 'application/json')
        || strtolower($request->getHeaderLine('X-Requested-With')) === 'xmlhttprequest';
};

// ── 错误页面语言检测：URL ?lang= > Accept-Language > 默认中文 ──
$resolveErrorLocale = function ($request): string {
    $query = $request->getUri()->getQuery();
    parse_str($query, $params);
    if (isset($params['lang'])) {
        $lang = strtolower($params['lang']);
        if ($lang === 'zh' || $lang === 'zh_cn') return 'zh';
        if ($lang === 'en') return 'en';
    }
    $accept = strtolower($request->getHeaderLine('Accept-Language'));
    if (str_starts_with($accept, 'zh')) return 'zh';
    if (str_starts_with($accept, 'en')) return 'en';
    return 'zh';
};

// ── 友好错误文案 ──
$errorMessages = [
    'zh' => [
        400 => '请求参数错误',
        401 => '未授权',
        403 => '禁止访问',
        404 => '页面不存在',
        405 => '请求方法不允许',
        408 => '请求超时',
        429 => '请求过于频繁',
        500 => '服务器内部错误',
        502 => '网关错误',
        503 => '服务不可用',
        504 => '网关超时',
    ],
    'en' => [
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        408 => 'Request Timeout',
        429 => 'Too Many Requests',
        500 => 'Internal Server Error',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Timeout',
    ],
];

// ── 渲染友好 HTML 错误页 ──
$renderErrorHtml = function ($request, int $code, ?string $detail = null) use ($resolveErrorLocale, $responseFactory): ResponseInterface {
    $response = $responseFactory->createResponse();
    $lang = $resolveErrorLocale($request);
    $htmlFile = __DIR__ . '/../templates/error.html';
    if (file_exists($htmlFile)) {
        $html = file_get_contents($htmlFile);
        $html = str_replace('{{CODE}}', (string) $code, $html);
        $html = str_replace('{{LANG}}', $lang, $html);
        $html = str_replace('{{DETAIL}}', $detail ? htmlspecialchars($detail, ENT_QUOTES, 'UTF-8') : '', $html);
        $response->getBody()->write($html);
        return $response->withStatus($code)->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
    $fallback = '<!doctype html><html lang="' . ($lang === 'en' ? 'en' : 'zh-CN') . '"><head><meta charset="utf-8"><title>' . $code . '</title></head><body style="font-family:sans-serif;padding:40px;text-align:center;"><h1>' . $code . '</h1><p>' . ($detail ?: 'Error') . '</p></body></html>';
    $response->getBody()->write($fallback);
    return $response->withStatus($code)->withHeader('Content-Type', 'text/html; charset=utf-8');
};

// ── 通用错误 → API 请求返回 JSON，否则 HTML ──
$errorMiddleware->setDefaultErrorHandler(function ($request, $exception, $displayErrorDetails) use ($isApiRequest, $renderErrorHtml, $errorMessages, $resolveErrorLocale, $responseFactory) {
    $response = $responseFactory->createResponse();
    $code = 500;
    if ($exception instanceof \Slim\Exception\HttpException) {
        $code = $exception->getCode();
    }
    $lang = $resolveErrorLocale($request);
    $message = $errorMessages[$lang][$code] ?? $errorMessages[$lang][500];

    if ($isApiRequest($request)) {
        $payload = ['code' => $code, 'message' => $message];
        if ($displayErrorDetails && $exception instanceof \Throwable) {
            $payload['error'] = $exception->getMessage();
            $payload['file']  = $exception->getFile() . ':' . $exception->getLine();
            $payload['trace'] = explode("\n", $exception->getTraceAsString());
        }
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $response->withStatus($code)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    $detail = null;
    if ($displayErrorDetails && $exception instanceof \Throwable) {
        $detail = $exception->getMessage();
    }
    return $renderErrorHtml($request, $code, $detail);
});

// ── 注册常见 HTTP 异常的友好处理 ──
// 注：Slim 4 仅内置了常用 HTTP 异常类，其余状态码走默认错误处理器
$httpExceptions = [
    \Slim\Exception\HttpBadRequestException::class          => 400,
    \Slim\Exception\HttpUnauthorizedException::class        => 401,
    \Slim\Exception\HttpForbiddenException::class           => 403,
    \Slim\Exception\HttpNotFoundException::class            => 404,
    \Slim\Exception\HttpMethodNotAllowedException::class    => 405,
    \Slim\Exception\HttpTooManyRequestsException::class     => 429,
    \Slim\Exception\HttpInternalServerErrorException::class => 500,
    \Slim\Exception\HttpNotImplementedException::class      => 501,
];

foreach ($httpExceptions as $exceptionClass => $statusCode) {
    $errorMiddleware->setErrorHandler($exceptionClass, function ($request, $exception, $displayErrorDetails) use ($isApiRequest, $renderErrorHtml, $errorMessages, $resolveErrorLocale, $statusCode, $responseFactory) {
        $response = $responseFactory->createResponse();
        $lang = $resolveErrorLocale($request);
        $message = $errorMessages[$lang][$statusCode] ?? $errorMessages[$lang][500];

        if ($isApiRequest($request)) {
            $payload = ['code' => $statusCode, 'message' => $message];
            if ($displayErrorDetails && $exception instanceof \Throwable) {
                $payload['error'] = $exception->getMessage();
            }
            $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE));
            return $response->withStatus($statusCode)->withHeader('Content-Type', 'application/json; charset=utf-8');
        }

        $detail = null;
        if ($displayErrorDetails && $exception instanceof \Throwable) {
            $detail = $exception->getMessage();
        }
        return $renderErrorHtml($request, $statusCode, $detail);
    });
}

$app->run();