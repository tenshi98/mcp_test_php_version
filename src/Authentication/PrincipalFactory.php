<?php

declare(strict_types=1);

namespace McpPhp\Authentication;

use McpPhp\Authorization\Principal;
use McpPhp\Authorization\Role;
use McpPhp\Exceptions\AuthenticationException;

final class PrincipalFactory
{
    /**
     * @param array<string, mixed> $user fila de allowed_users
     * @param list<string> $scopes
     * @throws AuthenticationException si el usuario no esta activo
     */
    public static function fromUser(array $user, array $scopes, string $method): Principal
    {
        if (($user['status'] ?? '') !== 'active') {
            throw new AuthenticationException('Usuario no autorizado.');
        }
        $role = Role::tryFrom((string) $user['role']) ?? throw new AuthenticationException('Usuario no autorizado.');

        return new Principal((int) $user['id'], (string) $user['email'], (string) $user['name'], $role, $scopes, $method);
    }
}
