<?php

declare(strict_types=1);

namespace McpPhp\Repositories;

use McpPhp\Database\Connection;

/** Acceso a la tabla tokens (tokens personales; solo almacena hashes). */
class TokenRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function create(int $userId, string $hash, string $label, string $scopes, \DateTimeImmutable $expiresAt): int
    {
        $this->db->run(
            'INSERT INTO tokens (user_id, token_hash, label, scopes, expires_at) VALUES (:u, :h, :l, :s, :x)',
            ['u' => $userId, 'h' => $hash, 'l' => mb_substr($label, 0, 120), 's' => $scopes, 'x' => $expiresAt->format('Y-m-d H:i:s')],
        );

        return $this->db->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    public function findByHash(string $hash): ?array
    {
        $r = $this->db->run('SELECT * FROM tokens WHERE token_hash = :h', ['h' => $hash])->fetch();

        return $r === false ? null : $r;
    }

    public function touch(int $id): void
    {
        $this->db->run('UPDATE tokens SET last_used_at = UTC_TIMESTAMP() WHERE id = :id', ['id' => $id]);
    }

    public function revoke(int $id): bool
    {
        return $this->db->run(
            "UPDATE tokens SET status = 'revoked', revoked_at = UTC_TIMESTAMP() WHERE id = :id AND status = 'active'",
            ['id' => $id],
        )->rowCount() > 0;
    }

    public function revokeAllForUser(int $userId): int
    {
        return $this->db->run(
            "UPDATE tokens SET status = 'revoked', revoked_at = UTC_TIMESTAMP() WHERE user_id = :u AND status = 'active'",
            ['u' => $userId],
        )->rowCount();
    }

    /** @return list<array<string, mixed>> */
    public function listForUser(?int $userId = null): array
    {
        $sql = 'SELECT id, user_id, label, scopes, issued_at, expires_at, status, revoked_at, last_used_at FROM tokens';
        $params = [];
        if ($userId !== null) {
            $sql .= ' WHERE user_id = :u';
            $params['u'] = $userId;
        }

        return $this->db->run($sql . ' ORDER BY id', $params)->fetchAll();
    }

    /** Elimina tokens expirados/revocados hace mas de $days dias. */
    public function purge(int $days): int
    {
        return $this->db->run(
            "DELETE FROM tokens WHERE (status = 'revoked' AND revoked_at < (UTC_TIMESTAMP() - INTERVAL :d DAY)) OR expires_at < (UTC_TIMESTAMP() - INTERVAL :d2 DAY)",
            ['d' => $days, 'd2' => $days],
        )->rowCount();
    }
}
