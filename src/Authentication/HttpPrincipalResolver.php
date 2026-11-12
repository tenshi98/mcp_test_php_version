<?php

declare(strict_types=1);

namespace McpPhp\Authentication;

use Mcp\Server\Authorization\AccessToken;
use McpPhp\Authorization\Principal;
use McpPhp\Exceptions\AuthenticationException;
use McpPhp\Repositories\UserRepository;

/** HTTP: el AccessToken ya fue validado (firma, expiracion, audiencia, revocacion) por el middleware. */
final class HttpPrincipalResolver implements PrincipalResolverInterface
{
    public function __construct(private readonly UserRepository $users)
    {
    }

    public function resolve(?AccessToken $accessToken): Principal
    {
        if ($accessToken === null) {
            throw new AuthenticationException('Se requiere autenticacion.');
        }
        $sub = $accessToken->getSubject();
        $user = ($sub !== null && ctype_digit($sub)) ? $this->users->findById((int) $sub) : null;
        if ($user === null) {
            throw new AuthenticationException('Usuario no autorizado.');
        }

        return PrincipalFactory::fromUser($user, $accessToken->getScopes(), (string) ($accessToken->getClaim('auth_method') ?? 'oauth'));
    }
}
