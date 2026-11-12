<?php

declare(strict_types=1);

namespace McpPhp\Authorization;

/**
 * Identidad autenticada que ejecuta una herramienta.
 * Permisos efectivos = permisos del rol  INTERSECCION  scopes del token.
 */
final class Principal
{
    /** @var list<Permission> */
    private array $permissions;

    /**
     * @param list<string> $scopes scopes concedidos por el token (OAuth o token personal)
     */
    public function __construct(
        public readonly int $userId,
        public readonly string $email,
        public readonly string $name,
        public readonly Role $role,
        array $scopes,
        public readonly string $authMethod,
    ) {
        $this->permissions = array_values(array_filter(
            $role->permissions(),
            static fn (Permission $p) => in_array($p->value, $scopes, true),
        ));
    }

    public function can(Permission $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    /** @return list<string> */
    public function effectiveScopes(): array
    {
        return array_map(static fn (Permission $p) => $p->value, $this->permissions);
    }
}
