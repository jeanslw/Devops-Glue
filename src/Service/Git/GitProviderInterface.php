<?php
namespace App\Service\Git;

interface GitProviderInterface {
    public function getBranches(string $repository): array;
}