<?php

declare(strict_types=1);

namespace McpPhp\OAuth\Endpoints;

use McpPhp\Logging\SecurityLogger;
use McpPhp\OAuth\Repositories\ClientRepository;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * RFC 7591: registro dinamico de clientes (los clientes MCP lo usan para obtener un client_id).
 * Los clientes registrados son "publicos" (PKCE obligatorio) salvo que pidan client_secret_basic/post.
 */
final class ClientRegistrationEndpoint
{
    private const LOOPBACK = ['localhost', '127.0.0.1', '[::1]'];
    private const FORBIDDEN_SCHEMES = ['javascript', 'data', 'file', 'vbscript', 'about', 'blob', 'ftp'];

    public function __construct(
        private readonly ClientRepository $clients,
        private readonly Responses $responses,
        private readonly SecurityLogger $security,
        private readonly ClockInterface $clock,
        private readonly int $maxPerHour,
        private readonly array $scopes,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body = (string) $request->getBody();
        if (strlen($body) > 16384) {
            return $this->error('invalid_client_metadata', 'Cuerpo demasiado grande.', 413);
        }
        $data = json_decode($body, true);
        if (!is_array($data) || array_is_list($data)) {
            return $this->error('invalid_client_metadata', 'Se esperaba un objeto JSON.');
        }

        $uris = $data['redirect_uris'] ?? null;
        if (!is_array($uris) || $uris === [] || count($uris) > 10) {
            return $this->error('invalid_redirect_uri', 'redirect_uris debe ser una lista de 1 a 10 URIs.');
        }
        foreach ($uris as $uri) {
            if (!is_string($uri) || !$this->isAcceptableRedirect($uri)) {
                return $this->error('invalid_redirect_uri', 'Redirect URI no aceptada: use https, http en loopback (localhost/127.0.0.1) o un esquema privado de aplicacion nativa; sin fragmentos.');
            }
        }

        $method = $data['token_endpoint_auth_method'] ?? 'none';
        if (!in_array($method, ['none', 'client_secret_basic', 'client_secret_post'], true)) {
            return $this->error('invalid_client_metadata', 'token_endpoint_auth_method no soportado.');
        }
        $grants = $data['grant_types'] ?? ['authorization_code', 'refresh_token'];
        if (!is_array($grants) || array_diff($grants, ['authorization_code', 'refresh_token']) !== []) {
            return $this->error('invalid_client_metadata', 'grant_types soportados: authorization_code, refresh_token.');
        }
        $name = $data['client_name'] ?? 'Cliente MCP';
        if (!is_string($name) || trim($name) === '' || mb_strlen($name) > 120) {
            return $this->error('invalid_client_metadata', 'client_name invalido.');
        }
        $name = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '');

        if ($this->clients->countRegisteredSince($this->clock->now()->modify('-1 hour')) >= $this->maxPerHour) {
            $this->security->warning('oauth.dcr_rate_limited');

            return $this->error('temporarily_unavailable', 'Demasiados registros recientes; intente mas tarde.', 429);
        }

        $confidential = $method !== 'none';
        $reg = $this->clients->register($name, array_values($uris), $confidential);
        $this->security->info('oauth.client_registered', null, ['client_id' => $reg['client_id'], 'confidential' => $confidential]);

        $out = [
            'client_id' => $reg['client_id'],
            'client_id_issued_at' => $this->clock->now()->getTimestamp(),
            'client_name' => $name,
            'redirect_uris' => array_values($uris),
            'grant_types' => array_values($grants),
            'response_types' => ['code'],
            'token_endpoint_auth_method' => $method,
            'scope' => implode(' ', $this->scopes),
        ];
        if ($reg['client_secret'] !== null) {
            $out['client_secret'] = $reg['client_secret'];
            $out['client_secret_expires_at'] = 0;
        }

        return $this->responses->json($out, 201);
    }

    private function isAcceptableRedirect(string $uri): bool
    {
        if (strlen($uri) > 2000 || preg_match('/[\x00-\x20]/', $uri) || str_contains($uri, '#')) {
            return false;
        }
        $p = parse_url($uri);
        if ($p === false || !isset($p['scheme'])) {
            return false;
        }
        $scheme = strtolower($p['scheme']);
        if (in_array($scheme, self::FORBIDDEN_SCHEMES, true)) {
            return false;
        }
        if ($scheme === 'https') {
            return isset($p['host']);
        }
        if ($scheme === 'http') {
            return isset($p['host']) && in_array(strtolower($p['host']), self::LOOPBACK, true);
        }

        return true; // esquema privado de app nativa (RFC 8252), p. ej. cursor://, vscode://
    }

    private function error(string $code, string $description, int $status = 400): ResponseInterface
    {
        return $this->responses->json(['error' => $code, 'error_description' => $description], $status);
    }
}
