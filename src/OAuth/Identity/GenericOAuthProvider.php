<?php

declare(strict_types=1);

namespace McpPhp\OAuth\Identity;

use League\OAuth2\Client\Provider\GenericProvider;
use McpPhp\Exceptions\AuthenticationException;
use Psr\Log\LoggerInterface;

/**
 * Cliente OAuth 2.0 / OIDC generico (league/oauth2-client) con PKCE S256.
 * Sirve para Google, Microsoft Entra, Keycloak, Auth0, Okta... configurando las URLs.
 */
final class GenericOAuthProvider implements IdentityProviderInterface
{
    /**
     * @param array<string, string> $fields mapa de claves del userinfo: subject, email, email_verified, name
     */
    public function __construct(
        private readonly string $name,
        private readonly string $clientId,
        #[\SensitiveParameter] private readonly string $clientSecret,
        private readonly string $authorizeUrl,
        private readonly string $tokenUrl,
        private readonly string $userInfoUrl,
        private readonly string $scopes = 'openid email profile',
        private readonly array $fields = [],
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /** Preajustes para proveedores habituales. */
    public static function preset(string $preset, string $clientId, string $clientSecret, ?LoggerInterface $logger = null): self
    {
        return match ($preset) {
            'google' => new self('google', $clientId, $clientSecret,
                'https://accounts.google.com/o/oauth2/v2/auth', 'https://oauth2.googleapis.com/token',
                'https://openidconnect.googleapis.com/v1/userinfo', 'openid email profile', [], $logger),
            'microsoft' => new self('microsoft', $clientId, $clientSecret,
                'https://login.microsoftonline.com/common/oauth2/v2.0/authorize', 'https://login.microsoftonline.com/common/oauth2/v2.0/token',
                'https://graph.microsoft.com/oidc/userinfo', 'openid email profile', ['email_verified' => ''], $logger),
            default => throw new \InvalidArgumentException("Preset desconocido: $preset"),
        };
    }

    public function name(): string
    {
        return $this->name;
    }

    public function begin(string $state, string $redirectUri): array
    {
        $provider = $this->client($redirectUri);
        $url = $provider->getAuthorizationUrl(['state' => $state]);

        return ['url' => $url, 'context' => ['pkce' => (string) $provider->getPkceCode()]];
    }

    public function complete(string $code, string $redirectUri, array $context): ExternalIdentity
    {
        $provider = $this->client($redirectUri);
        if (!empty($context['pkce'])) {
            $provider->setPkceCode($context['pkce']);
        }
        try {
            $token = $provider->getAccessToken('authorization_code', ['code' => $code]);
            $data = $provider->getResourceOwner($token)->toArray();
        } catch (\Throwable $e) {
            $this->logger?->warning('Fallo el intercambio con el proveedor de identidad', ['provider' => $this->name, 'error' => get_class($e)]);
            throw new AuthenticationException('No se pudo verificar la identidad con el proveedor externo.', 0, $e);
        }

        $f = $this->fields + ['subject' => 'sub', 'email' => 'email', 'email_verified' => 'email_verified', 'name' => 'name'];
        $subject = (string) ($data[$f['subject']] ?? '');
        $email = (string) ($data[$f['email']] ?? '');
        if ($subject === '' || $email === '') {
            throw new AuthenticationException('El proveedor no devolvio identificador o correo.');
        }
        $verified = $f['email_verified'] === '' ? true : filter_var($data[$f['email_verified']] ?? false, FILTER_VALIDATE_BOOLEAN);

        return new ExternalIdentity($this->name, $subject, $email, $verified, (string) ($data[$f['name']] ?? ''));
    }

    private function client(string $redirectUri): GenericProvider
    {
        return new GenericProvider([
            'clientId' => $this->clientId,
            'clientSecret' => $this->clientSecret,
            'redirectUri' => $redirectUri,
            'urlAuthorize' => $this->authorizeUrl,
            'urlAccessToken' => $this->tokenUrl,
            'urlResourceOwnerDetails' => $this->userInfoUrl,
            'scopes' => explode(' ', $this->scopes),
            'scopeSeparator' => ' ',
            'pkceMethod' => GenericProvider::PKCE_METHOD_S256,
        ]);
    }
}
