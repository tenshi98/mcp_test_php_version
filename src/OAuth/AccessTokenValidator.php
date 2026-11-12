<?php

declare(strict_types=1);

namespace McpPhp\OAuth;

use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\UnencryptedToken;
use Lcobucci\JWT\Validation\Constraint\IssuedBy;
use Lcobucci\JWT\Validation\Constraint\PermittedFor;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Constraint\StrictValidAt;
use Mcp\Server\Authorization\AccessToken;
use Mcp\Server\Transport\Http\OAuth\AuthorizationResult;
use Mcp\Server\Transport\Http\OAuth\AuthorizationTokenValidatorInterface;
use McpPhp\Logging\SecurityLogger;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use McpPhp\Repositories\UserRepository;
use Psr\Clock\ClockInterface;

/**
 * Validador de tokens Bearer para el recurso MCP (conecta con AuthorizationMiddleware del SDK).
 *
 * Comprueba, por este orden: firma RS256, emisor, AUDIENCIA (debe ser este recurso),
 * vigencia (exp/nbf/iat), que no este revocado en BD y que el usuario siga activo.
 * Si se usa un servidor de autorizacion externo (Keycloak, Auth0...), sustituya esta clase
 * por Mcp\Server\Transport\Http\OAuth\JwtTokenValidator del SDK (ver docs/AUTHENTICATION.md).
 */
final class AccessTokenValidator implements AuthorizationTokenValidatorInterface
{
    private readonly Configuration $jwt;

    public function __construct(
        string $publicKeyPem,
        private readonly string $issuer,
        private readonly string $resource,
        private readonly AccessTokenRepositoryInterface $accessTokens,
        private readonly UserRepository $users,
        private readonly SecurityLogger $security,
        private readonly ClockInterface $clock,
    ) {
        $this->jwt = Configuration::forAsymmetricSigner(new Sha256(), InMemory::plainText('unused-signing-key'), InMemory::plainText($publicKeyPem));
    }

    public function validate(string $accessToken): AuthorizationResult
    {
        try {
            $token = $this->jwt->parser()->parse($accessToken);
        } catch (\Throwable) {
            return $this->reject('malformed');
        }
        if (!$token instanceof UnencryptedToken) {
            return $this->reject('malformed');
        }

        $valid = $this->jwt->validator()->validate(
            $token,
            new SignedWith($this->jwt->signer(), $this->jwt->verificationKey()),
            new IssuedBy($this->issuer),
            new PermittedFor($this->resource),
            new StrictValidAt($this->clock),
        );
        if (!$valid) {
            return $this->reject('invalid_signature_audience_or_expired');
        }

        $claims = $token->claims();
        $jti = (string) $claims->get('jti', '');
        $sub = (string) $claims->get('sub', '');

        try {
            if ($jti === '' || $this->accessTokens->isAccessTokenRevoked($jti)) {
                return $this->reject('revoked', $sub);
            }
            $user = ctype_digit($sub) ? $this->users->findById((int) $sub) : null;
            if ($user === null || $user['status'] !== 'active') {
                return $this->reject('user_not_active', $sub);
            }
        } catch (\Throwable) {
            return AuthorizationResult::unavailable(5);
        }

        $scopes = array_values(array_filter((array) $claims->get('scopes', []), 'is_string'));

        return AuthorizationResult::allow(new AccessToken($scopes, [
            'iss' => $this->issuer,
            'sub' => $sub,
            'aud' => $this->resource,
            'client_id' => (string) $claims->get('client_id', ''),
            'jti' => $jti,
            'auth_method' => 'oauth',
        ]));
    }

    private function reject(string $reason, string $sub = ''): AuthorizationResult
    {
        $this->security->warning('oauth.access_token_rejected', ctype_digit($sub) ? (int) $sub : null, ['reason' => $reason]);

        // Mensaje generico: no revela por que fallo.
        return AuthorizationResult::unauthorized('invalid_token', 'The access token is invalid, expired or revoked.');
    }
}
