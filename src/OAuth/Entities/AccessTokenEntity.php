<?php

declare(strict_types=1);

namespace McpPhp\OAuth\Entities;

use Lcobucci\JWT\Token;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\Traits\AccessTokenTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\TokenEntityTrait;

/**
 * Token de acceso JWT (RS256). A diferencia del comportamiento por defecto de league,
 * la audiencia ("aud") es el RECURSO MCP (RFC 8707 / MCP authorization): un token emitido
 * para este servidor no sirve en otro, y viceversa.
 */
final class AccessTokenEntity implements AccessTokenEntityInterface
{
    use AccessTokenTrait;
    use EntityTrait;
    use TokenEntityTrait;

    public function __construct(private readonly string $issuer, private readonly string $resource)
    {
    }

    /** Sustituye a AccessTokenTrait::toString() (los metodos de la clase tienen prioridad sobre los del trait). */
    public function toString(): string
    {
        return $this->buildJwt()->toString();
    }

    private function buildJwt(): Token
    {
        $this->initJwtConfiguration();
        $now = new \DateTimeImmutable();

        return $this->jwtConfiguration()->builder()
            ->issuedBy($this->issuer)
            ->permittedFor($this->resource)
            ->identifiedBy($this->getIdentifier())
            ->issuedAt($now)
            ->canOnlyBeUsedAfter($now)
            ->expiresAt($this->getExpiryDateTime())
            ->relatedTo((string) $this->getUserIdentifier())
            ->withClaim('client_id', $this->getClient()->getIdentifier())
            ->withClaim('scopes', array_map(static fn ($s) => $s->getIdentifier(), $this->getScopes()))
            ->getToken($this->jwtConfiguration()->signer(), $this->jwtConfiguration()->signingKey());
    }

    private function jwtConfiguration(): \Lcobucci\JWT\Configuration
    {
        return $this->jwtConfiguration;
    }
}
