<?php
namespace App\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Service\GitService;

class GitController extends BaseController
{
    private GitService $git;

    public function __construct(GitService $git)
    {
        $this->git = $git;
    }

    public function branches(Request $request, Response $response, array $args): Response
    {
        $path = $args['path'] ?? '';
        try {
            $branches = $this->git->getBranchesForJob($path);
            return $this->output($response, $branches, $request);
        } catch (\Exception $e) {
            return $this->jsonError($response, $e->getMessage(), 404);
        }
    }
}