<?php

namespace App\Service;

use GuzzleHttp\Client;

class RegistryProbe
{
    public function __construct(
        private string $baseUrl,
        private string $username = '',
        private string $password = '',
        private ?Client $client = null
    ) {
    }

    private function client(): Client
    {
        if ($this->client !== null) {
            return $this->client;
        }
        return new Client([
            'base_uri'        => rtrim($this->baseUrl, '/'),
            'auth'            => ($this->username !== '' && $this->password !== '')
                ? [$this->username, $this->password]
                : [],
            'timeout'         => 4,
            'connect_timeout' => 3,
            'http_errors'     => false,
        ]);
    }

    public function reachable(string $path): bool
    {
        try {
            $code = $this->client()->get($path)->getStatusCode();
            return $code >= 200 && $code < 500;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function distributionVersion(): ?string
    {
        try {
            $resp = $this->client()->get('/v2/');
            $header = $resp->getHeaderLine('Docker-Distribution-Api-Version');
            if ($header === '') {
                return null;
            }
            $version = preg_replace('#^registry/#i', '', trim($header));
            return $version !== '' ? $version : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function detectedType(): string
    {
        if ($this->endpointExists('/api/v2.0/systeminfo')) {
            return 'harbor';
        }
        return $this->endpointExists('/v2/') ? 'registry' : 'unknown';
    }

    public function endpointExists(string $path): bool
    {
        try {
            $code = $this->client()->get($path)->getStatusCode();
            return $code >= 200 && $code < 404;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
