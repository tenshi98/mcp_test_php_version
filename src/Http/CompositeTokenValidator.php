<?php

declare(strict_types=1);

namespace McpPhp\Http;

use Mcp\Server\Authorization\AccessToken;
use Mcp\Server\Transport\Http\OAuth\AuthorizationResult;
use Mcp\Server\Transport\Http\OAuth\AuthorizationTokenValidatorInterface;
use McpPhp\Exceptions\AuthenticationException;
use McpPhp\Tokens\TokenGenerator;
use McpPhp\Tokens\TokenService;

/**
 * Valida el Bearer de /mcp. Por defecto SOLO acepta JWT emitidos por el servidor OAuth.
 *
 * Opcionalmente (MCP_HTTP_ALLOW_PERSONAL_TOKENS=true) acepta tambien los tokens personales
 * "mcp_..." para clientes que no soportan OAuth. Eso NO es OAuth 2.1: es un mecanismo
 * propio, desactivado por defecto, y se documenta como tal.
 */
final class CompositeTokenValidator implements AuthorizationTokenValidatorInterface
{
    public function __construct(
        private readonly AuthorizationTokenValidatorInterface $oauth,
        private readonly ?TokenService $personalTokens = null,
    ) {
    }

    public function validate(string $accessToken): AuthorizationResult
    {
        if ($this->personalTokens === null || !str_starts_with($accessToken, TokenGenerator::PREFIX)) {
            return $this->oauth->validate($accessToken);
        }

        try {
            $principal = $this->personalTokens->authenticate($accessToken);
        } catch (AuthenticationException) {
            return AuthorizationResult::unauthorized('invalid_token', 'The access token is invalid, expired or revoked.');
        } catch (\Throwable) {
            return AuthorizationResult::unavailable(5);
        }

        return AuthorizationResult::allow(new AccessToken($principal->effectiveScopes(), [
            'sub' => (string) $principal->userId,
            'auth_method' => 'personal_token',
        ]));
    }
}
