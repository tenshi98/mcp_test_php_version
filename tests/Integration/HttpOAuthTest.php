<?php

declare(strict_types=1);

namespace McpPhp\Tests\Integration;

use McpPhp\Tests\Support\DatabaseTestCase;
use McpPhp\Tests\Support\HttpServerProcess;
use McpPhp\Tests\Support\TestHttpClient;

/**
 * Servidor HTTP real (php -S) con el flujo OAuth 2.1 completo:
 * descubrimiento, registro dinamico, authorization code + PKCE, refresh con rotacion,
 * revocacion y llamadas MCP Streamable HTTP con Bearer.
 * El proveedor de identidad usado es "dev" (solo pruebas); la vinculacion de usuarios es la real.
 */
final class HttpOAuthTest extends DatabaseTestCase
{
    private static ?HttpServerProcess $server = null;
    private TestHttpClient $http;
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        if (self::$server === null) {
            self::$server = new HttpServerProcess(self::dbEnv() + ['APP_KEY' => self::APP_KEY]);
        }
        $this->base = self::$server->url;
        $this->http = new TestHttpClient($this->base);
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$server = null;
    }

    private const REDIRECT = 'http://127.0.0.1:9999/callback';

    /** @return array{client_id: string} */
    private function register(array $over = []): array
    {
        $r = $this->http->request('POST', '/oauth/register', json_encode($over + ['client_name' => 'PHPUnit', 'redirect_uris' => [self::REDIRECT]]), ['Content-Type' => 'application/json']);
        $this->assertSame(201, $r['status'], $r['body']);

        return $this->http->json($r);
    }

    /** @return array{verifier: string, challenge: string} */
    private function pkce(): array
    {
        $v = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');

        return ['verifier' => $v, 'challenge' => rtrim(strtr(base64_encode(hash('sha256', $v, true)), '+/', '-_'), '=')];
    }

    /**
     * Recorre authorize -> login -> consent y devuelve [code, verifier, client_id, scopes mostrados].
     * Si el login es denegado devuelve el resultado del callback.
     *
     * @return array<string, mixed>
     */
    private function authorize(string $email, ?string $clientId = null, string $decision = 'approve'): array
    {
        $clientId ??= $this->register()['client_id'];
        $pk = $this->pkce();
        $q = http_build_query([
            'response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => self::REDIRECT, 'state' => 'st-123',
            'code_challenge' => $pk['challenge'], 'code_challenge_method' => 'S256', 'resource' => $this->base . '/mcp',
        ]);
        $a = $this->http->request('GET', '/oauth/authorize?' . $q);
        $this->assertSame(302, $a['status'], $a['body']);
        $this->assertStringContainsString('/oauth/dev-login', $a['headers']['location']);
        parse_str((string) parse_url($a['headers']['location'], PHP_URL_QUERY), $loc);

        $login = $this->http->request('POST', '/oauth/dev-login', ['state' => $loc['state'], 'email' => $email]);
        $this->assertSame(302, $login['status']);
        $cb = $this->http->request('GET', $login['headers']['location']);
        if ($cb['status'] !== 200) {
            return ['denied' => $cb];
        }
        preg_match('/name="csrf" value="([^"]+)"/', $cb['body'], $m);
        preg_match_all('#<code>(records:[^<]+)</code>#', $cb['body'], $codes);
        $consent = $this->http->request('POST', '/oauth/consent', ['csrf' => $m[1], 'decision' => $decision]);
        $this->assertSame(302, $consent['status']);
        parse_str((string) parse_url($consent['headers']['location'], PHP_URL_QUERY), $out);

        return ['query' => $out, 'verifier' => $pk['verifier'], 'client_id' => $clientId, 'shown' => $codes[1], 'location' => $consent['headers']['location']];
    }

    /** @return array<string, mixed> tokens */
    private function login(string $email = 'admin@example.com'): array
    {
        $r = $this->authorize($email);
        $this->assertArrayHasKey('code', $r['query'], json_encode($r));
        $this->assertSame('st-123', $r['query']['state']);
        $t = $this->http->request('POST', '/oauth/token', [
            'grant_type' => 'authorization_code', 'code' => $r['query']['code'], 'redirect_uri' => self::REDIRECT,
            'client_id' => $r['client_id'], 'code_verifier' => $r['verifier'], 'resource' => $this->base . '/mcp',
        ]);
        $this->assertSame(200, $t['status'], $t['body']);
        $tok = $this->http->json($t);
        $tok['client_id'] = $r['client_id'];
        $tok['code'] = $r['query']['code'];
        $tok['verifier'] = $r['verifier'];

        return $tok;
    }

    /** @return array{status: int, headers: array<string, string>, body: string} */
    private function mcp(string $accessToken, array $message, array $extra = []): array
    {
        return $this->http->request('POST', '/mcp', json_encode($message), [
            'Content-Type' => 'application/json', 'Accept' => 'application/json, text/event-stream', 'Authorization' => 'Bearer ' . $accessToken,
        ] + $extra);
    }

    /** @return array<string, mixed> */
    private function rpc(array $response): array
    {
        $body = $response['body'];
        if (preg_match('/^data: (.+)$/m', $body, $m)) {
            $body = $m[1];
        }

        return json_decode($body, true) ?? [];
    }

    /** Estado HTTP de una peticion `initialize` autenticada con $token (200 = aceptado, 401 = rechazado). */
    private function probe(string $token): int
    {
        return $this->mcp($token, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'probe', 'version' => '1']]])['status'];
    }

    /** @return array{0: array<string, string>} headers para peticiones posteriores a initialize */
    private function session(string $token): array
    {
        $init = $this->mcp($token, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'phpunit', 'version' => '1']]]);
        $this->assertSame(200, $init['status'], $init['body']);
        $h = ['MCP-Protocol-Version' => '2025-06-18'];
        if (isset($init['headers']['mcp-session-id'])) {
            $h['Mcp-Session-Id'] = $init['headers']['mcp-session-id'];
        }
        $this->mcp($token, ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], $h);

        return $h;
    }

    // ---- descubrimiento ------------------------------------------------------------

    public function testHealthAndUnknownRoutes(): void
    {
        $this->assertSame(200, $this->http->request('GET', '/health')['status']);
        $this->assertSame(404, $this->http->request('GET', '/nada')['status']);
        $this->assertSame(404, $this->http->request('GET', '/oauth/otra')['status']);
    }

    public function testProtectedResourceMetadata(): void
    {
        $r = $this->http->request('GET', '/.well-known/oauth-protected-resource/mcp');
        $this->assertSame(200, $r['status']);
        $m = $this->http->json($r);
        $this->assertSame($this->base . '/mcp', $m['resource']);
        $this->assertSame([$this->base], $m['authorization_servers']);
        $this->assertContains('records:read', $m['scopes_supported']);
        $this->assertSame(['header'], $m['bearer_methods_supported']);
    }

    public function testAuthorizationServerMetadata(): void
    {
        $m = $this->http->json($this->http->request('GET', '/.well-known/oauth-authorization-server'));
        $this->assertSame($this->base, $m['issuer']);
        $this->assertSame(['S256'], $m['code_challenge_methods_supported'], 'OAuth 2.1: solo S256');
        $this->assertSame(['authorization_code', 'refresh_token'], $m['grant_types_supported']);
        $this->assertNotContains('implicit', $m['grant_types_supported']);
        $this->assertNotContains('password', $m['grant_types_supported']);
        $this->assertSame(['code'], $m['response_types_supported']);
        $this->assertSame($this->base . '/oauth/register', $m['registration_endpoint']);
        foreach (['authorization_endpoint', 'token_endpoint', 'revocation_endpoint'] as $k) {
            $this->assertStringStartsWith($this->base, $m[$k]);
        }
    }

    public function testMcpWithoutTokenReturnsChallenge(): void
    {
        $r = $this->http->request('POST', '/mcp', '{}', ['Content-Type' => 'application/json', 'Accept' => 'application/json, text/event-stream']);
        $this->assertSame(401, $r['status']);
        $this->assertStringStartsWith('Bearer', $r['headers']['www-authenticate']);
        $this->assertStringContainsString('resource_metadata="' . $this->base . '/.well-known/oauth-protected-resource/mcp"', $r['headers']['www-authenticate']);
    }

    // ---- registro dinamico (RFC 7591) -------------------------------------------------

    public function testDynamicClientRegistration(): void
    {
        $c = $this->register(['client_name' => 'Mi cliente']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $c['client_id']);
        $this->assertSame('none', $c['token_endpoint_auth_method']);
        $this->assertArrayNotHasKey('client_secret', $c);
        $row = $this->app->connection()->run('SELECT * FROM oauth_clients WHERE id = :id', ['id' => $c['client_id']])->fetch();
        $this->assertSame('Mi cliente', $row['name']);
        $this->assertSame(0, (int) $row['is_confidential']);
    }

    public function testConfidentialClientGetsHashedSecret(): void
    {
        $c = $this->register(['token_endpoint_auth_method' => 'client_secret_post']);
        $this->assertNotEmpty($c['client_secret']);
        $row = $this->app->connection()->run('SELECT secret_hash FROM oauth_clients WHERE id = :id', ['id' => $c['client_id']])->fetch();
        $this->assertNotSame($c['client_secret'], $row['secret_hash']);
        $this->assertTrue(password_verify($c['client_secret'], $row['secret_hash']));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function badRegistrations(): iterable
    {
        yield 'sin redirect_uris' => [['client_name' => 'x']];
        yield 'javascript:' => [['redirect_uris' => ['javascript:alert(1)']]];
        yield 'http no loopback' => [['redirect_uris' => ['http://evil.example.com/cb']]];
        yield 'fragmento' => [['redirect_uris' => ['https://app.example.com/cb#x']]];
        yield 'file:' => [['redirect_uris' => ['file:///etc/passwd']]];
        yield 'no es URI' => [['redirect_uris' => ['no-es-una-uri']]];
        yield 'redirect_uris no lista' => [['redirect_uris' => 'https://a.example.com']];
        yield 'metodo de auth raro' => [['redirect_uris' => ['https://a.example.com/cb'], 'token_endpoint_auth_method' => 'private_key_jwt']];
        yield 'grant implicit' => [['redirect_uris' => ['https://a.example.com/cb'], 'grant_types' => ['implicit']]];
        yield 'grant password' => [['redirect_uris' => ['https://a.example.com/cb'], 'grant_types' => ['password']]];
    }

    /** @param array<string, mixed> $body */
    #[\PHPUnit\Framework\Attributes\DataProvider('badRegistrations')]
    public function testRejectsInvalidRegistrations(array $body): void
    {
        $r = $this->http->request('POST', '/oauth/register', json_encode($body), ['Content-Type' => 'application/json']);
        $this->assertSame(400, $r['status'], $r['body']);
        $this->assertArrayHasKey('error', $this->http->json($r));
    }

    public function testRegistrationAcceptsHttpsLoopbackAndNativeSchemes(): void
    {
        $c = $this->register(['redirect_uris' => ['https://app.example.com/cb', 'http://localhost:5555/cb', 'http://[::1]:5555/cb', 'cursor://anysphere.cursor-retrieval/oauth']]);
        $this->assertCount(4, $c['redirect_uris']);
    }

    // ---- flujo authorization code + PKCE ----------------------------------------------------

    public function testFullFlowIssuesJwtBoundToTheResource(): void
    {
        $t = $this->login();
        $this->assertSame('Bearer', $t['token_type']);
        $this->assertSame(3600, $t['expires_in']);
        $this->assertNotEmpty($t['refresh_token']);

        [, $payload] = explode('.', $t['access_token']);
        $claims = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
        $this->assertSame([$this->base . '/mcp'], (array) $claims['aud'], 'audiencia = recurso MCP (RFC 8707)');
        $this->assertSame($this->base, $claims['iss']);
        $this->assertSame((string) $this->userId('admin@example.com'), $claims['sub']);
        $this->assertSame($t['client_id'], $claims['client_id']);
        $this->assertEqualsCanonicalizing(['records:read', 'records:write', 'records:delete'], $claims['scopes']);
        $this->assertLessThanOrEqual(3600, $claims['exp'] - $claims['iat']);

        $this->assertSame('no-store', $this->http->request('POST', '/oauth/token', ['grant_type' => 'refresh_token', 'refresh_token' => $t['refresh_token'], 'client_id' => $t['client_id']])['headers']['cache-control']);
    }

    public function testMcpToolsOverStreamableHttpWithOAuthToken(): void
    {
        $t = $this->login();
        $h = $this->session($t['access_token']);

        $list = $this->rpc($this->mcp($t['access_token'], ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], $h));
        $this->assertEqualsCanonicalizing(
            ['list_records', 'get_record', 'search_records', 'create_record', 'update_record', 'delete_record'],
            array_column($list['result']['tools'], 'name'),
        );

        $call = fn (string $tool, array $args, int $id) => $this->rpc($this->mcp($t['access_token'], ['jsonrpc' => '2.0', 'id' => $id, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => (object) $args]], $h));
        $created = $call('create_record', ['first_name' => 'Http', 'last_name' => 'Oauth', 'email' => 'http.oauth@example.test', 'title' => 'via http', 'category' => 'pruebas'], 3);
        $this->assertFalse($created['result']['isError']);
        $id = $created['result']['structuredContent']['record']['id'];
        $this->assertSame('via http', $call('get_record', ['id' => $id], 4)['result']['structuredContent']['record']['title']);
        $this->assertTrue($call('delete_record', ['id' => $id, 'confirm' => true], 5)['result']['structuredContent']['deleted']);
    }

    public function testViewerGetsReadOnlyScopesAndCannotDelete(): void
    {
        $r = $this->authorize('viewer@example.com');
        $this->assertSame(['records:read'], $r['shown'], 'la pantalla de consentimiento solo ofrece lo que permite el rol');
        $t = $this->http->json($this->http->request('POST', '/oauth/token', [
            'grant_type' => 'authorization_code', 'code' => $r['query']['code'], 'redirect_uri' => self::REDIRECT, 'client_id' => $r['client_id'], 'code_verifier' => $r['verifier'],
        ]));
        [, $payload] = explode('.', $t['access_token']);
        $this->assertSame(['records:read'], json_decode(base64_decode(strtr($payload, '-_', '+/')), true)['scopes']);

        $h = $this->session($t['access_token']);
        $del = $this->rpc($this->mcp($t['access_token'], ['jsonrpc' => '2.0', 'id' => 9, 'method' => 'tools/call', 'params' => ['name' => 'delete_record', 'arguments' => ['id' => 1, 'confirm' => true]]], $h));
        $this->assertTrue($del['result']['isError']);
        $this->assertStringContainsString('forbidden', $del['result']['content'][0]['text']);
        $this->assertSame(30, (int) $this->app->connection()->run('SELECT COUNT(*) FROM sample_records')->fetchColumn());
    }

    public function testUsersOutsideTheAllowListAreDenied(): void
    {
        foreach (['stranger@example.com', 'suspended@example.com'] as $email) {
            $r = $this->authorize($email);
            $this->assertSame(403, $r['denied']['status'], $email);
            $this->assertStringNotContainsString('code=', $r['denied']['body']);
        }
        $this->assertSame(0, (int) $this->app->connection()->run('SELECT COUNT(*) FROM oauth_auth_codes')->fetchColumn());
        $this->assertGreaterThanOrEqual(2, count($this->events('oauth.login_denied')));
    }

    public function testDenyingConsentReturnsAccessDenied(): void
    {
        $r = $this->authorize('admin@example.com', null, 'deny');
        $this->assertSame('access_denied', $r['query']['error']);
        $this->assertSame('st-123', $r['query']['state']);
        $this->assertArrayNotHasKey('code', $r['query']);
    }

    public function testFirstLoginLinksTheExternalIdentity(): void
    {
        $this->assertSame(0, (int) $this->app->connection()->run('SELECT COUNT(*) FROM oauth_users')->fetchColumn());
        $this->login('editor@example.com');
        $links = $this->app->userRepository()->oauthIdentitiesOf($this->userId('editor@example.com'));
        $this->assertCount(1, $links);
        $this->assertSame('dev', $links[0]['provider']);
    }

    // ---- endurecimiento OAuth 2.1 -------------------------------------------------------------

    public function testPkceIsMandatory(): void
    {
        $cid = $this->register()['client_id'];
        $base = ['response_type' => 'code', 'client_id' => $cid, 'redirect_uri' => self::REDIRECT, 'state' => 's'];
        $pk = $this->pkce();
        foreach ([
            'sin PKCE' => $base,
            'plain' => $base + ['code_challenge' => $pk['verifier'], 'code_challenge_method' => 'plain'],
            'sin metodo' => $base + ['code_challenge' => $pk['challenge']],
        ] as $label => $params) {
            $r = $this->http->request('GET', '/oauth/authorize?' . http_build_query($params));
            $this->assertNotSame(302 === $r['status'] && str_contains($r['headers']['location'] ?? '', '/oauth/dev-login'), true, "$label no debe llegar al login");
            $this->assertContains($r['status'], [302, 400], $label);
        }
    }

    public function testWrongResourceIsRejected(): void
    {
        $cid = $this->register()['client_id'];
        $pk = $this->pkce();
        $r = $this->http->request('GET', '/oauth/authorize?' . http_build_query([
            'response_type' => 'code', 'client_id' => $cid, 'redirect_uri' => self::REDIRECT, 'state' => 's',
            'code_challenge' => $pk['challenge'], 'code_challenge_method' => 'S256', 'resource' => 'https://otro-servidor.example.com/mcp',
        ]));
        $this->assertSame(302, $r['status']);
        $this->assertStringContainsString('error=invalid_target', $r['headers']['location']);
        $this->assertStringStartsWith(self::REDIRECT, $r['headers']['location']);
    }

    public function testUnregisteredRedirectUriIsNeverRedirectedTo(): void
    {
        $cid = $this->register()['client_id'];
        $pk = $this->pkce();
        $r = $this->http->request('GET', '/oauth/authorize?' . http_build_query([
            'response_type' => 'code', 'client_id' => $cid, 'redirect_uri' => 'http://evil.example.com/cb', 'state' => 's',
            'code_challenge' => $pk['challenge'], 'code_challenge_method' => 'S256',
        ]));
        $this->assertSame(400, $r['status']);
        $this->assertArrayNotHasKey('location', $r['headers']);
    }

    public function testUnknownClientAndUnsupportedResponseType(): void
    {
        $pk = $this->pkce();
        $q = ['redirect_uri' => self::REDIRECT, 'code_challenge' => $pk['challenge'], 'code_challenge_method' => 'S256'];
        $this->assertSame(400, $this->http->request('GET', '/oauth/authorize?' . http_build_query($q + ['response_type' => 'code', 'client_id' => 'no-existe']))['status']);
        $cid = $this->register()['client_id'];
        $r = $this->http->request('GET', '/oauth/authorize?' . http_build_query($q + ['response_type' => 'token', 'client_id' => $cid, 'state' => 's']));
        $this->assertNotSame(200, $r['status']);
        $this->assertStringNotContainsString('access_token', $r['headers']['location'] ?? '', 'no existe el flujo implicito');
    }

    public function testAuthorizationCodeIsSingleUse(): void
    {
        $t = $this->login();
        $again = $this->http->request('POST', '/oauth/token', [
            'grant_type' => 'authorization_code', 'code' => $t['code'], 'redirect_uri' => self::REDIRECT, 'client_id' => $t['client_id'], 'code_verifier' => $t['verifier'],
        ]);
        $this->assertSame(400, $again['status']);
        $this->assertSame('invalid_grant', $this->http->json($again)['error']);
    }

    public function testWrongPkceVerifierIsRejected(): void
    {
        $r = $this->authorize('admin@example.com');
        $t = $this->http->request('POST', '/oauth/token', [
            'grant_type' => 'authorization_code', 'code' => $r['query']['code'], 'redirect_uri' => self::REDIRECT, 'client_id' => $r['client_id'], 'code_verifier' => $this->pkce()['verifier'],
        ]);
        $this->assertSame(400, $t['status']);
        $this->assertArrayNotHasKey('access_token', $this->http->json($t));
    }

    public function testMissingVerifierAndWrongRedirectOnTokenEndpoint(): void
    {
        $r = $this->authorize('admin@example.com');
        $noVerifier = $this->http->request('POST', '/oauth/token', ['grant_type' => 'authorization_code', 'code' => $r['query']['code'], 'redirect_uri' => self::REDIRECT, 'client_id' => $r['client_id']]);
        $this->assertSame(400, $noVerifier['status']);
        $r2 = $this->authorize('admin@example.com');
        $wrongRedirect = $this->http->request('POST', '/oauth/token', ['grant_type' => 'authorization_code', 'code' => $r2['query']['code'], 'redirect_uri' => 'http://127.0.0.1:1/otro', 'client_id' => $r2['client_id'], 'code_verifier' => $r2['verifier']]);
        $this->assertSame(400, $wrongRedirect['status']);
    }

    public function testCodeCannotBeRedeemedByAnotherClient(): void
    {
        $r = $this->authorize('admin@example.com');
        $other = $this->register()['client_id'];
        $t = $this->http->request('POST', '/oauth/token', ['grant_type' => 'authorization_code', 'code' => $r['query']['code'], 'redirect_uri' => self::REDIRECT, 'client_id' => $other, 'code_verifier' => $r['verifier']]);
        $this->assertContains($t['status'], [400, 401]);
        $this->assertArrayNotHasKey('access_token', $this->http->json($t));
    }

    public function testUnsupportedGrantsAreRejected(): void
    {
        $cid = $this->register()['client_id'];
        foreach (['password' => ['username' => 'admin@example.com', 'password' => 'x'], 'client_credentials' => [], 'implicit' => []] as $grant => $extra) {
            $r = $this->http->request('POST', '/oauth/token', ['grant_type' => $grant, 'client_id' => $cid] + $extra);
            $this->assertSame(400, $r['status'], $grant);
            $this->assertSame('unsupported_grant_type', $this->http->json($r)['error']);
        }
    }

    public function testTokenRequestWithWrongResourceIsRejected(): void
    {
        $r = $this->authorize('admin@example.com');
        $t = $this->http->request('POST', '/oauth/token', ['grant_type' => 'authorization_code', 'code' => $r['query']['code'], 'redirect_uri' => self::REDIRECT, 'client_id' => $r['client_id'], 'code_verifier' => $r['verifier'], 'resource' => 'https://otro.example.com/mcp']);
        $this->assertSame(400, $t['status']);
        $this->assertSame('invalid_target', $this->http->json($t)['error']);
    }

    public function testConfidentialClientMustAuthenticate(): void
    {
        $c = $this->register(['token_endpoint_auth_method' => 'client_secret_post']);
        $r = $this->authorize('admin@example.com', $c['client_id']);
        $bad = $this->http->request('POST', '/oauth/token', ['grant_type' => 'authorization_code', 'code' => $r['query']['code'], 'redirect_uri' => self::REDIRECT, 'client_id' => $c['client_id'], 'client_secret' => 'incorrecto', 'code_verifier' => $r['verifier']]);
        $this->assertSame(401, $bad['status']);
        $r = $this->authorize('admin@example.com', $c['client_id']);
        $ok = $this->http->request('POST', '/oauth/token', ['grant_type' => 'authorization_code', 'code' => $r['query']['code'], 'redirect_uri' => self::REDIRECT, 'client_id' => $c['client_id'], 'client_secret' => $c['client_secret'], 'code_verifier' => $r['verifier']]);
        $this->assertSame(200, $ok['status'], $ok['body']);
    }

    // ---- validacion del Bearer ---------------------------------------------------------------

    /** @return iterable<string, array{string}> */
    public static function badBearers(): iterable
    {
        yield 'basura' => ['no-es-un-token'];
        yield 'jwt falso' => ['eyJhbGciOiJub25lIn0.eyJzdWIiOiIxIn0.'];
        yield 'token personal (desactivado en HTTP)' => ['mcp_' . 'A1b2C3d4E5f6G7h8I9j0K1l2M3n4O5p6Q7r8S9t0U1v'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badBearers')]
    public function testInvalidBearerTokensAreRejected(string $token): void
    {
        $r = $this->mcp($token, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);
        $this->assertSame(401, $r['status']);
        $this->assertStringContainsString('resource_metadata=', $r['headers']['www-authenticate']);
    }

    public function testPersonalTokenIsRejectedOverHttpByDefault(): void
    {
        $personal = $this->app->tokenService()->issue($this->userId('admin@example.com'), 'x', 3600)['token'];
        $this->assertSame(401, $this->probe($personal));
    }

    public function testTokenIssuedForAnotherResourceIsRejected(): void
    {
        // Mismo firmante pero audiencia distinta: se firma con la clave privada del servidor de pruebas.
        $priv = file_get_contents(self::$server->tmp . '/keys/private.pem');
        $cfg = \Lcobucci\JWT\Configuration::forAsymmetricSigner(new \Lcobucci\JWT\Signer\Rsa\Sha256(), \Lcobucci\JWT\Signer\Key\InMemory::plainText($priv), \Lcobucci\JWT\Signer\Key\InMemory::plainText('x'));
        $now = new \DateTimeImmutable();
        $jwt = $cfg->builder()->issuedBy($this->base)->permittedFor('https://otro.example.com/mcp')->identifiedBy('abc')->issuedAt($now)->canOnlyBeUsedAfter($now)
            ->expiresAt($now->modify('+1 hour'))->relatedTo('1')->withClaim('scopes', ['records:read'])->getToken($cfg->signer(), $cfg->signingKey())->toString();
        $this->assertSame(401, $this->probe($jwt));
    }

    public function testDnsRebindingProtection(): void
    {
        $t = $this->login();
        $r = $this->mcp($t['access_token'], ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], ['Host' => 'evil.example.com']);
        $this->assertSame(403, $r['status']);
        $r = $this->mcp($t['access_token'], ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], ['Origin' => 'http://evil.example.com']);
        $this->assertSame(403, $r['status']);
    }

    // ---- refresh y revocacion -------------------------------------------------------------------

    public function testRefreshRotatesTokensAndOldRefreshIsUnusable(): void
    {
        $t = $this->login();
        $r = $this->http->request('POST', '/oauth/token', ['grant_type' => 'refresh_token', 'refresh_token' => $t['refresh_token'], 'client_id' => $t['client_id']]);
        $this->assertSame(200, $r['status'], $r['body']);
        $n = $this->http->json($r);
        $this->assertNotSame($t['access_token'], $n['access_token']);
        $this->assertNotSame($t['refresh_token'], $n['refresh_token'], 'rotacion de refresh tokens');
        $this->assertSame(200, $this->probe($n['access_token']));

        $reuse = $this->http->request('POST', '/oauth/token', ['grant_type' => 'refresh_token', 'refresh_token' => $t['refresh_token'], 'client_id' => $t['client_id']]);
        $this->assertSame(400, $reuse['status']);
    }

    public function testRefreshCannotBeUsedByAnotherClient(): void
    {
        $t = $this->login();
        $other = $this->register()['client_id'];
        $r = $this->http->request('POST', '/oauth/token', ['grant_type' => 'refresh_token', 'refresh_token' => $t['refresh_token'], 'client_id' => $other]);
        $this->assertSame(400, $r['status']);
    }

    public function testRefreshDowngradesScopesWhenRoleChanges(): void
    {
        $t = $this->login('editor@example.com');
        $this->app->userRepository()->setRole($this->userId('editor@example.com'), 'viewer');
        $n = $this->http->json($this->http->request('POST', '/oauth/token', ['grant_type' => 'refresh_token', 'refresh_token' => $t['refresh_token'], 'client_id' => $t['client_id']]));
        [, $payload] = explode('.', $n['access_token']);
        $this->assertSame(['records:read'], json_decode(base64_decode(strtr($payload, '-_', '+/')), true)['scopes']);
    }

    public function testRevokingAccessTokenInvalidatesItImmediately(): void
    {
        $t = $this->login();
        $this->assertSame(200, $this->probe($t['access_token']));
        $rev = $this->http->request('POST', '/oauth/revoke', ['token' => $t['access_token'], 'client_id' => $t['client_id']]);
        $this->assertSame(200, $rev['status']);
        $this->assertSame(401, $this->probe($t['access_token']));
        $this->assertSame(400, $this->http->request('POST', '/oauth/token', ['grant_type' => 'refresh_token', 'refresh_token' => $t['refresh_token'], 'client_id' => $t['client_id']])['status'], 'al revocar el access token tambien cae su refresh');
    }

    public function testRevokingRefreshTokenKillsTheGrant(): void
    {
        $t = $this->login();
        $this->assertSame(200, $this->http->request('POST', '/oauth/revoke', ['token' => $t['refresh_token'], 'client_id' => $t['client_id']])['status']);
        $this->assertSame(400, $this->http->request('POST', '/oauth/token', ['grant_type' => 'refresh_token', 'refresh_token' => $t['refresh_token'], 'client_id' => $t['client_id']])['status']);
        $this->assertSame(401, $this->probe($t['access_token']));
    }

    public function testRevokeRequiresTheOwningClientAndIgnoresGarbage(): void
    {
        $t = $this->login();
        $other = $this->register()['client_id'];
        $this->http->request('POST', '/oauth/revoke', ['token' => $t['access_token'], 'client_id' => $other]);
        $this->assertSame(200, $this->probe($t['access_token']), 'otro cliente no puede revocar mi token');
        $this->assertSame(200, $this->http->request('POST', '/oauth/revoke', ['token' => 'basura', 'client_id' => $t['client_id']])['status'], 'RFC 7009');
        $this->assertSame(401, $this->http->request('POST', '/oauth/revoke', ['token' => 'x', 'client_id' => 'desconocido'])['status']);
    }

    public function testSuspendingUserRevokesAccessImmediately(): void
    {
        $t = $this->login('editor@example.com');
        $this->assertSame(200, $this->probe($t['access_token']));
        $this->app->userRepository()->setStatus($this->userId('editor@example.com'), 'suspended');
        $this->assertSame(401, $this->probe($t['access_token']));
    }

    public function testRevokingTheUsersOAuthAuthorizations(): void
    {
        $t = $this->login('editor@example.com');
        $this->app->userRepository()->revokeOAuthAuthorizations($this->userId('editor@example.com'));
        $this->assertSame(401, $this->probe($t['access_token']));
        $this->assertSame(400, $this->http->request('POST', '/oauth/token', ['grant_type' => 'refresh_token', 'refresh_token' => $t['refresh_token'], 'client_id' => $t['client_id']])['status']);
        $r = $this->authorize('editor@example.com');
        $this->assertSame(403, $r['denied']['status'], 'la identidad revocada no puede volver a entrar sin intervencion del administrador');
    }

    public function testRevokingAClientKillsItsTokens(): void
    {
        $t = $this->login();
        $this->assertTrue($this->app->authorizationServerFactory()->clients()->revoke($t['client_id']));
        $this->assertSame(401, $this->probe($t['access_token']));
        $this->assertContains($this->http->request('POST', '/oauth/token', ['grant_type' => 'refresh_token', 'refresh_token' => $t['refresh_token'], 'client_id' => $t['client_id']])['status'], [400, 401]);
    }

    public function testCallbackRejectsForgedState(): void
    {
        $cid = $this->register()['client_id'];
        $pk = $this->pkce();
        $this->http->request('GET', '/oauth/authorize?' . http_build_query([
            'response_type' => 'code', 'client_id' => $cid, 'redirect_uri' => self::REDIRECT, 'state' => 's', 'code_challenge' => $pk['challenge'], 'code_challenge_method' => 'S256',
        ]));
        $r = $this->http->request('GET', '/oauth/callback?state=forjado&code=' . rawurlencode('dev:' . base64_encode('admin@example.com')));
        $this->assertSame(400, $r['status']);
        $this->assertSame(400, (new TestHttpClient($this->base))->request('GET', '/oauth/callback?state=x&code=y')['status'], 'sin sesion tampoco');
    }

    public function testConsentRequiresCsrfToken(): void
    {
        $cid = $this->register()['client_id'];
        $pk = $this->pkce();
        $a = $this->http->request('GET', '/oauth/authorize?' . http_build_query([
            'response_type' => 'code', 'client_id' => $cid, 'redirect_uri' => self::REDIRECT, 'state' => 's', 'code_challenge' => $pk['challenge'], 'code_challenge_method' => 'S256',
        ]));
        parse_str((string) parse_url($a['headers']['location'], PHP_URL_QUERY), $loc);
        $login = $this->http->request('POST', '/oauth/dev-login', ['state' => $loc['state'], 'email' => 'admin@example.com']);
        $this->http->request('GET', $login['headers']['location']);
        $r = $this->http->request('POST', '/oauth/consent', ['csrf' => 'falso', 'decision' => 'approve']);
        $this->assertSame(400, $r['status']);
        $this->assertSame(0, (int) $this->app->connection()->run('SELECT COUNT(*) FROM oauth_auth_codes')->fetchColumn());
    }

    public function testSecurityEventsAreRecordedWithoutLeakingTokens(): void
    {
        $t = $this->login();
        $this->http->request('POST', '/oauth/revoke', ['token' => $t['access_token'], 'client_id' => $t['client_id']]);
        $all = json_encode($this->events());
        foreach (['oauth.client_registered', 'oauth.identity_linked', 'oauth.authorization_granted', 'oauth.token_issued', 'oauth.token_revoked'] as $type) {
            $this->assertNotEmpty($this->events($type), $type);
        }
        foreach ([$t['access_token'], $t['refresh_token'], $t['code'], $t['verifier']] as $secret) {
            $this->assertStringNotContainsString($secret, $all);
        }
    }

    public function testDcrRateLimit(): void
    {
        $this->app->connection()->run("INSERT INTO oauth_clients (id, name, redirect_uris) SELECT CONCAT('seed', n), 'x', '[]' FROM (SELECT 1 n UNION SELECT 2 UNION SELECT 3) t");
        $server = new HttpServerProcess(self::dbEnv() + ['APP_KEY' => self::APP_KEY, 'OAUTH_DCR_MAX_PER_HOUR' => '3']);
        try {
            $c = new TestHttpClient($server->url);
            $r = $c->request('POST', '/oauth/register', json_encode(['redirect_uris' => ['https://a.example.com/cb']]), ['Content-Type' => 'application/json']);
            $this->assertSame(429, $r['status']);
        } finally {
            $server->stop();
        }
    }
}
