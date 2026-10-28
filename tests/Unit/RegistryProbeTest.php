<?php

declare(strict_types=1);

namespace App\Test\Unit;

use App\Service\RegistryProbe;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class RegistryProbeTest extends TestCase
{
    private function clientWith(array $responses): Client
    {
        return new Client([
            'handler' => HandlerStack::create(new MockHandler($responses)),
            'http_errors' => false,
        ]);
    }

    public function testDistributionVersionParsesRegistryHeader(): void
    {
        $client = $this->clientWith([
            new Response(401, ['Docker-Distribution-Api-Version' => 'registry/2.0']),
        ]);
        $probe = new RegistryProbe('https://reg.example.com', '', '', $client);
        $this->assertSame('2.0', $probe->distributionVersion());
    }

    public function testDistributionVersionReturnsNullWhenHeaderMissing(): void
    {
        $client = $this->clientWith([new Response(200, [])]);
        $probe = new RegistryProbe('https://reg.example.com', '', '', $client);
        $this->assertNull($probe->distributionVersion());
    }

    public function testDetectedTypeHarbor(): void
    {
        $client = $this->clientWith([
            new Response(200),
            new Response(200),
        ]);
        $probe = new RegistryProbe('https://reg.example.com', '', '', $client);
        $this->assertSame('harbor', $probe->detectedType());
    }

    public function testDetectedTypeRegistry(): void
    {
        $client = $this->clientWith([
            new Response(404),
            new Response(401, ['Docker-Distribution-Api-Version' => 'registry/2.0']),
        ]);
        $probe = new RegistryProbe('https://reg.example.com', '', '', $client);
        $this->assertSame('registry', $probe->detectedType());
    }

    public function testDetectedTypeUnknownWhenUnreachable(): void
    {
        $client = $this->clientWith([
            new Response(503),
            new Response(503),
        ]);
        $probe = new RegistryProbe('https://reg.example.com', '', '', $client);
        $this->assertSame('unknown', $probe->detectedType());
    }

    public function testReachableHonorsStatusCode(): void
    {
        $client = $this->clientWith([new Response(200), new Response(500)]);
        $probe = new RegistryProbe('https://reg.example.com', '', '', $client);
        $this->assertTrue($probe->reachable('/v2/'));
        $this->assertFalse($probe->reachable('/api/v2.0/systeminfo'));
    }
}
