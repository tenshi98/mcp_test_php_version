<?php

declare(strict_types=1);

namespace McpPhp\OAuth\Endpoints;

use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\UnencryptedToken;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\CryptTrait;
use League\OAuth2\Server\Exception\OAuthServerException;
use McpPhp\Logging\SecurityLogger;
use McpPhp\OAuth\OAuthConfig;
use McpPhp\OAuth\Repositories\AccessTokenRepository;
use McpPhp\OAuth\Repositories\ClientRepository;
use McpPhp\OAuth\Repositories\RefreshTokenRepository;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/** /oauth/token (code -> tokens, refresh con rotacion) y /oauth/revoke (RFC 7009). */
final class TokenEndpoint
{
    use CryptTrait;

    public function __construct(
        private readonly AuthorizationServer $server,
        private readonly OAuthConfig $config,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly Responses $responses,
        private readonly ClientRepository $clients,
        private readonly AccessTokenRepository $accessTokens,
        private readonly RefreshTokenRepository $refreshTokens,
        private readonly SecurityLogger $security,
        private readonly LoggerInterface $logger,
    ) {
        $this->setEncryptionKey($config->encryptionKey);
    }

    public function token(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $resource = $body['resource'] ?? null;
        if ($resource !== null && $resource !== $this->config->resource) {
            return $this->responses->json(['error' => 'invalid_target', 'error_description' => 'El recurso solicitado no es este servidor.'], 400);
        }

        try {
            $response = $this->server->respondToAccessTokenRequest($request, $this->responseFactory->createResponse());
            $this->security->info('oauth.token_issued', null, ['grant' => (string) ($body['grant_type'] ?? ''), 'client_id' => (string) ($body['client_id'] ?? '')]);

            return $response->withHeader('Cache-Control', 'no-store')->withHeader('Pragma', 'no-cache');
        } catch (OAuthServerException $e) {
            $this->security->warning('oauth.token_error', null, ['error' => $e->getErrorType(), 'grant' => (string) ($body['grant_type'] ?? '')]);

            return $e->generateHttpResponse($this->responseFactory->createResponse())->withHeader('Cache-Control', 'no-store');
        } catch (\Throwable $e) {
            $this->logger->error('Error interno en /oauth/token', ['exception' => $e]);

            return $this->responses->json(['error' => 'server_error', 'error_description' => 'Error interno del servidor.'], 500);
        }
    }

    public function revoke(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        [$clientId, $secret] = $this->clientCredentials($request, $body);
        if ($clientId === null || !$this->clients->validateClient($clientId, $secret, 'authorization_code')) {
            return $this->responses->json(['error' => 'invalid_client'], 401);
        }
        $token = (string) ($body['token'] ?? '');
        if ($token === '') {
            return $this->responses->json(['error' => 'invalid_request', 'error_description' => 'Falta token.'], 400);
        }

        try {
            if (substr_count($token, '.') === 2) {
                $this->revokeAccessJwt($token, $clientId);
            } else {
                $this->revokeRefresh($token, $clientId);
            }
        } catch (\Throwable) {
            // RFC 7009: un token desconocido o invalido no es un error.
        }

        return $this->responses->empty(200);
    }

    private function revokeAccessJwt(string $jwt, string $clientId): void
    {
        $parsed = (new Parser(new JoseEncoder()))->parse($jwt);
        if (!$parsed instanceof UnencryptedToken || $parsed->claims()->get('client_id') !== $clientId) {
            return; // no pertenece a este cliente
        }
        $jti = (string) $parsed->claims()->get('jti', '');
        if ($jti !== '') {
            $this->accessTokens->revokeAccessToken($jti);
            $this->security->info('oauth.token_revoked', (int) $parsed->claims()->get('sub'), ['type' => 'access', 'client_id' => $clientId]);
        }
    }

    private function revokeRefresh(string $encrypted, string $clientId): void
    {
        $payload = json_decode($this->decrypt($encrypted), true);
        if (!is_array($payload) || ($payload['client_id'] ?? null) !== $clientId) {
            return;
        }
        if (!empty($payload['refresh_token_id'])) {
            $this->refreshTokens->revokeRefreshToken((string) $payload['refresh_token_id']);
        }
        if (!empty($payload['access_token_id'])) {
            $this->accessTokens->revokeAccessToken((string) $payload['access_token_id']);
        }
        $this->security->info('oauth.token_revoked', isset($payload['user_id']) ? (int) $payload['user_id'] : null, ['type' => 'refresh', 'client_id' => $clientId]);
    }

    /** @param array<string, mixed> $body @return array{0: ?string, 1: ?string} */
    private function clientCredentials(ServerRequestInterface $request, array $body): array
    {
        $auth = $request->getHeaderLine('Authorization');
        if (preg_match('/^Basic\s+(.+)$/i', $auth, $m)) {
            $decoded = base64_decode($m[1], true);
            if ($decoded !== false && str_contains($decoded, ':')) {
                [$id, $secret] = explode(':', $decoded, 2);

                return [rawurldecode($id), rawurldecode($secret)];
            }
        }

        return [isset($body['client_id']) ? (string) $body['client_id'] : null, isset($body['client_secret']) ? (string) $body['client_secret'] : null];
    }
}
