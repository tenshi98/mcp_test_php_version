<?php

declare(strict_types=1);

namespace McpPhp\OAuth;

use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\CryptKey;
use League\OAuth2\Server\Grant\AuthCodeGrant;
use League\OAuth2\Server\Grant\RefreshTokenGrant;
use McpPhp\Database\Connection;
use McpPhp\OAuth\Repositories\AccessTokenRepository;
use McpPhp\OAuth\Repositories\AuthCodeRepository;
use McpPhp\OAuth\Repositories\ClientRepository;
use McpPhp\OAuth\Repositories\RefreshTokenRepository;
use McpPhp\OAuth\Repositories\ScopeRepository;
use McpPhp\Repositories\UserRepository;

/**
 * Configura league/oauth2-server como servidor de autorizacion OAuth 2.1:
 *  - solo authorization_code (+ PKCE) y refresh_token (con rotacion);
 *  - sin implicit ni password grant;
 *  - access tokens JWT RS256 de vida corta con audiencia = recurso MCP.
 */
final class AuthorizationServerFactory
{
    public function __construct(
        private readonly OAuthConfig $config,
        private readonly Connection $db,
        private readonly UserRepository $users,
    ) {
    }

    public function clients(): ClientRepository
    {
        return new ClientRepository($this->db);
    }

    public function accessTokens(): AccessTokenRepository
    {
        return new AccessTokenRepository($this->db, $this->config->issuer, $this->config->resource);
    }

    public function refreshTokens(): RefreshTokenRepository
    {
        return new RefreshTokenRepository($this->db);
    }

    public function create(): AuthorizationServer
    {
        $server = new AuthorizationServer(
            $this->clients(),
            $this->accessTokens(),
            new ScopeRepository($this->config->scopes, $this->users),
            new CryptKey($this->config->privateKeyPath, null, $this->config->checkKeyPermissions),
            $this->config->encryptionKey,
        );

        $authCode = new AuthCodeGrant(new AuthCodeRepository($this->db), $this->refreshTokens(), new \DateInterval('PT' . $this->config->authCodeTtl . 'S'));
        $authCode->setRefreshTokenTTL(new \DateInterval('PT' . $this->config->refreshTtl . 'S'));
        // OAuth 2.1: PKCE obligatorio tambien para clientes publicos (ademas exigimos S256 en el endpoint).
        $server->enableGrantType($authCode, new \DateInterval('PT' . $this->config->accessTtl . 'S'));

        $refresh = new RefreshTokenGrant($this->refreshTokens());
        $refresh->setRefreshTokenTTL(new \DateInterval('PT' . $this->config->refreshTtl . 'S'));
        $server->enableGrantType($refresh, new \DateInterval('PT' . $this->config->accessTtl . 'S'));

        return $server;
    }
}
