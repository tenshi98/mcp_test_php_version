<?php

declare(strict_types=1);

namespace McpPhp\OAuth\Endpoints;

use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Exception\OAuthServerException;
use McpPhp\Exceptions\McpPhpException;
use McpPhp\Logging\SecurityLogger;
use McpPhp\OAuth\Entities\UserEntity;
use McpPhp\OAuth\Identity\DevIdentityProvider;
use McpPhp\OAuth\Identity\IdentityLinkService;
use McpPhp\OAuth\Identity\IdentityProviderInterface;
use McpPhp\OAuth\OAuthConfig;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Flujo de autorizacion (authorization code + PKCE):
 *   /oauth/authorize  -> valida la peticion y redirige al proveedor de identidad externo
 *   /oauth/callback   -> el proveedor devuelve al usuario; se vincula con allowed_users; pantalla de consentimiento
 *   /oauth/consent    -> el usuario aprueba/deniega; se emite el codigo y se redirige al cliente
 */
final class AuthorizationEndpoint
{
    public function __construct(
        private readonly AuthorizationServer $server,
        private readonly OAuthConfig $config,
        private readonly IdentityProviderInterface $identityProvider,
        private readonly IdentityLinkService $links,
        private readonly WebSession $session,
        private readonly Responses $responses,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly SecurityLogger $security,
        private readonly \McpPhp\OAuth\Repositories\ScopeRepository $scopes,
    ) {
    }

    public function authorize(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $authRequest = $this->server->validateAuthorizationRequest($request);
            $params = $request->getQueryParams();

            // OAuth 2.1: PKCE con S256 obligatorio para todos los clientes.
            if (empty($params['code_challenge']) || ($params['code_challenge_method'] ?? '') !== 'S256') {
                throw $this->redirectingError($request, $authRequest->getRedirectUri(), 'invalid_request', 3, 'PKCE con code_challenge_method=S256 es obligatorio.');
            }
            // RFC 8707: si el cliente indica el recurso, debe ser este servidor MCP.
            if (isset($params['resource']) && $params['resource'] !== $this->config->resource) {
                throw $this->redirectingError($request, $authRequest->getRedirectUri(), 'invalid_target', 13, 'El recurso solicitado no es este servidor.');
            }
        } catch (OAuthServerException $e) {
            return $this->oauthError($e);
        }

        $state = bin2hex(random_bytes(24));
        $redirectUri = $this->config->endpoint('/oauth/callback');
        $begin = $this->identityProvider->begin($state, $redirectUri);

        $this->session->start();
        $this->session->regenerate();
        $this->session->set('oauth_params', $params);
        $this->session->set('oauth_upstream_state', $state);
        $this->session->set('oauth_upstream_ctx', $begin['context']);
        $this->session->forget('oauth_user_id', 'oauth_csrf');

