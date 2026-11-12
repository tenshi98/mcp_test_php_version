<?php

declare(strict_types=1);

namespace McpPhp\Authentication;

use Mcp\Server\Authorization\AccessToken;
use McpPhp\Authorization\Principal;
use McpPhp\Exceptions\AuthenticationException;

/** Determina quien ejecuta cada llamada a una herramienta. */
interface PrincipalResolverInterface
{
    /**
     * @param AccessToken|null $accessToken token ya validado por el transporte HTTP (null en STDIO)
     * @throws AuthenticationException
     */
    public function resolve(?AccessToken $accessToken): Principal;
}
