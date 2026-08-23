<?php
declare(strict_types=1);

namespace App\Test\Unit;

use App\Service\OidcService;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use PHPUnit\Framework\TestCase;

/**
 * OidcService 单测：锁死 RS256 id_token 签发、JWKS 公钥发布、Discovery 文档、
 * 密钥持久化稳定性，以及 groups 角色映射。
 *
 * 关键回归点：id_token 必须能仅凭发布的 jwks.json（n/e/kid）被标准 OIDC 客户端验签——
 * 这正是 Harbor / Jenkins oic-auth / GitLab OmniAuth 的消费方式。
 */
class OidcServiceTest extends TestCase
{
    private function makeService(array $config = []): OidcService
    {
        return new OidcService($config);
    }

    public function testIdTokenRoundTripAndClaims(): void
    {
        $svc = $this->makeService(['id_token_ttl' => 3600]);
        $token = $svc->signIdToken([
            'iss'                => 'https://glue.example.com',
            'sub'                => 'alice',
            'name'               => 'alice',
            'preferred_username' => 'alice',
            'email'              => 'alice@example.com',
            'email_verified'     => true,
            'groups'             => ['ci_admin'],
        ], 'jenkins-client', 'nonce-abc');

        $decoded = JWT::decode($token, new Key($svc->publicKeyPem(), 'RS256'));
        $this->assertSame('https://glue.example.com', $decoded->iss);
        $this->assertSame('alice', $decoded->sub);
        $this->assertSame('jenkins-client', $decoded->aud);
        $this->assertSame('nonce-abc', $decoded->nonce);
        $this->assertSame('alice@example.com', $decoded->email);
        $this->assertTrue($decoded->email_verified);
        $this->assertSame(['ci_admin'], $decoded->groups);
        $this->assertIsNumeric($decoded->iat);
        $this->assertIsNumeric($decoded->exp);
        $this->assertGreaterThan($decoded->iat, $decoded->exp);
    }

    public function testIdTokenVerifiesViaPublishedJwks(): void
    {
        $svc = $this->makeService();
        $token = $svc->signIdToken([
            'iss'    => 'https://glue.example.com',
            'sub'    => 'alice',
            'groups' => ['admin'],
        ], 'harbor', null);

        // 完全模拟标准 OIDC 客户端：只靠 jwks.json 拿公钥验签
        $keySet = JWK::parseKeySet($svc->jwks());
        $this->assertArrayHasKey($svc->keyId(), $keySet);

        $decoded = JWT::decode($token, $keySet);
        $this->assertSame('alice', $decoded->sub);
        $this->assertSame(['admin'], $decoded->groups);
    }

    public function testJwksExposesOnlyPublicRsaKey(): void
    {
        $svc = $this->makeService();
        $jwks = $svc->jwks();

        $this->assertCount(1, $jwks['keys']);
        $key = $jwks['keys'][0];
        $this->assertSame('RSA', $key['kty']);
        $this->assertSame('sig', $key['use']);
        $this->assertSame('RS256', $key['alg']);
        $this->assertSame($svc->keyId(), $key['kid']);
        $this->assertNotEmpty($key['n']);
        $this->assertNotEmpty($key['e']);
        $this->assertArrayNotHasKey('d', $key); // 绝不含私钥参数
    }

    public function testKeyIsStableAcrossInstancesWithSameKeyFile(): void
    {
        $file = sys_get_temp_dir() . '/devops_glue_oidc_test_' . uniqid() . '.pem';
        try {
            $first = $this->makeService(['key_file' => $file]);
            $kid1  = $first->keyId();
            $n1    = $first->jwks()['keys'][0]['n'];

            $second = $this->makeService(['key_file' => $file]);
            $this->assertSame($kid1, $second->keyId());
            $this->assertSame($n1, $second->jwks()['keys'][0]['n']);
            $this->assertFileExists($file);
        } finally {
            @unlink($file);
        }
    }

    public function testDiscoveryDocument(): void
    {
        $svc = $this->makeService();
        $doc = $svc->discovery('https://glue.example.com');

        $this->assertSame('https://glue.example.com', $doc['issuer']);
        $this->assertSame('https://glue.example.com/oauth/authorize', $doc['authorization_endpoint']);
        $this->assertSame('https://glue.example.com/oauth/token', $doc['token_endpoint']);
        $this->assertSame('https://glue.example.com/oauth/userinfo', $doc['userinfo_endpoint']);
        $this->assertSame('https://glue.example.com/.well-known/jwks.json', $doc['jwks_uri']);
        $this->assertContains('code', $doc['response_types_supported']);
        $this->assertContains('RS256', $doc['id_token_signing_alg_values_supported']);
        $this->assertContains('openid', $doc['scopes_supported']);
        $this->assertContains('groups', $doc['claims_supported']);
    }

    public function testIssuerConfig(): void
    {
        $this->assertSame('https://glue.example.com', $this->makeService(['issuer' => 'https://glue.example.com/'])->issuer());
        $this->assertSame('', $this->makeService()->issuer());
    }

    public function testGroupsFromRole(): void
    {
        $svc = $this->makeService();
        $this->assertSame(['ci_admin'], $svc->groupsFromRole('ci_admin'));
        $this->assertSame([], $svc->groupsFromRole(''));
        $this->assertSame([], $svc->groupsFromRole('   '));
    }
}
