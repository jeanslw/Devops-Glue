<?php
namespace App\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Service\GitService;

class GitController
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
            $response->getBody()->write(json_encode($branches));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }
    }
}