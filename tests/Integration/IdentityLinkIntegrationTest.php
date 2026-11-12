<?php

declare(strict_types=1);

namespace McpPhp\Tests\Integration;

use McpPhp\Exceptions\AuthenticationException;
use McpPhp\OAuth\Identity\ExternalIdentity;
use McpPhp\Tests\Support\DatabaseTestCase;

final class IdentityLinkIntegrationTest extends DatabaseTestCase
{
    private function id(string $email, string $sub = 'sub-1', bool $verified = true, string $provider = 'google'): ExternalIdentity
    {
        return new ExternalIdentity($provider, $sub, $email, $verified, 'Nombre');
    }

    public function testFirstLoginLinksIdentityToAllowedUser(): void
    {
        $user = $this->app->identityLinkService()->resolve($this->id('editor@example.com'));
        $this->assertSame('editor', $user['role']);
        $links = $this->app->userRepository()->oauthIdentitiesOf((int) $user['id']);
        $this->assertCount(1, $links);
        $this->assertSame('google', $links[0]['provider']);
        $this->assertSame('sub-1', $links[0]['external_id']);
        $this->assertSame('active', $links[0]['status']);
        $this->assertCount(1, $this->events('oauth.identity_linked'));
    }

    public function testSecondLoginUsesExistingLinkEvenIfEmailChanged(): void
    {
        $svc = $this->app->identityLinkService();
        $svc->resolve($this->id('editor@example.com'));
        $user = $svc->resolve($this->id('cambiado@gmail.com'));
        $this->assertSame('editor@example.com', $user['email'], 'se identifica por (proveedor, sub), no por el correo');
        $this->assertNotNull($this->app->userRepository()->oauthIdentitiesOf((int) $user['id'])[0]['last_login_at']);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function denied(): iterable
    {
        yield 'correo fuera de allowed_users' => ['stranger@example.com', true];
        yield 'usuario suspendido' => ['suspended@example.com', true];
        yield 'correo no verificado' => ['admin@example.com', false];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('denied')]
    public function testDeniedLogins(string $email, bool $verified): void
    {
        try {
            $this->app->identityLinkService()->resolve($this->id($email, 'x', $verified));
            $this->fail('debio denegarse');
        } catch (AuthenticationException $e) {
            $this->assertStringNotContainsString($email, $e->getMessage(), 'el mensaje al cliente es generico');
        }
        $this->assertCount(1, $this->events('oauth.login_denied'));
        $this->assertSame(0, (int) $this->app->connection()->run('SELECT COUNT(*) FROM oauth_users')->fetchColumn(), 'no se crea ningun vinculo');
    }

    public function testRevokedIdentityIsDeniedAndCanBeRelinkedOnlyByAdmin(): void
    {
        $svc = $this->app->identityLinkService();
        $user = $svc->resolve($this->id('editor@example.com'));
        $this->app->userRepository()->revokeOAuthAuthorizations((int) $user['id']);
        $this->expectException(AuthenticationException::class);
        $svc->resolve($this->id('editor@example.com'));
    }

    public function testSuspendedUserWithExistingLinkIsDenied(): void
    {
        $svc = $this->app->identityLinkService();
        $user = $svc->resolve($this->id('editor@example.com'));
        $this->app->userRepository()->setStatus((int) $user['id'], 'suspended');
        $this->expectException(AuthenticationException::class);
        $svc->resolve($this->id('editor@example.com'));
    }

    public function testSameUserCanLinkTwoProviders(): void
    {
        $svc = $this->app->identityLinkService();
        $svc->resolve($this->id('editor@example.com', 'g-1', true, 'google'));
        $svc->resolve($this->id('editor@example.com', 'm-1', true, 'microsoft'));
        $this->assertCount(2, $this->app->userRepository()->oauthIdentitiesOf($this->userId('editor@example.com')));
    }

    public function testSameExternalIdCannotBelongToTwoUsers(): void
    {
        $svc = $this->app->identityLinkService();
        $svc->resolve($this->id('editor@example.com', 'shared'));
        $user = $svc->resolve($this->id('admin@example.com', 'shared'));
        $this->assertSame('editor@example.com', $user['email'], 'un sub ya vinculado siempre resuelve a su usuario original');
    }
}
