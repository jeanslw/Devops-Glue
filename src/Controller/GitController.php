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
}
