<?php
use App\Bootstrap;
use App\ErrorHandlerFactory;
use Psr\Http\Message\ResponseFactoryInterface;

require __DIR__ . '/../vendor/autoload.php';

// ── 静态文件直出（PHP 内置服务器用）──
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$requestPath = parse_url($requestUri, PHP_URL_PATH);
$staticFile = realpath(__DIR__ . $requestPath);
if ($requestPath !== '/' && $staticFile && str_starts_with($staticFile, realpath(__DIR__) . DIRECTORY_SEPARATOR) && is_file($staticFile)) {
    $ext = strtolower(pathinfo($staticFile, PATHINFO_EXTENSION));
    $mimeTypes = ['css' => 'text/css', 'js' => 'application/javascript', 'png' => 'image/png', 'html' => 'text/html', 'json' => 'application/json', 'svg' => 'image/svg+xml', 'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ico' => 'image/x-icon'];
    $mime = $mimeTypes[$ext] ?? mime_content_type($staticFile) ?: 'application/octet-stream';
    header('Content-Type: ' . $mime);
    readfile($staticFile);
    exit;
}

// ── 应用引导（env、DB、DI、Slim App）──
$app = Bootstrap::createApp();
$container = $app->getContainer();

// PSR-17 Response 工厂（错误处理器统一使用，不直接 new 具体实现）
$responseFactory = $container->get(ResponseFactoryInterface::class);

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

// ── 注册错误处理器（含 ApiException 识别）──
ErrorHandlerFactory::register(
    $errorMiddleware,
    $appDebug,
    $isApiRequest,
    $errorMessages,
    $resolveErrorLocale,
    $responseFactory
);

$app->run();