        return $this->responses->redirect($begin['url']);
    }

    public function devLogin(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->identityProvider instanceof DevIdentityProvider) {
            return $this->responses->html('No encontrado', '<p>Ruta no disponible.</p>', 404);
        }
        $state = (string) ($request->getQueryParams()['state'] ?? ($request->getParsedBody()['state'] ?? ''));
        if ($request->getMethod() === 'POST') {
            $email = (string) ($request->getParsedBody()['email'] ?? '');
            $q = http_build_query(['state' => $state, 'code' => DevIdentityProvider::encodeCode($email)]);

            return $this->responses->redirect($this->config->endpoint('/oauth/callback') . '?' . $q);
        }
        $s = Responses::e($state);

        return $this->responses->html('Inicio de sesion (solo desarrollo)', <<<HTML
<p>Proveedor de identidad <code>dev</code>. No usar en produccion.</p>
<form method="post" action="/oauth/dev-login"><input type="hidden" name="state" value="$s">
<label>Correo electronico<input type="email" name="email" required autofocus></label>
<button class="ok" type="submit">Continuar</button></form>
HTML);
    }

    public function callback(ServerRequestInterface $request): ResponseInterface
    {
        $q = $request->getQueryParams();
        $expected = $this->session->get('oauth_upstream_state');
        if (!is_string($expected) || !isset($q['state']) || !hash_equals($expected, (string) $q['state'])
            || $this->session->get('oauth_params') === null) {
            $this->security->warning('oauth.callback_invalid_state');

            return $this->responses->html('Solicitud no valida', '<p>La sesion de autorizacion expiro o no es valida. Vuelva a iniciar la conexion desde su cliente MCP.</p>', 400);
        }
        $this->session->forget('oauth_upstream_state');

        if (isset($q['error']) || empty($q['code'])) {
            $this->security->info('oauth.upstream_error', null, ['error' => (string) ($q['error'] ?? 'no_code')]);

            return $this->responses->html('Autenticacion cancelada', '<p>No se completo la autenticacion con el proveedor de identidad.</p>', 400);
        }

        try {
            $identity = $this->identityProvider->complete((string) $q['code'], $this->config->endpoint('/oauth/callback'), (array) ($this->session->get('oauth_upstream_ctx') ?? []));
            $user = $this->links->resolve($identity);
        } catch (McpPhpException $e) {
            return $this->responses->html('Acceso denegado', '<p>' . Responses::e($e->getMessage()) . '</p>', 403);
        }

        $csrf = bin2hex(random_bytes(24));
        $this->session->set('oauth_user_id', (int) $user['id']);
        $this->session->set('oauth_csrf', $csrf);

        return $this->consentPage($request, (array) $this->session->get('oauth_params'), $user, $csrf);
    }

    public function consent(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $csrf = $this->session->get('oauth_csrf');
        $userId = $this->session->get('oauth_user_id');
        $params = $this->session->get('oauth_params');
        if (!is_string($csrf) || !hash_equals($csrf, (string) ($body['csrf'] ?? '')) || !is_int($userId) || !is_array($params)) {
            $this->security->warning('oauth.consent_csrf_failed', is_int($userId) ? $userId : null);

            return $this->responses->html('Solicitud no valida', '<p>La sesion expiro. Vuelva a iniciar la conexion.</p>', 400);
        }
        $this->session->forget('oauth_csrf', 'oauth_user_id', 'oauth_params');

        try {
            $authRequest = $this->server->validateAuthorizationRequest($request->withQueryParams($params));
            $authRequest->setUser(new UserEntity((string) $userId));
            $approved = ($body['decision'] ?? '') === 'approve';
            $authRequest->setAuthorizationApproved($approved);
            $this->security->info($approved ? 'oauth.authorization_granted' : 'oauth.authorization_denied', $userId, ['client_id' => $authRequest->getClient()->getIdentifier()]);

            return $this->server->completeAuthorizationRequest($authRequest, $this->responseFactory->createResponse());
        } catch (OAuthServerException $e) {
            return $this->oauthError($e);
        }
    }

    /** @param array<string, mixed> $params @param array<string, mixed> $user */
    private function consentPage(ServerRequestInterface $request, array $params, array $user, string $csrf): ResponseInterface
    {
        try {
            $authRequest = $this->server->validateAuthorizationRequest($request->withQueryParams($params));
        } catch (OAuthServerException $e) {
            return $this->oauthError($e);
        }
        $requested = array_map(static fn ($s) => $s->getIdentifier(), $authRequest->getScopes());
        $granted = array_map(
            static fn ($s) => $s->getIdentifier(),
            $this->scopes->finalizeScopes($authRequest->getScopes(), 'authorization_code', $authRequest->getClient(), (string) $user['id']),
        );
        $client = Responses::e($authRequest->getClient()->getName());
        $email = Responses::e((string) $user['email']);
        $scopeList = $granted === [] ? '<li><em>ninguno (su rol no permite estos permisos)</em></li>'
            : implode('', array_map(static fn ($s) => '<li><code>' . Responses::e($s) . '</code></li>', $granted));
        $c = Responses::e($csrf);
        unset($requested);

        return $this->responses->html('Autorizar acceso', <<<HTML
<p><strong>$client</strong> solicita acceder al servidor MCP en su nombre (<code>$email</code>) con estos permisos:</p>
<ul>$scopeList</ul>
<form method="post" action="/oauth/consent"><input type="hidden" name="csrf" value="$c">
<button class="ok" name="decision" value="approve" type="submit">Autorizar</button>
<button class="no" name="decision" value="deny" type="submit">Cancelar</button></form>
HTML);
    }

    /** Error OAuth que se devuelve al cliente por redireccion (incluye state). */
    private function redirectingError(ServerRequestInterface $request, ?string $redirectUri, string $type, int $code, string $message): OAuthServerException
    {
        $e = new OAuthServerException($message, $code, $type, 400, null, $redirectUri);
        $e->setServerRequest($request);

        return $e;
    }

    private function oauthError(OAuthServerException $e): ResponseInterface
    {
        $this->security->info('oauth.authorize_error', null, ['error' => $e->getErrorType()]);
        if ($e->hasRedirect()) {
            return $e->generateHttpResponse($this->responseFactory->createResponse());
        }

        return $this->responses->html('Solicitud no valida', '<p>' . Responses::e($e->getMessage()) . '</p>', 400);
    }
}
