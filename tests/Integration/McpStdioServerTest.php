<?php

declare(strict_types=1);

namespace McpPhp\Tests\Integration;

use McpPhp\Tests\Support\DatabaseTestCase;
use McpPhp\Tests\Support\McpStdioProcess;

/** Lanza bin/mcp-server como lo haria Claude Desktop y habla el protocolo real por STDIO. */
final class McpStdioServerTest extends DatabaseTestCase
{
    /** @return array<string, string> */
    private function env(?string $token): array
    {
        return self::dbEnv() + ['APP_KEY' => self::APP_KEY, 'APP_ENV' => 'testing'] + ($token !== null ? ['MCP_ACCESS_TOKEN' => $token] : []);
    }

    private function token(string $email = 'admin@example.com'): string
    {
        return $this->app->tokenService()->issue($this->userId($email), 'stdio', 3600)['token'];
    }

    /** @return array<string, mixed> */
    private function payload(array $response): array
    {
        return json_decode($response['result']['content'][0]['text'], true);
    }

    public function testInitializeNegotiatesCapabilitiesAndAdvertisesTools(): void
    {
        $p = new McpStdioProcess($this->env($this->token()));
        $init = $p->initialize();
        $this->assertSame('2025-06-18', $init['result']['protocolVersion']);
        $this->assertArrayHasKey('tools', $init['result']['capabilities']);
        $this->assertSame('mcp-php-crud-server', $init['result']['serverInfo']['name']);

        $list = $p->request('tools/list');
        $names = array_column($list['result']['tools'], 'name');
        $this->assertEqualsCanonicalizing(['list_records', 'get_record', 'search_records', 'create_record', 'update_record', 'delete_record'], $names);
        foreach ($list['result']['tools'] as $tool) {
            $this->assertSame('object', $tool['inputSchema']['type']);
            $this->assertNotEmpty($tool['description']);
            $this->assertArrayHasKey('annotations', $tool);
        }
        $this->assertSame([], $p->invalidStdoutLines, 'stdout solo debe contener mensajes JSON-RPC');
        $this->assertSame(0, $p->close()['exit']);
    }

    public function testFullCrudCycleOverStdio(): void
    {
        $p = new McpStdioProcess($this->env($this->token()));
        $p->initialize();

        $created = $this->payload($p->callTool('create_record', ['first_name' => 'Mcp', 'last_name' => 'Stdio', 'email' => 'mcp.stdio@example.test', 'title' => 'E2E', 'category' => 'pruebas', 'price' => 5.5]));
        $id = $created['record']['id'];
        $this->assertSame(31, $id);

        $this->assertSame('E2E', $this->payload($p->callTool('get_record', ['id' => $id, 'fields' => ['title']]))['record']['title']);
        $this->assertSame(1, $this->payload($p->callTool('search_records', ['text' => 'mcp.stdio']))['pagination']['total']);
        $this->assertSame(31, $this->payload($p->callTool('list_records', ['per_page' => 5]))['pagination']['total']);
        $this->assertSame(9.0, $this->payload($p->callTool('update_record', ['id' => $id, 'price' => 9]))['record']['price']);

        $this->assertFalse($this->payload($p->callTool('delete_record', ['id' => $id]))['deleted']);
        $this->assertTrue($this->payload($p->callTool('delete_record', ['id' => $id, 'confirm' => true]))['deleted']);
        $gone = $p->callTool('get_record', ['id' => $id]);
        $this->assertTrue($gone['result']['isError']);
        $this->assertSame('not_found', $this->payload($gone)['error']['code']);
        $this->assertSame([], $p->invalidStdoutLines);
    }

    public function testProtocolLevelParameterValidation(): void
    {
        $p = new McpStdioProcess($this->env($this->token()));
        $p->initialize();
        foreach ([
            ['get_record', ['id' => 'abc']],
            ['get_record', []],
            ['list_records', ['per_page' => 1000]],
            ['list_records', ['sort_by' => 'id; DROP TABLE x']],
            ['create_record', ['first_name' => 'x']],
            ['search_records', ['status' => 'borrado']],
            ['list_records', ['desconocido' => 1]],
        ] as [$tool, $args]) {
            $r = $p->callTool($tool, $args);
            $this->assertSame(-32602, $r['error']['code'] ?? null, "$tool " . json_encode($args));
        }
        $unknown = $p->callTool('no_existe', []);
        $this->assertArrayHasKey('error', $unknown);
        $this->assertSame(30, (int) $this->app->connection()->run('SELECT COUNT(*) FROM sample_records')->fetchColumn());
    }

