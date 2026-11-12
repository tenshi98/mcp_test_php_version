<?php

declare(strict_types=1);

namespace McpPhp\OAuth\Repositories;

use League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;
use McpPhp\Database\Connection;
use McpPhp\OAuth\Entities\AuthCodeEntity;

final class AuthCodeRepository implements AuthCodeRepositoryInterface
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function getNewAuthCode(): AuthCodeEntityInterface
    {
        return new AuthCodeEntity();
    }

    public function persistNewAuthCode(AuthCodeEntityInterface $e): void
    {
        $this->db->run(
            'INSERT INTO oauth_auth_codes (id, user_id, client_id, scopes, expires_at) VALUES (:id, :u, :c, :s, :e)',
            [
                'id' => $e->getIdentifier(),
                'u' => (int) $e->getUserIdentifier(),
                'c' => $e->getClient()->getIdentifier(),
                's' => implode(' ', array_map(static fn ($s) => $s->getIdentifier(), $e->getScopes())),
                'e' => $e->getExpiryDateTime()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            ],
        );
    }

    public function revokeAuthCode(string $codeId): void
    {
        $this->db->run('UPDATE oauth_auth_codes SET revoked = 1 WHERE id = :id', ['id' => $codeId]);
    }

    public function isAuthCodeRevoked(string $codeId): bool
    {
        $r = $this->db->run('SELECT revoked FROM oauth_auth_codes WHERE id = :id', ['id' => $codeId])->fetchColumn();

        return $r === false || (bool) $r;
    }
}
