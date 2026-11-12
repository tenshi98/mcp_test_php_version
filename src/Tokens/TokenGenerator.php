<?php

declare(strict_types=1);

namespace McpPhp\Tokens;

use McpPhp\Exceptions\ConfigurationException;

/**
 * Genera tokens aleatorios de 256 bits y calcula su hash de almacenamiento
 * (HMAC-SHA256 con una clave del servidor: un volcado de la BD no permite usarlos).
 */
final class TokenGenerator
{
    public const PREFIX = 'mcp_';

    public function __construct(#[\SensitiveParameter] private readonly string $pepper)
    {
        if (strlen($pepper) < 32) {
            throw new ConfigurationException('APP_KEY debe tener al menos 32 caracteres. Genere una con: php bin/console app:generate-key');
        }
    }

    public function generate(): string
    {
        return self::PREFIX . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public function hash(#[\SensitiveParameter] string $token): string
    {
        return hash_hmac('sha256', $token, $this->pepper);
    }

    public static function looksLikeToken(string $token): bool
    {
        return (bool) preg_match('/^' . self::PREFIX . '[A-Za-z0-9_-]{43}$/', $token);
    }
}
