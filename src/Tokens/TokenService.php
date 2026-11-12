<?php

declare(strict_types=1);

namespace McpPhp\Tokens;

use McpPhp\Authentication\PrincipalFactory;
use McpPhp\Authorization\Permission;
use McpPhp\Authorization\Principal;
use McpPhp\Exceptions\AuthenticationException;
use McpPhp\Exceptions\NotFoundException;
use McpPhp\Exceptions\ValidationException;
use McpPhp\Logging\SecurityLogger;
use McpPhp\Repositories\TokenRepository;
use McpPhp\Repositories\UserRepository;
use Psr\Clock\ClockInterface;

/** Emision, validacion, expiracion y revocacion de tokens personales. */
final class TokenService
{
    public function __construct(
        private readonly TokenRepository $tokens,
        private readonly UserRepository $users,
        private readonly TokenGenerator $generator,
        private readonly SecurityLogger $security,
        private readonly ClockInterface $clock,
        private readonly int $maxTtlSeconds = 90 * 86400,
    ) {
    }

    /**
     * @param list<string>|null $scopes null = todos los scopes del rol
     * @return array{id: int, token: string, expires_at: string, scopes: list<string>}  "token" solo se devuelve aqui, una vez
     */
    public function issue(int $userId, string $label, int $ttlSeconds, ?array $scopes = null): array
    {
        $user = $this->users->findById($userId) ?? throw new NotFoundException('Usuario no encontrado.');
        if ($user['status'] !== 'active') {
            throw new ValidationException(['user' => 'El usuario no esta activo.']);
        }
        if ($ttlSeconds < 60 || $ttlSeconds > $this->maxTtlSeconds) {
            throw new ValidationException(['ttl' => sprintf('La vigencia debe estar entre 60 y %d segundos.', $this->maxTtlSeconds)]);
        }
        $scopes ??= Permission::allScopes();
        $invalid = array_diff($scopes, Permission::allScopes());
        if ($scopes === [] || $invalid !== []) {
            throw new ValidationException(['scopes' => 'Scopes validos: ' . implode(', ', Permission::allScopes())]);
        }

        $plain = $this->generator->generate();
        $expires = $this->clock->now()->modify("+$ttlSeconds seconds");
        $id = $this->tokens->create($userId, $this->generator->hash($plain), $label, implode(' ', $scopes), $expires);
        $this->security->info('token.issued', $userId, ['token_id' => $id, 'expires_at' => $expires->format('c')]);

        return ['id' => $id, 'token' => $plain, 'expires_at' => $expires->format('Y-m-d H:i:s'), 'scopes' => array_values($scopes)];
    }

    /**
     * @throws AuthenticationException con mensaje generico (el motivo real solo va al log de seguridad)
     */
    public function authenticate(#[\SensitiveParameter] string $plainToken): Principal
    {
        $fail = function (string $reason, ?int $userId = null, array $ctx = []): never {
            $this->security->warning('token.rejected', $userId, ['reason' => $reason] + $ctx);
            throw new AuthenticationException('Token invalido, expirado o revocado.');
        };

        if (!TokenGenerator::looksLikeToken($plainToken)) {
            $fail('malformed');
        }
        $row = $this->tokens->findByHash($this->generator->hash($plainToken));
        if ($row === null) {
            $fail('unknown');
        }
        $userId = (int) $row['user_id'];
        if ($row['status'] !== 'active') {
            $fail('revoked', $userId, ['token_id' => (int) $row['id']]);
        }
        $expires = new \DateTimeImmutable($row['expires_at'], new \DateTimeZone('UTC'));
        if ($expires <= $this->clock->now()) {
            $fail('expired', $userId, ['token_id' => (int) $row['id']]);
        }
        $user = $this->users->findById($userId);
        if ($user === null || $user['status'] !== 'active') {
            $fail('user_not_active', $userId, ['token_id' => (int) $row['id']]);
        }

        $this->tokens->touch((int) $row['id']);

        return PrincipalFactory::fromUser($user, preg_split('/\s+/', trim((string) $row['scopes'])) ?: [], 'token');
    }

    public function revoke(int $tokenId, ?int $actorId = null): void
    {
        if (!$this->tokens->revoke($tokenId)) {
            throw new NotFoundException('Token no encontrado o ya revocado.');
        }
        $this->security->info('token.revoked', $actorId, ['token_id' => $tokenId]);
    }

    public function revokeAllForUser(int $userId): int
    {
        $n = $this->tokens->revokeAllForUser($userId);
        $this->security->info('token.revoked_all', $userId, ['count' => $n]);

        return $n;
    }

    /** @return list<array<string, mixed>> */
    public function list(?int $userId = null): array
    {
        $now = $this->clock->now();

        return array_map(static function (array $t) use ($now) {
            if ($t['status'] === 'active' && new \DateTimeImmutable($t['expires_at'], new \DateTimeZone('UTC')) <= $now) {
                $t['status'] = 'expired';
            }

            return $t;
        }, $this->tokens->listForUser($userId));
    }
}