    public function testSqlInjectionOverTheWire(): void
    {
        $p = new McpStdioProcess($this->env($this->token()));
        $p->initialize();
        $r = $p->callTool('search_records', ['text' => "'; DROP TABLE sample_records; --", 'name' => "' OR '1'='1"]);
        $this->assertSame(0, $this->payload($r)['pagination']['total']);
        $this->assertSame(30, $this->payload($p->callTool('list_records', []))['pagination']['total']);
    }

    public function testViewerTokenCannotDeleteOverStdio(): void
    {
        $p = new McpStdioProcess($this->env($this->token('viewer@example.com')));
        $p->initialize();
        $r = $p->callTool('delete_record', ['id' => 1, 'confirm' => true]);
        $this->assertTrue($r['result']['isError']);
        $this->assertSame('forbidden', $this->payload($r)['error']['code']);
        $this->assertSame(30, (int) $this->app->connection()->run('SELECT COUNT(*) FROM sample_records')->fetchColumn());
    }

    public function testRevocationTakesEffectWhileServerIsRunning(): void
    {
        $issued = $this->app->tokenService()->issue($this->userId('admin@example.com'), 'x', 3600);
        $p = new McpStdioProcess($this->env($issued['token']));
        $p->initialize();
        $this->assertFalse($p->callTool('list_records', [])['result']['isError']);
        $this->app->tokenService()->revoke($issued['id']);
        $r = $p->callTool('list_records', []);
        $this->assertTrue($r['result']['isError']);
        $this->assertSame('authentication_failed', $this->payload($r)['error']['code']);
    }

    public function testExpiredTokenWhileRunning(): void
    {
        $issued = $this->app->tokenService()->issue($this->userId('admin@example.com'), 'x', 3600);
        $p = new McpStdioProcess($this->env($issued['token']));
        $p->initialize();
        $this->app->connection()->run('UPDATE tokens SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE id = :id', ['id' => $issued['id']]);
        $this->assertSame('authentication_failed', $this->payload($p->callTool('list_records', []))['error']['code']);
    }

    public function testStartupFailsWithoutToken(): void
    {
        $p = new McpStdioProcess($this->env(null));
        $r = $p->close();
        $this->assertSame(1, $r['exit']);
        $this->assertStringContainsString('MCP_ACCESS_TOKEN', $r['stderr']);
    }

    public function testStartupFailsWithInvalidOrRevokedOrExpiredToken(): void
    {
        $revoked = $this->app->tokenService()->issue($this->userId('admin@example.com'), 'x', 3600);
        $this->app->tokenService()->revoke($revoked['id']);
        $expired = $this->app->tokenService()->issue($this->userId('admin@example.com'), 'y', 3600);
        $this->app->connection()->run('UPDATE tokens SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE id = :id', ['id' => $expired['id']]);

        foreach (['basura', 'mcp_' . str_repeat('A', 43), $revoked['token'], $expired['token']] as $bad) {
            $p = new McpStdioProcess($this->env($bad));
            $r = $p->close();
            $this->assertSame(1, $r['exit'], $bad);
            $this->assertStringNotContainsString($bad, $r['stderr'], 'el token no se escribe en los mensajes de error');
            $this->assertSame([], $p->invalidStdoutLines);
        }
    }

    public function testStartupFailsWithSuspendedUser(): void
    {
        $t = $this->token('editor@example.com');
        $this->app->userRepository()->setStatus($this->userId('editor@example.com'), 'suspended');
        $r = (new McpStdioProcess($this->env($t)))->close();
        $this->assertSame(1, $r['exit']);
    }

    public function testDatabaseOutageAtStartupDoesNotLeakCredentials(): void
    {
        $t = $this->token();
        $env = $this->env($t);
        $env['DB_HOST'] = '127.0.0.1';
        $env['DB_PORT'] = '1';
        $env['DB_PASSWORD'] = 'clave_super_secreta';
        $r = (new McpStdioProcess($env))->close();
        $this->assertSame(1, $r['exit']);
        $this->assertStringNotContainsString('clave_super_secreta', $r['stderr']);
        $this->assertStringNotContainsString('SQLSTATE', $r['stderr']);
    }
}
