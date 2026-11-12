<?php

declare(strict_types=1);

namespace McpPhp\Http;

use Mcp\Server\Transport\Http\Middleware\AuthorizationMiddleware;
use Mcp\Server\Transport\Http\Middleware\CorsMiddleware;
use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
use Mcp\Server\Transport\Http\OAuth\ProtectedResourceMetadata;
use Mcp\Server\Transport\Http\OAuth\ProtectedResourceMetadataHandler;
use Mcp\Server\Transport\StreamableHttpTransport;
use McpPhp\Authorization\Permission;
use McpPhp\Core\Application;
use McpPhp\OAuth\Endpoints\AuthorizationEndpoint;
use McpPhp\OAuth\Endpoints\ClientRegistrationEndpoint;
use McpPhp\OAuth\Endpoints\MetadataEndpoint;
use McpPhp\OAuth\Endpoints\Responses;
use McpPhp\OAuth\Endpoints\TokenEndpoint;
use McpPhp\OAuth\Endpoints\WebSession;
use McpPhp\OAuth\Repositories\ScopeRepository;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Front controller HTTP:
 *   /mcp                                      Streamable HTTP (recurso protegido, OAuth 2.1)
 *   /.well-known/oauth-protected-resource/mcp RFC 9728
 *   /.well-known/oauth-authorization-server   RFC 8414
 *   /oauth/{authorize,callback,consent,token,revoke,register}
 *   /health
 */
final class Kernel
{
    private readonly Psr17Factory $factory;
    private readonly Responses $responses;

    public function __construct(private readonly Application $app)
    {
        $this->factory = new Psr17Factory();
        $this->responses = new Responses($this->factory, $this->factory);
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $path = rtrim($request->getUri()->getPath(), '/') ?: '/';
        $method = $request->getMethod();

        try {
            $response = $this->dispatch($request, $path, $method);
        } catch (\Throwable $e) {
            $this->app->logger()->error('Error no controlado en HTTP', ['path' => $path, 'exception' => $e]);
            $response = $this->responses->json(['error' => 'server_error', 'error_description' => 'Error interno del servidor.'], 500);
        }

        return $this->withCommonHeaders($request, $response, $path);
    }

    private function dispatch(ServerRequestInterface $request, string $path, string $method): ResponseInterface
    {
        if ($method === 'OPTIONS' && $path !== '/mcp') {
            return $this->factory->createResponse(204);
        }

        switch (true) {
            case $path === '/health':
                return $this->responses->json(['status' => 'ok'], 200, false);

            case $path === '/mcp':
                return $this->mcp($request);

            case str_starts_with($path, '/.well-known/oauth-protected-resource'):
                return (new ProtectedResourceMetadataHandler($this->resourceMetadata(), $this->factory, $this->factory))->handle($request);

            case $path === '/.well-known/oauth-authorization-server':
                return (new MetadataEndpoint($this->app->oauthConfig(), $this->responses))->handle();
        }

        if (str_starts_with($path, '/oauth/')) {
            return $this->oauth($request, $path, $method);
        }

        return $this->responses->json(['error' => 'not_found'], 404);
    }

