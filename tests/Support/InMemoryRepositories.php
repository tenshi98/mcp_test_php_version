<?php

declare(strict_types=1);

namespace McpPhp\Tests\Support;

use McpPhp\Repositories\TokenRepository;
use McpPhp\Repositories\UserRepository;

/** Fabrica dobles en memoria de los repositorios (no tocan la BD). */
final class InMemoryRepositories
{
    /** @param list<array<string, mixed>> $users */
    public static function users(array $users): UserRepository
    {
        return new class ($users) extends UserRepository {
            /** @param list<array<string, mixed>> $rows */
            public function __construct(private array $rows)
            {
            }

            public function findById(int $id): ?array
            {
                foreach ($this->rows as $r) {
                    if ($r['id'] === $id) {
                        return $r;
                    }
                }

                return null;
            }

            public function findByEmail(string $email): ?array
            {
                foreach ($this->rows as $r) {
                    if ($r['email'] === strtolower($email)) {
                        return $r;
                    }
                }

                return null;
            }
        };
    }

    public static function tokens(): TokenRepository
    {
        return new class extends TokenRepository {
            /** @var array<int, array<string, mixed>> */
            public array $rows = [];

            public function __construct()
            {
            }

            public function create(int $userId, string $hash, string $label, string $scopes, \DateTimeImmutable $expiresAt): int
            {
                $id = count($this->rows) + 1;
                $this->rows[$id] = ['id' => $id, 'user_id' => $userId, 'token_hash' => $hash, 'label' => $label, 'scopes' => $scopes,
                    'expires_at' => $expiresAt->format('Y-m-d H:i:s'), 'status' => 'active', 'revoked_at' => null, 'issued_at' => '', 'last_used_at' => null];

                return $id;
            }

            public function findByHash(string $hash): ?array
            {
                foreach ($this->rows as $r) {
                    if ($r['token_hash'] === $hash) {
                        return $r;
                    }
                }

                return null;
            }

            public function touch(int $id): void
            {
                $this->rows[$id]['last_used_at'] = 'now';
            }

            public function revoke(int $id): bool
            {
                if (!isset($this->rows[$id]) || $this->rows[$id]['status'] !== 'active') {
                    return false;
                }
                $this->rows[$id]['status'] = 'revoked';

                return true;
            }

            public function revokeAllForUser(int $userId): int
            {
                $n = 0;
                foreach ($this->rows as $id => $r) {
                    if ($r['user_id'] === $userId && $r['status'] === 'active') {
                        $this->rows[$id]['status'] = 'revoked';
                        $n++;
                    }
                }

                return $n;
            }

            public function listForUser(?int $userId = null): array
            {
                return array_values($this->rows);
            }
        };
    }
}
