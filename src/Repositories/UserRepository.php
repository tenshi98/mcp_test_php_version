<?php

declare(strict_types=1);

namespace McpPhp\Repositories;

use McpPhp\Database\Connection;
use McpPhp\Exceptions\ConflictException;
use McpPhp\Exceptions\DatabaseException;

/** Acceso a allowed_users y oauth_users. */
class UserRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        $r = $this->db->run('SELECT * FROM allowed_users WHERE id = :id', ['id' => $id])->fetch();

        return $r === false ? null : $r;
    }

    /** @return array<string, mixed>|null */
    public function findByEmail(string $email): ?array
    {
        $r = $this->db->run('SELECT * FROM allowed_users WHERE email = :e', ['e' => strtolower($email)])->fetch();

        return $r === false ? null : $r;
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return $this->db->run('SELECT * FROM allowed_users ORDER BY id')->fetchAll();
    }

    public function create(string $name, string $email, string $role, string $status = 'active'): int
    {
        try {
            $this->db->run(
                'INSERT INTO allowed_users (name, email, role, status) VALUES (:n, :e, :r, :s)',
                ['n' => $name, 'e' => strtolower($email), 'r' => $role, 's' => $status],
            );
        } catch (DatabaseException $e) {
            if ($e->getPrevious() instanceof \PDOException && (int) ($e->getPrevious()->errorInfo[1] ?? 0) === 1062) {
                throw new ConflictException('Ya existe un usuario con ese correo.', 0, $e);
            }
            throw $e;
        }

        return $this->db->lastInsertId();
    }

    public function setStatus(int $id, string $status): bool
    {
        return $this->db->run('UPDATE allowed_users SET status = :s WHERE id = :id', ['s' => $status, 'id' => $id])->rowCount() > 0;
    }

    public function setRole(int $id, string $role): bool
    {
        return $this->db->run('UPDATE allowed_users SET role = :r WHERE id = :id', ['r' => $role, 'id' => $id])->rowCount() > 0;
    }

    // ---- identidades OAuth -------------------------------------------------

    /** @return array<string, mixed>|null */
    public function findOAuthIdentity(string $provider, string $externalId): ?array
    {
        $r = $this->db->run(
            'SELECT * FROM oauth_users WHERE provider = :p AND external_id = :x',
            ['p' => $provider, 'x' => $externalId],
        )->fetch();

        return $r === false ? null : $r;
    }

    public function linkOAuthIdentity(string $provider, string $externalId, int $userId, string $email, string $name): void
    {
        $this->db->run(
            'INSERT INTO oauth_users (provider, external_id, user_id, email, name, last_login_at) VALUES (:p, :x, :u, :e, :n, UTC_TIMESTAMP())',
            ['p' => $provider, 'x' => $externalId, 'u' => $userId, 'e' => strtolower($email), 'n' => mb_substr($name, 0, 190)],
        );
    }

    public function touchOAuthLogin(int $oauthUserId): void
    {
        $this->db->run('UPDATE oauth_users SET last_login_at = UTC_TIMESTAMP() WHERE id = :id', ['id' => $oauthUserId]);
    }

    /** @return list<array<string, mixed>> */
    public function oauthIdentitiesOf(int $userId): array
    {
        return $this->db->run('SELECT * FROM oauth_users WHERE user_id = :u ORDER BY id', ['u' => $userId])->fetchAll();
    }

    /** Revoca todas las identidades OAuth y las autorizaciones (tokens) del usuario. */
    public function revokeOAuthAuthorizations(int $userId): int
    {
        $this->db->run("UPDATE oauth_users SET status = 'revoked' WHERE user_id = :u", ['u' => $userId]);
        $n = $this->db->run('UPDATE oauth_access_tokens SET revoked = 1 WHERE user_id = :u AND revoked = 0', ['u' => $userId])->rowCount();
        $this->db->run(
            'UPDATE oauth_refresh_tokens SET revoked = 1 WHERE access_token_id IN (SELECT id FROM oauth_access_tokens WHERE user_id = :u)',
            ['u' => $userId],
        );

        return $n;
    }
}
