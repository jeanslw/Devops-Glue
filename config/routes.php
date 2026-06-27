<?php

use Slim\App;
use App\Controller\JenkinsController;

return function (App $app) {
          
    // ==========================================
    // 1. Jenkins 接口路由 (正则约束防冲突版)
    // ==========================================
    $app->group('/api/jenkins', function ($group) {
        
        // --- 全局接口 (无动态参数) ---
        $group->get('/jobs/list', [JenkinsController::class, 'getJobsList']);
        $group->get('/job/git/list', [JenkinsController::class, 'getJobGitList']);

        // ==========================================
        // 核心改造：使用 {build_id:\d+} 强制要求 build_id 必须是数字！
        // 这样 FastRoute 就能完美区分 /{group}/{project}/... 和 /{project}/{build_id}/...
        // ==========================================

        // --- 带 group 的接口 ---
        $group->get('/{group}/{project}/{build_id:\d+}/status', [JenkinsController::class, 'getBuildStatus']);
        $group->get('/{group}/{project}/{build_id:\d+}/parameters/list', [JenkinsController::class, 'getParametersList']);
        $group->get('/{group}/{project}/{build_id:\d+}/console', [JenkinsController::class, 'getConsoleOutput']);
        // 去掉 {project}，branches 和 zone 保持单级在 URL 中
        $group->post('/{group}/{branches}/{zone}/build_trigger', [JenkinsController::class, 'triggerBuild']);
        
        // 不带 build_id (获取列表或最新参数)
        $group->get('/{group}/{project}/parameters/list', [JenkinsController::class, 'getParametersList']); 
        $group->get('/{group}/{project}/build_id/list', [JenkinsController::class, 'getBuildIdList']);
        $group->get('/{group}/{project}/build_time/list', [JenkinsController::class, 'getBuildTimeList']);
        $group->get('/{group}/{project}/build/list', [JenkinsController::class, 'getBuildList']);
        $group->get('/{group}/{project}/branches/list', [JenkinsController::class, 'getBranchesList']);


        // --- 兼容无 group (仅 project) 的接口 ---
        // 带 build_id (必须是数字，靠这个与上面的路由区分开！)
        $group->get('/{project}/{build_id:\d+}/status', [JenkinsController::class, 'getBuildStatus']);
        $group->get('/{project}/{build_id:\d+}/parameters/list', [JenkinsController::class, 'getParametersList']);
        $group->get('/{project}/{build_id:\d+}/console', [JenkinsController::class, 'getConsoleOutput']);
        // --- 兼容无 group (仅 project) 的接口 ---
        $group->post('/{branches}/{zone}/build_trigger', [JenkinsController::class, 'triggerBuild']);

        // 不带 build_id
        $group->get('/{project}/parameters/list', [JenkinsController::class, 'getParametersList']);
        $group->get('/{project}/build_id/list', [JenkinsController::class, 'getBuildIdList']);
        $group->get('/{project}/build_time/list', [JenkinsController::class, 'getBuildTimeList']);
        $group->get('/{project}/build/list', [JenkinsController::class, 'getBuildList']);
        $group->get('/{project}/branches/list', [JenkinsController::class, 'getBranchesList']);
    });


    // ==========================================
    // 2. Harbor 接口路由 (新增)
    // ==========================================
    $app->group('/api/harbor', function ($group) {
        // 查询类全部使用 GET
        $group->get('/projects/list', [HarborController::class, 'getProjectsList']);
        $group->get('/{project}/repositories/list', [HarborController::class, 'getRepositoriesList']);
        $group->get('/{project}/{repository}/tags/list', [HarborController::class, 'getTagsList']);
        $group->get('/{project}/{repository}/{tag}/info', [HarborController::class, 'getTagInfo']);
        
        // 动作类使用 POST
        $group->post('/{project}/{repository}/{tag}/scan', [HarborController::class, 'triggerScan']);
    });
};