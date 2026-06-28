<?php

use Slim\App;
use Slim\Interfaces\RouteCollectorProxyInterface as RouteCollectorProxy;
use App\Controller\JenkinsController;
use App\Controller\HarborController;
use App\Controller\GitController;

// 定义正则规则
$projectRegex = '[a-zA-Z0-9_\-]+';
// 注意：Harbor 仓库名可能包含斜杠，允许 % 和 /  
$repoRegex = '.+'; 

return function (App $app) use ($projectRegex, $repoRegex) {
    
    // ==========================================
    // 0. 全局接口
    // ==========================================
    $app->group('/api/main', function (RouteCollectorProxy $group) {         
        $group->get('/jobs/list', [JenkinsController::class, 'getJobsList']);
        $group->get('/map/list', [JenkinsController::class, 'getJobGitList']);
    });

    // ==========================================
    // 1. Jenkins 接口路由
    // ==========================================
    // ==========================================
    // 1. Jenkins 接口路由
    // ==========================================
    $app->group('/api/jenkins', function (RouteCollectorProxy $group) use ($projectRegex) {
        
        // ==========================================
        // 【1】：先定义一级 Job (无 group，参数较少)
        // ==========================================
        $group->post('/{branches}/{zone}/build_trigger', [JenkinsController::class, 'triggerBuild']);

        $group->get('/{project:'.$projectRegex.'}/{build_id:\d+}/status', [JenkinsController::class, 'getBuildStatus']);
        $group->get('/{project:'.$projectRegex.'}/{build_id:\d+}/parameters', [JenkinsController::class, 'getParametersList']);
        $group->get('/{project:'.$projectRegex.'}/{build_id:\d+}/console', [JenkinsController::class, 'getConsoleOutput']);
        $group->get('/{project:'.$projectRegex.'}/parameters', [JenkinsController::class, 'getParametersList']);
        $group->get('/{project:'.$projectRegex.'}/{type:build_id|build_time|build}', [JenkinsController::class, 'getBuildList']);
        $group->get('/{project:'.$projectRegex.'}/branches', [JenkinsController::class, 'getBranchesList']);

        // ==========================================
        // 【2】：二级 Job (带 group，参数较多)
        // ==========================================
       // $group->post('/{group}/{project:'.$projectRegex.'}/{branches}/{zone}/build_trigger', [JenkinsController::class, 'triggerBuild']);
        
        $group->get('/{group}/{project:'.$projectRegex.'}/{build_id:\d+}/status', [JenkinsController::class, 'getBuildStatus']);
        $group->get('/{group}/{project:'.$projectRegex.'}/{build_id:\d+}/parameters', [JenkinsController::class, 'getParametersList']);
        $group->get('/{group}/{project:'.$projectRegex.'}/{build_id:\d+}/console', [JenkinsController::class, 'getConsoleOutput']);
        $group->get('/{group}/{project:'.$projectRegex.'}/parameters', [JenkinsController::class, 'getParametersList']); 
        $group->get('/{group}/{project:'.$projectRegex.'}/{type:build_id|build_time|build}', [JenkinsController::class, 'getBuildList']);
        });
    // ==========================================
    // 2. Git 接口路由 (专注代码仓库、分支、Tag)
    // ==========================================
        $app->group('/api/git', function (RouteCollectorProxy $group) use ($projectRegex) {
            
            // 查询一级 Job 对应的 Git 仓库分支列表
            $group->get('/{project:'.$projectRegex.'}/branches', [GitController::class, 'getBranchesList']);
            
            // 查询二级 Job 对应的 Git 仓库分支列表
            $group->get('/{group}/{project:'.$projectRegex.'}/branches', [GitController::class, 'getBranchesList']);
            
            // 💡 扩展预留：以后如果查代码仓库的 Tag、Commit 记录，都可以直接加在这里
            // $group->get('/{project}/tags', [GitController::class, 'getTagsList']);
            // $group->get('/{project}/commits', [GitController::class, 'getCommitsList']);
        });

    // ==========================================
    // 3. Harbor 接口路由
    // ==========================================
    $app->group('/api/harbor', function (RouteCollectorProxy $group) use ($projectRegex, $repoRegex) {

        // 1. 查询所有项目列表
        $group->map(['GET', 'POST'], '/projects', [HarborController::class, 'getProjectsList']);

        // 2. 查询指定项目下的所有镜像仓库列表
        $group->map(['GET', 'POST'], '/{project:' . $projectRegex . '}/repositories', [HarborController::class, 'getRepositoriesList']);

        // 3. 查询指定镜像仓库下的所有 Tag 列表 (支持仓库名带 '/')
        $group->map(['GET', 'POST'], '/{project:' . $projectRegex . '}/repositories/{repository:' . $repoRegex . '}/tags', [HarborController::class, 'getTagsList']);
        
    });
};