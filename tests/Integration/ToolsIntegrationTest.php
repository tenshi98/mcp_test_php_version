<?php

declare(strict_types=1);

namespace McpPhp\Tests\Integration;

use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use McpPhp\Core\Application;
use McpPhp\Mcp\ToolInvoker;
use McpPhp\Tests\Support\DatabaseTestCase;

/** Herramientas MCP con servicios y BD reales, pasando por autenticacion y autorizacion. */
final class ToolsIntegrationTest extends DatabaseTestCase
{
    /** @param list<string>|null $scopes */
    private function call(string $email, string $tool, array $args, ?array $scopes = null, ?Application $app = null): CallToolResult
    {
        $app ??= $this->app;
        $t = $app->tokenService()->issue($this->userId($email), 'test', 3600, $scopes);
        $invoker = new ToolInvoker($app->stdioPrincipalResolver($t['token']), $app->authorization(), $app->logger());

        return $invoker->invoke($app->toolRegistry()->get($tool), $args, null);
    }

    /** @return array<string, mixed> */
    private function data(CallToolResult $r): array
    {
        $c = $r->content[0];
        $this->assertInstanceOf(TextContent::class, $c);

        return json_decode($c->text, true);
    }

    public function testAllSixToolsAreRegistered(): void
    {
        $this->assertEqualsCanonicalizing(
            ['list_records', 'get_record', 'search_records', 'create_record', 'update_record', 'delete_record'],
            $this->app->toolRegistry()->names(),
        );
    }

    public function testViewerCanReadButNotWrite(): void
    {
        $this->assertFalse($this->call('viewer@example.com', 'list_records', ['per_page' => 3])->isError);
        foreach ([
            ['create_record', ['first_name' => 'a', 'last_name' => 'b', 'email' => 'a@b.cl', 'title' => 't', 'category' => 'c']],
            ['update_record', ['id' => 1, 'title' => 'x']],
            ['delete_record', ['id' => 1, 'confirm' => true]],
        ] as [$tool, $args]) {
            $r = $this->call('viewer@example.com', $tool, $args);
            $this->assertTrue($r->isError, $tool);
            $this->assertSame('forbidden', $this->data($r)['error']['code'], $tool);
        }
        $this->assertSame(30, $this->app->recordService()->list([])['pagination']['total']);
        $this->assertSame('Camila', $this->app->recordService()->get(1)['first_name']);
    }

    public function testEditorCanWriteButNotDelete(): void
    {
        $r = $this->call('editor@example.com', 'create_record', ['first_name' => 'a', 'last_name' => 'b', 'email' => 'e@b.cl', 'title' => 't', 'category' => 'c']);
        $this->assertFalse($r->isError);
        $this->assertSame(31, $this->data($r)['record']['id']);
        $this->assertFalse($this->call('editor@example.com', 'update_record', ['id' => 31, 'title' => 'nuevo'])->isError);
        $d = $this->call('editor@example.com', 'delete_record', ['id' => 31, 'confirm' => true]);
        $this->assertTrue($d->isError);
        $this->assertSame('forbidden', $this->data($d)['error']['code']);
        $this->assertSame('nuevo', $this->app->recordService()->get(31)['title']);
    }

    public function testAdminDeleteRequiresConfirmation(): void
    {
        $preview = $this->data($this->call('admin@example.com', 'delete_record', ['id' => 3]));
        $this->assertFalse($preview['deleted']);
        $this->assertTrue($preview['confirmation_required']);
        $this->assertSame(30, $this->app->recordService()->list([])['pagination']['total']);

        $done = $this->data($this->call('admin@example.com', 'delete_record', ['id' => 3, 'confirm' => true]));
        $this->assertTrue($done['deleted']);
        $this->assertSame(29, $this->app->recordService()->list([])['pagination']['total']);
    }

    public function testTokenScopesRestrictAnAdmin(): void
    {
        $r = $this->call('admin@example.com', 'delete_record', ['id' => 3, 'confirm' => true], ['records:read']);
        $this->assertTrue($r->isError);
        $this->assertSame('forbidden', $this->data($r)['error']['code']);
        $this->assertFalse($this->call('admin@example.com', 'get_record', ['id' => 3], ['records:read'])->isError);
    }

