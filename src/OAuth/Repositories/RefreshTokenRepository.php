<?php

declare(strict_types=1);

namespace McpPhp\OAuth\Repositories;

use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use McpPhp\Database\Connection;
use McpPhp\OAuth\Entities\RefreshTokenEntity;

final class RefreshTokenRepository implements RefreshTokenRepositoryInterface
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function getNewRefreshToken(): ?RefreshTokenEntityInterface
    {
        return new RefreshTokenEntity();
    }

    public function persistNewRefreshToken(RefreshTokenEntityInterface $e): void
    {
        $this->db->run(
            'INSERT INTO oauth_refresh_tokens (id, access_token_id, expires_at) VALUES (:id, :a, :e)',
            [
                'id' => $e->getIdentifier(),
                'a' => $e->getAccessToken()->getIdentifier(),
                'e' => $e->getExpiryDateTime()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            ],
        );
    }

    public function revokeRefreshToken(string $tokenId): void
    {
        $this->db->run('UPDATE oauth_refresh_tokens SET revoked = 1 WHERE id = :id', ['id' => $tokenId]);
    }

    public function isRefreshTokenRevoked(string $tokenId): bool
    {
        $r = $this->db->run('SELECT revoked FROM oauth_refresh_tokens WHERE id = :id', ['id' => $tokenId])->fetchColumn();

        return $r === false || (bool) $r;
    }

    /** @return array{access_token_id: string}|null */
    public function find(string $tokenId): ?array
    {
        $r = $this->db->run('SELECT * FROM oauth_refresh_tokens WHERE id = :id', ['id' => $tokenId])->fetch();

        return $r === false ? null : $r;
    }
}
