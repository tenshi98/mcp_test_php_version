<?php

declare(strict_types=1);

namespace McpPhp\OAuth;

use McpPhp\Authorization\Permission;
use McpPhp\Core\Config;
use McpPhp\Exceptions\ConfigurationException;

/** Configuracion del servidor de autorizacion y del recurso protegido. */
final class OAuthConfig
{
    /**
     * @param list<string> $scopes
     */
    public function __construct(
        public readonly string $issuer,
        public readonly string $resource,
        public readonly string $privateKeyPath,
        public readonly string $publicKeyPath,
        #[\SensitiveParameter] public readonly string $encryptionKey,
        public readonly int $accessTtl,
        public readonly int $refreshTtl,
        public readonly int $authCodeTtl,
        public readonly bool $dcrEnabled,
        public readonly int $dcrMaxPerHour,
        public readonly array $scopes,
        public readonly bool $checkKeyPermissions,
    ) {
    }

    public static function fromConfig(Config $c): self
    {
        $base = rtrim($c->get('APP_URL', 'http://localhost:8787') ?? '', '/');
        if (!preg_match('#^https?://#', $base)) {
            throw new ConfigurationException('APP_URL debe ser una URL absoluta (https://... en produccion).');
        }
        if ($c->isProduction() && !str_starts_with($base, 'https://')) {
            throw new ConfigurationException('En produccion APP_URL debe usar https (OAuth 2.1 / MCP lo exigen).');
        }
        $key = $c->get('OAUTH_ENCRYPTION_KEY');
        if ($key === null || strlen($key) < 32) {
            throw new ConfigurationException('OAUTH_ENCRYPTION_KEY no configurada. Genere una con: php bin/console app:generate-key');
        }

        return new self(
            issuer: $base,
            resource: $base . '/mcp',
            privateKeyPath: $c->path('OAUTH_PRIVATE_KEY', 'storage/keys/private.pem'),
            publicKeyPath: $c->path('OAUTH_PUBLIC_KEY', 'storage/keys/public.pem'),
            encryptionKey: $key,
            accessTtl: $c->int('OAUTH_ACCESS_TOKEN_TTL', 3600),
            refreshTtl: $c->int('OAUTH_REFRESH_TOKEN_TTL', 30 * 86400),
            authCodeTtl: $c->int('OAUTH_AUTH_CODE_TTL', 600),
            dcrEnabled: $c->bool('OAUTH_DCR_ENABLED', true),
            dcrMaxPerHour: $c->int('OAUTH_DCR_MAX_PER_HOUR', 60),
            scopes: Permission::allScopes(),
            checkKeyPermissions: $c->isProduction(),
        );
    }

    public function endpoint(string $path): string
    {
        return $this->issuer . $path;
    }
}