    public function testValidationErrorsAreStructured(): void
    {
        $r = $this->call('admin@example.com', 'create_record', ['first_name' => 'a', 'last_name' => 'b', 'email' => 'a@b.cl', 'title' => 't', 'category' => 'c', 'birth_date' => '2025-02-30']);
        $this->assertTrue($r->isError);
        $d = $this->data($r)['error'];
        $this->assertSame('validation_error', $d['code']);
        $this->assertArrayHasKey('birth_date', $d['details']['errors']);
    }

    public function testNotFoundAndDuplicateErrors(): void
    {
        $this->assertSame('not_found', $this->data($this->call('admin@example.com', 'get_record', ['id' => 9999]))['error']['code']);
        $r = $this->call('admin@example.com', 'create_record', ['first_name' => 'a', 'last_name' => 'b', 'email' => 'camila.rojas@example.test', 'title' => 't', 'category' => 'c']);
        $this->assertSame('conflict', $this->data($r)['error']['code']);
    }

    public function testRevokedTokenCannotRunTools(): void
    {
        $t = $this->app->tokenService()->issue($this->userId('admin@example.com'), 'x', 3600);
        $invoker = new ToolInvoker($this->app->stdioPrincipalResolver($t['token']), $this->app->authorization(), $this->app->logger());
        $tool = $this->app->toolRegistry()->get('list_records');
        $this->assertFalse($invoker->invoke($tool, [], null)->isError);
        $this->app->tokenService()->revoke($t['id']);
        $r = $invoker->invoke($tool, [], null);
        $this->assertTrue($r->isError, 'la revocacion surte efecto en la siguiente llamada');
        $this->assertSame('authentication_failed', $this->data($r)['error']['code']);
    }

    public function testExpiredTokenCannotRunTools(): void
    {
        $t = $this->app->tokenService()->issue($this->userId('admin@example.com'), 'x', 3600);
        $invoker = new ToolInvoker($this->app->stdioPrincipalResolver($t['token']), $this->app->authorization(), $this->app->logger());
        $this->clock->advance('+3601 seconds');
        $this->assertSame('authentication_failed', $this->data($invoker->invoke($this->app->toolRegistry()->get('list_records'), [], null))['error']['code']);
    }

    public function testSuspendedUserCannotRunTools(): void
    {
        $t = $this->app->tokenService()->issue($this->userId('admin@example.com'), 'x', 3600);
        $invoker = new ToolInvoker($this->app->stdioPrincipalResolver($t['token']), $this->app->authorization(), $this->app->logger());
        $this->app->userRepository()->setStatus($this->userId('admin@example.com'), 'suspended');
        $this->assertTrue($invoker->invoke($this->app->toolRegistry()->get('list_records'), [], null)->isError);
    }

    public function testDatabaseFailureDoesNotLeakConnectionDetails(): void
    {
        $t = $this->app->tokenService()->issue($this->userId('admin@example.com'), 'x', 3600);

        // Mismo usuario/tabla de tokens, pero el servicio de registros apunta a una BD inexistente.
        $broken = $this->makeApp(['DB_HOST' => '127.0.0.1', 'DB_PORT' => '1', 'DB_NAME' => 'no_existe', 'DB_USER' => 'usuario_secreto', 'DB_PASSWORD' => 'clave_super_secreta']);
        $broken->set('clock', $this->clock);
        $principals = $this->app->stdioPrincipalResolver($t['token']); // autentica contra la BD buena
        $invoker = new ToolInvoker($principals, $this->app->authorization(), $this->app->logger());
        $r = $invoker->invoke($broken->toolRegistry()->get('list_records'), [], null);

        $this->assertTrue($r->isError);
        $json = json_encode($r->content[0]);
        $this->assertSame('database_error', $this->data($r)['error']['code']);
        foreach (['clave_super_secreta', 'usuario_secreto', '127.0.0.1', 'SQLSTATE', 'no_existe', 'PDOException'] as $leak) {
            $this->assertStringNotContainsString($leak, $json, "no debe filtrar '$leak'");
        }
    }
}
