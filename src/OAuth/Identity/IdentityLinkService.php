<?php

declare(strict_types=1);

namespace McpPhp\OAuth\Identity;

use McpPhp\Exceptions\AuthenticationException;
use McpPhp\Logging\SecurityLogger;
use McpPhp\Repositories\UserRepository;

/**
 * Vincula identidades externas con usuarios permitidos.
 *
 *  - Identidad ya vinculada y activa  -> entra.
 *  - Identidad vinculada pero revocada -> rechazada.
 *  - Identidad nueva: solo si el proveedor VERIFICO el correo y ese correo esta en allowed_users
 *    (estado active); entonces se crea el vinculo en oauth_users.
 */
final class IdentityLinkService
{
    public function __construct(private readonly UserRepository $users, private readonly SecurityLogger $security)
    {
    }

    /**
     * @return array<string, mixed> fila de allowed_users
     * @throws AuthenticationException
     */
    public function resolve(ExternalIdentity $id): array
    {
        $link = $this->users->findOAuthIdentity($id->provider, $id->subject);

        if ($link !== null) {
            if ($link['status'] !== 'active') {
                $this->deny('identity_revoked', $id, (int) $link['user_id']);
            }
            $user = $this->users->findById((int) $link['user_id']);
            if ($user === null || $user['status'] !== 'active') {
                $this->deny('user_not_active', $id, (int) $link['user_id']);
            }
            $this->users->touchOAuthLogin((int) $link['id']);
            $this->security->info('oauth.login', (int) $user['id'], ['provider' => $id->provider]);

            return $user;
        }

        if (!$id->emailVerified) {
            $this->deny('email_not_verified', $id);
        }
        $user = $this->users->findByEmail($id->email);
        if ($user === null || $user['status'] !== 'active') {
            $this->deny('not_in_allowed_users', $id, $user['id'] ?? null);
        }

        $this->users->linkOAuthIdentity($id->provider, $id->subject, (int) $user['id'], $id->email, $id->name);
        $this->security->info('oauth.identity_linked', (int) $user['id'], ['provider' => $id->provider]);

        return $user;
    }

    private function deny(string $reason, ExternalIdentity $id, ?int $userId = null): never
    {
        // El correo se registra solo para auditoria interna; el cliente recibe un mensaje generico.
        $this->security->warning('oauth.login_denied', $userId, ['reason' => $reason, 'provider' => $id->provider, 'email' => $id->email]);
        throw new AuthenticationException('Su cuenta no esta autorizada para usar este servidor.');
    }
}
