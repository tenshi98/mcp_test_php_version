<?php

declare(strict_types=1);

namespace McpPhp\Authentication;

use Mcp\Server\Authorization\AccessToken;
use McpPhp\Authorization\Principal;
use McpPhp\Tokens\TokenService;

/**
 * STDIO: el cliente MCP lanza el proceso y pasa el token en la variable de entorno
 * MCP_ACCESS_TOKEN (la especificacion indica que STDIO no usa el flujo OAuth, sino
 * credenciales del entorno). El token se revalida en CADA llamada, asi que la
 * revocacion o expiracion surte efecto inmediatamente aun con el proceso en marcha.
 */
final class StdioPrincipalResolver implements PrincipalResolverInterface
{
    public function __construct(private readonly TokenService $tokens, #[\SensitiveParameter] private readonly string $token)
    {
    }

    public function resolve(?AccessToken $accessToken): Principal
    {
        return $this->tokens->authenticate($this->token);
    }
}
