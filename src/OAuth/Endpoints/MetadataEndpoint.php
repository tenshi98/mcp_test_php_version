<?php

declare(strict_types=1);

namespace McpPhp\OAuth\Endpoints;

use McpPhp\OAuth\OAuthConfig;
use Psr\Http\Message\ResponseInterface;

/** RFC 8414: metadatos del servidor de autorizacion. */
final class MetadataEndpoint
{
    public function __construct(private readonly OAuthConfig $config, private readonly Responses $responses)
    {
    }

    public function handle(): ResponseInterface
    {
        $c = $this->config;
        $meta = [
            'issuer' => $c->issuer,
            'authorization_endpoint' => $c->endpoint('/oauth/authorize'),
            'token_endpoint' => $c->endpoint('/oauth/token'),
            'revocation_endpoint' => $c->endpoint('/oauth/revoke'),
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none', 'client_secret_basic', 'client_secret_post'],
            'revocation_endpoint_auth_methods_supported' => ['none', 'client_secret_basic', 'client_secret_post'],
            'scopes_supported' => $c->scopes,
            'resource_indicators_supported' => true,
        ];
        if ($c->dcrEnabled) {
            $meta['registration_endpoint'] = $c->endpoint('/oauth/register');
        }

        return $this->responses->json($meta, 200, false)->withHeader('Cache-Control', 'public, max-age=300');
    }
}
