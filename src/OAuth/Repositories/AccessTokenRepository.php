<?php

declare(strict_types=1);

namespace McpPhp\OAuth\Repositories;

use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use McpPhp\Database\Connection;
use McpPhp\OAuth\Entities\AccessTokenEntity;

final class AccessTokenRepository implements AccessTokenRepositoryInterface
{
    public function __construct(private readonly Connection $db, private readonly string $issuer, private readonly string $resource)
    {
    }

    public function getNewToken(ClientEntityInterface $clientEntity, array $scopes, ?string $userIdentifier = null): AccessTokenEntityInterface
    {
        $token = new AccessTokenEntity($this->issuer, $this->resource);
        $token->setClient($clientEntity);
        foreach ($scopes as $scope) {
            $token->addScope($scope);
        }
        if ($userIdentifier !== null) {
            $token->setUserIdentifier($userIdentifier);
        }

        return $token;
    }

    public function persistNewAccessToken(AccessTokenEntityInterface $accessTokenEntity): void
    {
        $this->db->run(
            'INSERT INTO oauth_access_tokens (id, user_id, client_id, scopes, expires_at) VALUES (:id, :u, :c, :s, :e)',
            [
                'id' => $accessTokenEntity->getIdentifier(),
                'u' => (int) $accessTokenEntity->getUserIdentifier(),
                'c' => $accessTokenEntity->getClient()->getIdentifier(),
                's' => implode(' ', array_map(static fn ($s) => $s->getIdentifier(), $accessTokenEntity->getScopes())),
                'e' => $accessTokenEntity->getExpiryDateTime()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            ],
        );
    }

    public function revokeAccessToken(string $tokenId): void
    {
        $this->db->run('UPDATE oauth_access_tokens SET revoked = 1 WHERE id = :id', ['id' => $tokenId]);
        $this->db->run('UPDATE oauth_refresh_tokens SET revoked = 1 WHERE access_token_id = :id', ['id' => $tokenId]);
    }

    /** Un token desconocido cuenta como revocado. */
    public function isAccessTokenRevoked(string $tokenId): bool
    {
        $r = $this->db->run('SELECT revoked FROM oauth_access_tokens WHERE id = :id', ['id' => $tokenId])->fetchColumn();

        return $r === false || (bool) $r;
    }
}
