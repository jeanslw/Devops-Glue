<?php

namespace App\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Service\GitService;
use App\Service\I18nService;
use App\Config\AppConfig;

class GitController extends BaseController
{
    private GitService $git;

    public function __construct(I18nService $i18n, GitService $git)
    {
        parent::__construct($i18n);
        $this->git = $git;
    }

    /**
     * Job 绑定仓库的分支列表。
     *
     * @param array<string,string> $args 路由参数
     */
    public function branches(Request $request, Response $response, array $args): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_BUILD_RECORDS_PULL)) {
            return $resp;
        }

        $path = $args['path'] ?? '';
        try {
            $branches = $this->git->getBranchesForJob($path);
            return $this->output($response, $branches, $request);
        } catch (\Exception $e) {
            return $this->jsonError($response, $this->__('git.query_failed'), 404);
        }
    }

    /**
     * Job 绑定仓库的 tag 列表。
     *
     * 与 branches 对称：CD 构建页可把 tag 当作构建 ref（发布版本按 tag 构建）。
     * 平台不可达时与分支查询一样回 404 + git.query_failed（不区分「查不到」与「查询失败」，
     * 避免把平台凭据/网络问题当成「这个仓库没有 tag」）。
     *
     * @param array<string,string> $args 路由参数
     */
    public function tags(Request $request, Response $response, array $args): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_BUILD_RECORDS_PULL)) {
            return $resp;
        }

        $path = $args['path'] ?? '';
        try {
            return $this->output($response, $this->git->getTagsForJob($path), $request);
        } catch (\Exception $e) {
            return $this->jsonError($response, $this->__('git.query_failed'), 404);
        }
    }

    /**
     * branches + tags 合并端点，响应形如 {"branches": [...], "tags": [...]}。
     *
     * CD 构建页的 ref 下拉要的是「分支 + tag」全量候选值：走这里只需一次 job→仓库映射解析
     * 与一次平台客户端构造，且两组值必然来自同一仓库快照。
     *
     * @param array<string,string> $args 路由参数
     */
    public function refs(Request $request, Response $response, array $args): Response
    {
        $this->initAuthFromRequest($request);
        if ($resp = $this->requirePermission($response, AppConfig::PERM_CI_BUILD_RECORDS_PULL)) {
            return $resp;
        }

        $path = $args['path'] ?? '';
        try {
            return $this->output($response, $this->git->getRefsForJob($path), $request);
        } catch (\Exception $e) {
            return $this->jsonError($response, $this->__('git.query_failed'), 404);
        }
    }
}
