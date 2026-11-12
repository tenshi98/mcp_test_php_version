<?php

declare(strict_types=1);

namespace McpPhp\OAuth\Identity;

use McpPhp\Exceptions\AuthenticationException;
use McpPhp\Exceptions\ConfigurationException;

/**
 * SOLO PARA DESARROLLO Y PRUEBAS: "autentica" a quien escriba un correo en un formulario local.
 * Se niega a construirse con APP_ENV=production.
 */
final class DevIdentityProvider implements IdentityProviderInterface
{
    public function __construct(bool $production, private readonly string $loginUrl)
    {
        if ($production) {
            throw new ConfigurationException('El proveedor de identidad "dev" no puede usarse en produccion.');
        }
    }

    public function name(): string
    {
        return 'dev';
    }

    public function begin(string $state, string $redirectUri): array
    {
        return ['url' => $this->loginUrl . '?state=' . rawurlencode($state), 'context' => []];
    }

    public function complete(string $code, string $redirectUri, array $context): ExternalIdentity
    {
        if (!str_starts_with($code, 'dev:')) {
            throw new AuthenticationException('Codigo de desarrollo invalido.');
        }
        $email = base64_decode(substr($code, 4), true);
        if ($email === false || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new AuthenticationException('Correo invalido.');
        }

        return new ExternalIdentity('dev', 'dev-' . hash('sha256', strtolower($email)), strtolower($email), true, $email);
    }

    public static function encodeCode(string $email): string
    {
        return 'dev:' . base64_encode($email);
    }
}
