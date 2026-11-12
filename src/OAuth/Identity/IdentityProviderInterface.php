<?php

declare(strict_types=1);

namespace McpPhp\OAuth\Identity;

use McpPhp\Exceptions\AuthenticationException;

/**
 * Proveedor de identidad externo (Google, Microsoft, Keycloak, GitHub...) que autentica
 * al usuario humano durante el flujo de autorizacion del servidor MCP.
 */
interface IdentityProviderInterface
{
    /** Identificador estable guardado en oauth_users.provider (p. ej. "google"). */
    public function name(): string;

    /**
     * @param string $state valor anti-CSRF que el proveedor devolvera tal cual
     * @return array{url: string, context: array<string, string>} URL de redireccion y datos a guardar en sesion (p. ej. verificador PKCE)
     */
    public function begin(string $state, string $redirectUri): array;

    /**
     * @param array<string, string> $context lo devuelto por begin()
     * @throws AuthenticationException
     */
    public function complete(string $code, string $redirectUri, array $context): ExternalIdentity;
}
