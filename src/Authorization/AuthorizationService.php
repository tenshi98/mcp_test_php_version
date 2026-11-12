<?php

declare(strict_types=1);

namespace McpPhp\Authorization;

use McpPhp\Exceptions\AuthorizationException;
use McpPhp\Logging\SecurityLogger;

final class AuthorizationService
{
    public function __construct(private readonly SecurityLogger $security)
    {
    }

    /** @throws AuthorizationException */
    public function assertCan(Principal $principal, Permission $permission, string $resource): void
    {
        if ($principal->can($permission)) {
            return;
        }
        $this->security->warning('authorization.denied', $principal->userId, [
            'permission' => $permission->value,
            'resource' => $resource,
            'role' => $principal->role->value,
        ]);

        throw new AuthorizationException(sprintf('No tiene permiso "%s" para usar %s.', $permission->value, $resource));
    }
}
