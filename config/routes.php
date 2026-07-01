<?php
use Slim\Routing\RouteCollectorProxy;
use App\Controller\JenkinsController;
use App\Controller\GitController;
use App\Controller\MainController;
use App\Controller\HarborController;

$app->group('/api', function (RouteCollectorProxy $api) {

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