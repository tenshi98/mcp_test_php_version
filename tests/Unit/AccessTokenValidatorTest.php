<?php

declare(strict_types=1);

namespace McpPhp\Tests\Unit;

use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use McpPhp\Logging\SecurityLogger;
use McpPhp\OAuth\AccessTokenValidator;
use McpPhp\Tests\Support\FrozenClock;
use McpPhp\Tests\Support\InMemoryRepositories;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** Valida el servidor de recursos: firma, emisor, audiencia, vigencia, revocacion y usuario activo. */
final class AccessTokenValidatorTest extends TestCase
{
    private const ISSUER = 'https://mcp.example.test';
    private const RESOURCE = 'https://mcp.example.test/mcp';

    private string $privatePem;
    private string $publicPem;
    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock();
        [$this->privatePem, $this->publicPem] = self::keyPair();
    }

    /** @return array{string, string} */
    private static function keyPair(): array
    {
        $k = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($k, $priv);

        return [$priv, openssl_pkey_get_details($k)['key']];
    }

    private function validator(bool $revoked = false, string $userStatus = 'active'): AccessTokenValidator
    {
        $repo = $this->createStub(AccessTokenRepositoryInterface::class);
        $repo->method('isAccessTokenRevoked')->willReturn($revoked);
        $users = InMemoryRepositories::users([['id' => 7, 'email' => 'u@x.test', 'name' => 'U', 'role' => 'editor', 'status' => $userStatus]]);

        return new AccessTokenValidator($this->publicPem, self::ISSUER, self::RESOURCE, $repo, $users, new SecurityLogger(new NullLogger()), $this->clock);
    }

    /** @param array<string, mixed> $o */
    private function token(array $o = []): string
    {
        $now = $this->clock->now();
        $cfg = Configuration::forAsymmetricSigner(new Sha256(), InMemory::plainText($o['key'] ?? $this->privatePem), InMemory::plainText($this->publicPem));
        $b = $cfg->builder()
            ->issuedBy($o['iss'] ?? self::ISSUER)
            ->permittedFor($o['aud'] ?? self::RESOURCE)
            ->identifiedBy('jti-1')
            ->issuedAt($now->modify($o['iat'] ?? '-1 minute'))
            ->canOnlyBeUsedAfter($now->modify($o['nbf'] ?? '-1 minute'))
            ->expiresAt($now->modify($o['exp'] ?? '+1 hour'))
            ->relatedTo($o['sub'] ?? '7')
            ->withClaim('client_id', 'client-1')
            ->withClaim('scopes', $o['scopes'] ?? ['records:read']);

        return $b->getToken($cfg->signer(), $cfg->signingKey())->toString();
    }

    public function testValidTokenIsAllowedWithScopesAndClaims(): void
    {
        $r = $this->validator()->validate($this->token(['scopes' => ['records:read', 'records:write']]));
        $this->assertTrue($r->isAllowed());
        $t = $r->getAccessToken();
        $this->assertSame(['records:read', 'records:write'], $t->getScopes());
        $this->assertSame('7', $t->getSubject());
        $this->assertSame('client-1', $t->getClientId());
    }

    public function testExpiredTokenIsRejected(): void
    {
        $r = $this->validator()->validate($this->token(['exp' => '-1 second']));
        $this->assertFalse($r->isAllowed());
        $this->assertSame(401, $r->getStatusCode());
        $this->assertSame('invalid_token', $r->getError());
    }

    public function testTokenForAnotherAudienceIsRejected(): void
    {
        $this->assertFalse($this->validator()->validate($this->token(['aud' => 'https://otro.example.test/mcp']))->isAllowed());
    }

    public function testTokenFromAnotherIssuerIsRejected(): void
    {
        $this->assertFalse($this->validator()->validate($this->token(['iss' => 'https://evil.example.test']))->isAllowed());
    }

    public function testTokenSignedWithAnotherKeyIsRejected(): void
    {
        [$otherPriv] = self::keyPair();
        $this->assertFalse($this->validator()->validate($this->token(['key' => $otherPriv]))->isAllowed());
    }

    public function testTamperedPayloadIsRejected(): void
    {
        [$h, $p, $s] = explode('.', $this->token());
        $claims = json_decode(base64_decode(strtr($p, '-_', '+/')), true);
        $claims['scopes'] = ['records:delete'];
        $forged = $h . '.' . rtrim(strtr(base64_encode(json_encode($claims)), '+/', '-_'), '=') . '.' . $s;
        $this->assertFalse($this->validator()->validate($forged)->isAllowed());
    }

    public function testAlgNoneTokenIsRejected(): void
    {
        $b64 = static fn (array $a) => rtrim(strtr(base64_encode(json_encode($a)), '+/', '-_'), '=');
        $now = $this->clock->now()->getTimestamp();
        $jwt = $b64(['alg' => 'none', 'typ' => 'JWT']) . '.' . $b64(['iss' => self::ISSUER, 'aud' => self::RESOURCE, 'sub' => '7', 'jti' => 'x', 'iat' => $now - 60, 'nbf' => $now - 60, 'exp' => $now + 3600, 'scopes' => ['records:delete']]) . '.';
        $this->assertFalse($this->validator()->validate($jwt)->isAllowed());
    }

    public function testNotYetValidTokenIsRejected(): void
    {
        $this->assertFalse($this->validator()->validate($this->token(['nbf' => '+10 minutes', 'iat' => '+10 minutes']))->isAllowed());
    }

    public function testRevokedTokenIsRejected(): void
    {
        $this->assertFalse($this->validator(revoked: true)->validate($this->token())->isAllowed());
    }

    public function testTokenOfInactiveUserIsRejected(): void
    {
        $this->assertFalse($this->validator(userStatus: 'suspended')->validate($this->token())->isAllowed());
    }

    public function testTokenOfUnknownUserIsRejected(): void
    {
        $this->assertFalse($this->validator()->validate($this->token(['sub' => '999']))->isAllowed());
    }

    public function testGarbageIsRejected(): void
    {
        foreach (['', 'abc', 'a.b.c', 'mcp_notajwt'] as $g) {
            $this->assertFalse($this->validator()->validate($g)->isAllowed(), $g);
        }
    }

    public function testRevocationStoreOutageYieldsServiceUnavailable(): void
    {
        $repo = $this->createStub(AccessTokenRepositoryInterface::class);
        $repo->method('isAccessTokenRevoked')->willThrowException(new \RuntimeException('db down'));
        $v = new AccessTokenValidator($this->publicPem, self::ISSUER, self::RESOURCE, $repo, InMemoryRepositories::users([]), new SecurityLogger(new NullLogger()), $this->clock);
        $this->assertSame(503, $v->validate($this->token())->getStatusCode());
    }
}
