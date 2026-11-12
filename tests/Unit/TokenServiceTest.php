<?php

declare(strict_types=1);

namespace McpPhp\Tests\Unit;

use McpPhp\Exceptions\AuthenticationException;
use McpPhp\Exceptions\NotFoundException;
use McpPhp\Exceptions\ValidationException;
use McpPhp\Logging\SecurityLogger;
use McpPhp\Tests\Support\FrozenClock;
use McpPhp\Tests\Support\InMemoryRepositories;
use McpPhp\Tokens\TokenGenerator;
use McpPhp\Tokens\TokenService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class TokenServiceTest extends TestCase
{
    private const KEY = 'unit-test-app-key-0123456789abcdef-0123456789';

    private FrozenClock $clock;
    private TokenService $svc;
    private \McpPhp\Repositories\TokenRepository $repo;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock();
        $this->repo = InMemoryRepositories::tokens();
        $users = InMemoryRepositories::users([
            ['id' => 1, 'email' => 'admin@x.test', 'name' => 'Admin', 'role' => 'admin', 'status' => 'active'],
            ['id' => 2, 'email' => 'susp@x.test', 'name' => 'Susp', 'role' => 'viewer', 'status' => 'suspended'],
        ]);
        $this->svc = new TokenService($this->repo, $users, new TokenGenerator(self::KEY), new SecurityLogger(new NullLogger()), $this->clock);
    }

    public function testIssuedTokenAuthenticates(): void
    {
        $issued = $this->svc->issue(1, 'test', 3600);
        $p = $this->svc->authenticate($issued['token']);
        $this->assertSame(1, $p->userId);
        $this->assertSame('token', $p->authMethod);
        $this->assertCount(3, $p->effectiveScopes());
    }

    public function testPlainTokenIsNeverStored(): void
    {
        $issued = $this->svc->issue(1, 'test', 3600);
        $stored = json_encode($this->repo->rows);
        $this->assertStringNotContainsString($issued['token'], $stored);
        $this->assertStringNotContainsString(substr($issued['token'], 8), $stored);
        $this->assertSame(64, strlen($this->repo->rows[1]['token_hash']));
    }

    public function testExpiredTokenIsRejected(): void
    {
        $issued = $this->svc->issue(1, 'test', 3600);
        $this->clock->advance('+3601 seconds');
        $this->expectException(AuthenticationException::class);
        $this->svc->authenticate($issued['token']);
    }

    public function testTokenIsValidUntilExactExpiry(): void
    {
        $issued = $this->svc->issue(1, 'test', 3600);
        $this->clock->advance('+3599 seconds');
        $this->assertSame(1, $this->svc->authenticate($issued['token'])->userId);
    }

    public function testRevokedTokenIsRejected(): void
    {
        $issued = $this->svc->issue(1, 'test', 3600);
        $this->svc->revoke($issued['id']);
        $this->expectException(AuthenticationException::class);
        $this->svc->authenticate($issued['token']);
    }

    public function testRevokingTwiceFails(): void
    {
        $issued = $this->svc->issue(1, 'test', 3600);
        $this->svc->revoke($issued['id']);
        $this->expectException(NotFoundException::class);
        $this->svc->revoke($issued['id']);
    }

    public function testUnknownAndMalformedTokensAreRejectedWithSameMessage(): void
    {
        $messages = [];
        foreach (['', 'garbage', 'mcp_' . str_repeat('A', 43), 'Bearer x'] as $bad) {
            try {
                $this->svc->authenticate($bad);
                $this->fail('debio fallar');
            } catch (AuthenticationException $e) {
                $messages[] = $e->getMessage();
            }
        }
        $this->assertCount(1, array_unique($messages), 'el mensaje no debe revelar el motivo');
    }

    public function testTokenOfSuspendedUserIsRejected(): void
    {
        // se emite directamente en el repositorio para saltar la validacion de emision
        $gen = new TokenGenerator(self::KEY);
        $plain = $gen->generate();
        $this->repo->create(2, $gen->hash($plain), 'x', 'records:read', $this->clock->now()->modify('+1 day'));
        $this->expectException(AuthenticationException::class);
        $this->svc->authenticate($plain);
    }

    public function testCannotIssueForInactiveOrUnknownUser(): void
    {
        try {
            $this->svc->issue(2, 'x', 3600);
            $this->fail();
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(NotFoundException::class);
        $this->svc->issue(99, 'x', 3600);
    }

    public function testIssueValidatesTtlAndScopes(): void
    {
        foreach ([[10, null], [999999999, null], [3600, ['records:nuke']], [3600, []]] as [$ttl, $scopes]) {
            try {
                $this->svc->issue(1, 'x', $ttl, $scopes);
                $this->fail('debio fallar');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testTokenWithDifferentKeyIsRejected(): void
    {
        $issued = $this->svc->issue(1, 'x', 3600);
        $other = new TokenService($this->repo, InMemoryRepositories::users([]), new TokenGenerator(str_repeat('z', 40)), new SecurityLogger(new NullLogger()), $this->clock);
        $this->expectException(AuthenticationException::class);
        $other->authenticate($issued['token']);
    }

    public function testGeneratorRequiresStrongKey(): void
    {
        $this->expectException(\McpPhp\Exceptions\ConfigurationException::class);
        new TokenGenerator('short');
    }

    public function testListMarksExpiredTokens(): void
    {
        $this->svc->issue(1, 'x', 60);
        $this->clock->advance('+2 minutes');
        $this->assertSame('expired', $this->svc->list()[0]['status']);
    }
}
