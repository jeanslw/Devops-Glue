<?php
use Slim\Routing\RouteCollectorProxy;
use App\Controller\JenkinsController;
use App\Controller\GitController;
use App\Controller\MainController;
use App\Controller\HarborController;
use App\Controller\AdminController;
use App\Controller\BuildController;

// 管理页面
$app->get('/admin', function ($request, $response) {
    $htmlFile = __DIR__ . '/../templates/admin.html';
    $response->getBody()->write(file_exists($htmlFile)
        ? file_get_contents($htmlFile)
        : '<h1>管理页面丢失</h1>');
    return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
});

$app->group('/api', function (RouteCollectorProxy $api) {

    // 健康检查
    $api->map(['GET'], '/health', [MainController::class, 'health']);

    // 简单鉴权 helper（闭包内复用）
    $checkAuth = function ($request) {
        $user = $_ENV['ADMIN_USER'] ?? 'admin';
        $pass = $_ENV['ADMIN_PASSWORD'] ?? '';
        if (empty($pass)) return true; // 未设密码则放行
        $token = $request->getQueryParams()['token'] ?? '';
        if (empty($token)) {
            $header = $request->getHeaderLine('Authorization');
            if (preg_match('/^Bearer\s+(.+)$/i', $header, $m)) $token = $m[1];
        }
        $expected = base64_encode($user . ':' . $pass);
        return $token && hash_equals($expected, $token);
    };

    // API 文档 (Swagger UI) —— 需登录
    $api->get('/docs', function ($request, $response) use ($checkAuth) {
        $htmlFile = __DIR__ . '/../templates/swagger.html';
        $swaggerHtml = file_exists($htmlFile) ? file_get_contents($htmlFile) : '<h1>文档文件丢失</h1>';

        if ($checkAuth($request)) {
            $response->getBody()->write($swaggerHtml);
            return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
        }

        // 未登录 → 显示登录页
        $loginPage = '<!DOCTYPE html><html lang="zh"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>API 文档登录</title>
        <style>
        *{margin:0;padding:0;box-sizing:border-box}body{font-family:Segoe UI,Microsoft YaHei,sans-serif;background:linear-gradient(135deg,#667eea,#764ba2);min-height:100vh;display:flex;align-items:center;justify-content:center}
        .box{background:#fff;border-radius:16px;padding:36px 32px;box-shadow:0 8px 32px rgba(0,0,0,.18);width:380px;max-width:90vw;text-align:center}
        .box h3{font-size:20px;margin-bottom:4px}.box .sub{font-size:13px;color:#9ca3af;margin-bottom:24px}
        .box input{width:100%;padding:10px 14px;border:1.5px solid #d1d5db;border-radius:8px;font-size:14px;margin-bottom:12px}
        .box input:focus{outline:none;border-color:#667eea;box-shadow:0 0 0 3px rgba(102,126,234,.15)}
        .box .btn{width:100%;padding:10px;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;border:none;background:#4f46e5;color:#fff;transition:all .2s}
        .box .btn:hover{background:#4338ca}
        .err{color:#dc2626;font-size:13px;margin-top:8px;display:none}
        .back{margin-top:16px;font-size:13px}.back a{color:#4f46e5;text-decoration:none}
        </style></head><body>
        <div class="box"><h3>📖 API 文档</h3><p class="sub">请使用管理后台账号登录</p>
        <input id="u" placeholder="账号" autocomplete="username"><input id="p" type="password" placeholder="密码" autocomplete="current-password">
        <button class="btn" onclick="login()">登 录</button><div class="err" id="e"></div>
        <div class="back"><a href="/">🏠 回首页</a></div></div>
        <script>
        async function login(){var u=document.getElementById("u").value.trim(),p=document.getElementById("p").value,e=document.getElementById("e");e.style.display="none";if(!u||!p){e.textContent="请输入账号密码";e.style.display="block";return}
        try{var r=await fetch("/api/admin/login",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({user:u,password:p})});var d=await r.json();if(r.ok&&d.token){location.href="/api/docs?token="+d.token}else{e.textContent=d.message||"登录失败";e.style.display="block"}}catch(x){e.textContent="网络错误: "+x.message;e.style.display="block"}}
        document.addEventListener("keydown",function(ev){if(ev.key==="Enter")login()});
        </script></body></html>';
        $response->getBody()->write($loginPage);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    });

    // OpenAPI 规范 —— 需登录
    $api->get('/openapi.json', function ($request, $response) use ($checkAuth) {
        if (!$checkAuth($request)) {
            $response->getBody()->write(json_encode(['code' => 401, 'message' => '请先登录 API 文档']));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }

        $specFile = __DIR__ . '/../templates/openapi.json';
        $spec = file_exists($specFile)
            ? json_decode(file_get_contents($specFile), true)
            : ['openapi' => '3.0.3', 'info' => ['title' => 'Devops-Glue API'], 'paths' => []];

        $uri  = $request->getUri();
        $port = $uri->getPort();
        $isDefault = ($uri->getScheme() === 'http'  && $port === 80)
                  || ($uri->getScheme() === 'https' && $port === 443);
        $spec['servers'] = [[
            'url'         => $uri->getScheme() . '://' . $uri->getHost() . (($port && !$isDefault) ? ':' . $port : ''),
            'description' => '当前环境',
        ]];

        $response->getBody()->write(json_encode($spec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        return $response->withHeader('Content-Type', 'application/json');
    });

    $api->group('/main', function (RouteCollectorProxy $main) {
        $main->map(['GET', 'POST'], '/jobs/list', [MainController::class, 'jobsList']);
        $main->map(['GET', 'POST'], '/map/list', [MainController::class, 'mapList']);
        $main->map(['GET', 'POST'], '/git/platforms', [MainController::class, 'gitPlatforms']);
        $main->map(['GET', 'POST'], '/git/discovery', [MainController::class, 'gitDiscovery']);
    });

    $api->group('/admin', function (RouteCollectorProxy $admin) {
        $admin->map(['POST'], '/login', [AdminController::class, 'login']);
        $admin->map(['PUT'], '/password', [AdminController::class, 'changePassword']);
        $admin->map(['GET'], '/job_git_map', [AdminController::class, 'jobGitMapList']);
        $admin->map(['POST'], '/job_git_map', [AdminController::class, 'jobGitMapSave']);
        $admin->map(['PUT'], '/job_git_map', [AdminController::class, 'jobGitMapUpdate']);
        $admin->map(['DELETE'], '/job_git_map', [AdminController::class, 'jobGitMapDelete']);
        $admin->map(['GET'], '/platform_versions', [AdminController::class, 'platformVersionsList']);
        $admin->map(['PUT'], '/platform_versions', [AdminController::class, 'platformVersionsUpdate']);
    });

    $api->group('/build', function (RouteCollectorProxy $build) {
        $build->map(['GET', 'POST'], '/jobs/list', [BuildController::class, 'jobsList']);
        $build->map(['GET'], '/config-mode', [BuildController::class, 'configMode']);
        $build->map(['GET', 'POST'], '/{path:.+}/pipelines', [BuildController::class, 'pipelines']);
        $build->map(['GET', 'POST'], '/{path:.+}/pipelines/{id:\d+}', [BuildController::class, 'pipelineDetail']);
        $build->map(['POST'], '/{path:.+}/pipelines/{id:\d+}/retry', [BuildController::class, 'retry']);
        $build->map(['POST'], '/{path:.+}/pipelines/{id:\d+}/cancel', [BuildController::class, 'cancel']);
        $build->map(['GET', 'POST'], '/{path:.+}/logs/{id:\d+}', [BuildController::class, 'logs']);
        $build->map(['POST'], '/{path:.+}/trigger', [BuildController::class, 'trigger']);
        $build->map(['GET', 'POST'], '/{path:.+}/variables', [BuildController::class, 'variables']);
        $build->map(['POST'], '/{path:.+}/scan-sync', [BuildController::class, 'scanSync']);
        $build->map(['GET', 'POST'], '/{path:.+}/tag', [BuildController::class, 'tagQuery']);
    });

    // Jenkins 路由
    $buildMode = $_ENV['BUILD_MODE'] ?? 'both';
    if ($buildMode === 'gitlab_ci') {
        // 友好提示而非 404
        $api->any('/jenkins[/{path:.*}]', function ($request, $response) {
            $response->getBody()->write(json_encode(['code' => 400, 'message' => 'Jenkins 未配置，当前为 gitlab_ci 模式']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        });
    } else {
    $api->group('/jenkins', function (RouteCollectorProxy $jenkins) {
        $jenkins->map(['POST'], '/{path:[^/]+(?:/[^/]+)?}/build_trigger', [JenkinsController::class, 'buildTrigger']);
        $jenkins->map(['GET', 'POST'], '/{path:.+}/branches', [GitController::class, 'branches']);
        $jenkins->map(['GET', 'POST'], '/{path:.+}/parameters[/{build_id}]', [JenkinsController::class, 'parameters']);
        $jenkins->map(['GET', 'POST'], '/{path:.+}/{type:build|build_id|build_time}', [JenkinsController::class, 'buildList']);
        $jenkins->map(['GET', 'POST'], '/{path:.+}/{build_id}/status', [JenkinsController::class, 'status']);
    });
    } // /if BUILD_MODE !== gitlab_ci

    $api->group('/git', function (RouteCollectorProxy $git) {
        $git->map(['GET', 'POST'], '/{path:.+}/branches', [GitController::class, 'branches']);
    });

    $api->group('/harbor', function (RouteCollectorProxy $harbor) {
        $harbor->map(['GET', 'POST'], '/projects', [HarborController::class, 'getProjectsList']);
        $harbor->map(['GET', 'POST'], '/{project}/repositories', [HarborController::class, 'getRepositoriesList']);
        $harbor->map(['GET', 'POST'], '/{project}/repositories/{repository}/tags', [HarborController::class, 'getTagsList']);
        $harbor->map(['POST'], '/{project}/repositories/{repository}/tags/{tag}/scan', [HarborController::class, 'scanTrigger']);
        $harbor->map(['GET'], '/{project}/repositories/{repository}/tags/{tag}/scan', [HarborController::class, 'getScanReport']);
    });
});