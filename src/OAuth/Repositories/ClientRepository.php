<?php

declare(strict_types=1);

namespace McpPhp\OAuth\Repositories;

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use McpPhp\Database\Connection;
use McpPhp\OAuth\Entities\ClientEntity;

final class ClientRepository implements ClientRepositoryInterface
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function getClientEntity(string $clientIdentifier): ?ClientEntityInterface
    {
        $row = $this->row($clientIdentifier);
        if ($row === null || $row['status'] !== 'active') {
            return null;
        }

        return new ClientEntity(
            $row['id'],
            $row['name'],
            json_decode($row['redirect_uris'], true, 512, JSON_THROW_ON_ERROR),
            (bool) $row['is_confidential'],
        );
    }

    public function validateClient(string $clientIdentifier, ?string $clientSecret, ?string $grantType): bool
    {
        $row = $this->row($clientIdentifier);
        if ($row === null || $row['status'] !== 'active') {
            return false;
        }
        if (!in_array($grantType, ['authorization_code', 'refresh_token'], true)) {
            return false;
        }
        if (!(bool) $row['is_confidential']) {
            return true; // cliente publico: la seguridad la da PKCE
        }

        return $clientSecret !== null && $row['secret_hash'] !== null && password_verify($clientSecret, $row['secret_hash']);
    }

    /**
     * @param list<string> $redirectUris
     * @return array{client_id: string, client_secret: ?string}
     */
    public function register(string $name, array $redirectUris, bool $confidential): array
    {
        $id = bin2hex(random_bytes(16));
        $secret = $confidential ? bin2hex(random_bytes(32)) : null;
        $this->db->run(
            'INSERT INTO oauth_clients (id, name, secret_hash, redirect_uris, is_confidential) VALUES (:id, :n, :s, :r, :c)',
            [
                'id' => $id,
                'n' => mb_substr($name, 0, 190),
                's' => $secret !== null ? password_hash($secret, PASSWORD_DEFAULT) : null,
                'r' => json_encode(array_values($redirectUris), JSON_UNESCAPED_SLASHES),
                'c' => (int) $confidential,
            ],
        );

        return ['client_id' => $id, 'client_secret' => $secret];
    }

    public function revoke(string $clientId): bool
    {
        $n = $this->db->run("UPDATE oauth_clients SET status = 'revoked' WHERE id = :id", ['id' => $clientId])->rowCount();
        $this->db->run('UPDATE oauth_access_tokens SET revoked = 1 WHERE client_id = :id', ['id' => $clientId]);
        $this->db->run(
            'UPDATE oauth_refresh_tokens SET revoked = 1 WHERE access_token_id IN (SELECT id FROM oauth_access_tokens WHERE client_id = :id)',
            ['id' => $clientId],
        );

        return $n > 0;
    }

    public function countRegisteredSince(\DateTimeImmutable $since): int
    {
        return (int) $this->db->run('SELECT COUNT(*) FROM oauth_clients WHERE created_at >= :s', ['s' => $since->format('Y-m-d H:i:s')])->fetchColumn();
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return $this->db->run('SELECT id, name, is_confidential, status, created_at, redirect_uris FROM oauth_clients ORDER BY created_at')->fetchAll();
    }

    /** @return array<string, mixed>|null */
    private function row(string $id): ?array
    {
        $r = $this->db->run('SELECT * FROM oauth_clients WHERE id = :id', ['id' => $id])->fetch();

        return $r === false ? null : $r;
    }
}
