<?php
use Slim\Routing\RouteCollectorProxy;
use App\Controller\JenkinsController;
use App\Controller\GitController;
use App\Controller\MainController;
use App\Controller\HarborController;

$app->group('/api', function (RouteCollectorProxy $api) {

    // 健康检查
    $api->map(['GET'], '/health', [MainController::class, 'health']);

    // API 文档 (Swagger UI)
    $api->get('/docs', function ($request, $response) {
        $htmlFile = __DIR__ . '/../templates/swagger.html';
        $response->getBody()->write(file_exists($htmlFile)
            ? file_get_contents($htmlFile)
            : '<h1>文档文件丢失</h1>');
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    });

    // OpenAPI 规范
    $api->get('/openapi.json', function ($request, $response) {
        $specFile = __DIR__ . '/../templates/openapi.json';
        $response->getBody()->write(file_exists($specFile)
            ? file_get_contents($specFile)
            : '{}');
        return $response->withHeader('Content-Type', 'application/json');
    });

    $api->group('/main', function (RouteCollectorProxy $main) {
        $main->map(['GET', 'POST'], '/jobs/list', [MainController::class, 'jobsList']);
        $main->map(['GET', 'POST'], '/map/list', [MainController::class, 'mapList']);
        $main->map(['GET', 'POST'], '/git/platforms', [MainController::class, 'gitPlatforms']);
        $main->map(['GET', 'POST'], '/git/discovery', [MainController::class, 'gitDiscovery']);
    });

    $api->group('/jenkins', function (RouteCollectorProxy $jenkins) {
        $jenkins->map(['POST'], '/{path:[^/]+(?:/[^/]+)?}/build_trigger', [JenkinsController::class, 'buildTrigger']);
        $jenkins->map(['GET', 'POST'], '/{path:.+}/branches', [GitController::class, 'branches']);
        $jenkins->map(['GET', 'POST'], '/{path:.+}/parameters[/{build_id}]', [JenkinsController::class, 'parameters']);
        $jenkins->map(['GET', 'POST'], '/{path:.+}/{type:build|build_id|build_time}', [JenkinsController::class, 'buildList']);
        $jenkins->map(['GET', 'POST'], '/{path:.+}/{build_id}/status', [JenkinsController::class, 'status']);
        $jenkins->map(['GET', 'POST'], '/{path:.+}/{build_id}/console', [JenkinsController::class, 'console']);
    });

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