    private function oauth(ServerRequestInterface $request, string $path, string $method): ResponseInterface
    {
        $cfg = $this->app->oauthConfig();
        $server = $this->app->authorizationServerFactory()->create();
        $secure = str_starts_with($cfg->issuer, 'https://');
        $session = new WebSession($secure, $this->app->config->path('SESSION_PATH', 'storage/sessions') . '/web');
        $factory = $this->app->authorizationServerFactory();

        $authz = fn () => new AuthorizationEndpoint(
            $server, $cfg, $this->app->identityProvider(), $this->app->identityLinkService(), $session,
            $this->responses, $this->factory, $this->app->securityLogger(),
            new ScopeRepository($cfg->scopes, $this->app->userRepository()),
        );
        $tokens = fn () => new TokenEndpoint(
            $server, $cfg, $this->factory, $this->responses, $factory->clients(), $factory->accessTokens(),
            $factory->refreshTokens(), $this->app->securityLogger(), $this->app->logger(),
        );

        return match (true) {
            $path === '/oauth/authorize' && $method === 'GET' => $authz()->authorize($request),
            $path === '/oauth/callback' && $method === 'GET' => $authz()->callback($request),
            $path === '/oauth/consent' && $method === 'POST' => $authz()->consent($request),
            $path === '/oauth/dev-login' && in_array($method, ['GET', 'POST'], true) => $authz()->devLogin($request),
            $path === '/oauth/token' && $method === 'POST' => $tokens()->token($request),
            $path === '/oauth/revoke' && $method === 'POST' => $tokens()->revoke($request),
            $path === '/oauth/register' && $method === 'POST' && $cfg->dcrEnabled => (new ClientRegistrationEndpoint(
                $factory->clients(), $this->responses, $this->app->securityLogger(), $this->app->clock(), $cfg->dcrMaxPerHour, $cfg->scopes,
            ))->handle($request),
            default => $this->responses->json(['error' => 'not_found'], 404),
        };
    }

    private function resourceMetadata(): ProtectedResourceMetadata
    {
        $cfg = $this->app->oauthConfig();

        return new ProtectedResourceMetadata(
            $cfg->resource,
            [$cfg->issuer],
            Permission::allScopes(),
            'MCP PHP CRUD Server',
        );
    }

    private function mcp(ServerRequestInterface $request): ResponseInterface
    {
        $config = $this->app->config;
        $cfg = $this->app->oauthConfig();
        $issuerHost = (string) parse_url($cfg->issuer, PHP_URL_HOST);
        $hosts = array_values(array_unique([...$config->list('MCP_ALLOWED_HOSTS', 'localhost,127.0.0.1,[::1]'), $issuerHost]));
        $origins = $config->list('MCP_ALLOWED_ORIGINS');

        $middleware = [
            new CorsMiddleware($origins),
            new DnsRebindingProtectionMiddleware($hosts, $origins),
            new AuthorizationMiddleware($this->app->accessTokenValidator(), $this->resourceMetadata(), null, $this->factory, $this->factory, StreamableHttpTransport::DEFAULT_MAX_BODY_BYTES, $this->app->logger()),
        ];

        $server = $this->app->serverFactory($this->app->httpPrincipalResolver(), true)->build();
        $transport = new StreamableHttpTransport($request, $this->factory, $this->factory, $this->app->logger(), $middleware);

        return $server->run($transport);
    }

    /** Cabeceras de seguridad y CORS para los endpoints OAuth/metadatos (el MCP aplica las suyas). */
    private function withCommonHeaders(ServerRequestInterface $request, ResponseInterface $response, string $path): ResponseInterface
    {
        $response = $response->withHeader('X-Content-Type-Options', 'nosniff');
        if ($path === '/mcp') {
            return $response;
        }
        try {
            if (str_starts_with($this->app->oauthConfig()->issuer, 'https://')) {
                $response = $response->withHeader('Strict-Transport-Security', 'max-age=31536000');
            }
        } catch (\Throwable) {
            // configuracion OAuth incompleta: ya se respondio con error generico
        }
        $origin = $request->getHeaderLine('Origin');
        $allowed = $this->app->config->list('MCP_ALLOWED_ORIGINS');
        $isApi = str_starts_with($path, '/.well-known/') || in_array($path, ['/oauth/token', '/oauth/register', '/oauth/revoke'], true);
        if ($isApi && $origin !== '' && (in_array('*', $allowed, true) || in_array($origin, $allowed, true))) {
            $response = $response
                ->withHeader('Access-Control-Allow-Origin', in_array('*', $allowed, true) ? '*' : $origin)
                ->withHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type, MCP-Protocol-Version')
                ->withHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
                ->withHeader('Vary', 'Origin');
        }

        return $response;
    }
}
