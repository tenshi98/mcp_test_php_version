<?php

declare(strict_types=1);

namespace McpPhp\OAuth\Repositories;

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;
use McpPhp\Authorization\Role;
use McpPhp\OAuth\Entities\ScopeEntity;
use McpPhp\Repositories\UserRepository;

final class ScopeRepository implements ScopeRepositoryInterface
{
    /** @param list<string> $supported */
    public function __construct(private readonly array $supported, private readonly UserRepository $users)
    {
    }

    public function getScopeEntityByIdentifier(string $identifier): ?ScopeEntityInterface
    {
        return in_array($identifier, $this->supported, true) ? new ScopeEntity($identifier) : null;
    }

    /**
     * Limita los scopes concedidos a los permisos del rol del usuario (un "viewer" nunca
     * obtiene records:write aunque lo pida) y, si no se pidio ninguno, concede todos los permitidos.
     */
    public function finalizeScopes(array $scopes, string $grantType, ClientEntityInterface $clientEntity, ?string $userIdentifier = null, ?string $authCodeId = null): array
    {
        if ($userIdentifier === null) {
            return $scopes; // aun no hay usuario (fase de validacion): se ajusta al completar la autorizacion
        }
        $user = $this->users->findById((int) $userIdentifier);
        $role = $user !== null ? Role::tryFrom((string) $user['role']) : null;
        if ($role === null) {
            return [];
        }
        $allowed = array_map(static fn ($p) => $p->value, $role->permissions());
        $requested = array_map(static fn (ScopeEntityInterface $s) => $s->getIdentifier(), $scopes);
        $granted = $requested === [] ? $allowed : array_values(array_intersect($requested, $allowed));

        return array_map(static fn (string $s) => new ScopeEntity($s), $granted);
    }
}
