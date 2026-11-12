<?php

declare(strict_types=1);

namespace McpPhp\Tests\Unit;

use Mcp\Schema\Content\TextContent;
use Mcp\Server\Authorization\AccessToken;
use McpPhp\Authentication\PrincipalResolverInterface;
use McpPhp\Authorization\AuthorizationService;
use McpPhp\Authorization\Permission;
use McpPhp\Authorization\Principal;
use McpPhp\Authorization\Role;
use McpPhp\Exceptions\AuthenticationException;
use McpPhp\Exceptions\DatabaseException;
use McpPhp\Exceptions\NotFoundException;
use McpPhp\Exceptions\ValidationException;
use McpPhp\Logging\SecurityLogger;
use McpPhp\Mcp\ToolInterface;
use McpPhp\Mcp\ToolInvoker;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ToolInvokerTest extends TestCase
{
    private function invoker(?Role $role, ?\Closure $before = null): ToolInvoker
    {
        $resolver = new class ($role) implements PrincipalResolverInterface {
            public function __construct(private readonly ?Role $role)
            {
            }

            public function resolve(?AccessToken $accessToken): Principal
            {
                if ($this->role === null) {
                    throw new AuthenticationException('Token invalido, expirado o revocado.');
                }

                return new Principal(1, 'a@b.c', 'A', $this->role, Permission::allScopes(), 'test');
            }
        };

        return new ToolInvoker($resolver, new AuthorizationService(new SecurityLogger(new NullLogger())), new NullLogger());
    }

    private function tool(\Closure $run, Permission $perm = Permission::RecordsRead): ToolInterface
    {
        return new class ($run, $perm) implements ToolInterface {
            public function __construct(private readonly \Closure $run, private readonly Permission $perm)
            {
            }

            public function name(): string
            {
                return 'fake_tool';
            }

            public function title(): string
            {
                return 'Fake';
            }

            public function description(): string
            {
                return 'Fake';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object'];
            }

            public function requiredPermission(): Permission
            {
                return $this->perm;
            }

            public function annotations(): array
            {
                return [];
            }

            public function execute(array $arguments, Principal $principal): array
            {
                return ($this->run)($arguments, $principal);
            }
        };
    }

    /** @return array<string, mixed> */
    private function payload(\Mcp\Schema\Result\CallToolResult $r): array
    {
        $c = $r->content[0];
        $this->assertInstanceOf(TextContent::class, $c);

        return json_decode($c->text, true);
    }

    public function testSuccessReturnsStructuredContent(): void
    {
        $r = $this->invoker(Role::Viewer)->invoke($this->tool(static fn ($a) => ['echo' => $a['x']]), ['x' => 5], null);
        $this->assertFalse($r->isError);
        $this->assertSame(['echo' => 5], $r->structuredContent);
        $this->assertSame(['echo' => 5], $this->payload($r));
    }

    public function testUnauthenticatedIsErrorResult(): void
    {
        $r = $this->invoker(null)->invoke($this->tool(static fn () => ['ok' => true]), [], null);
        $this->assertTrue($r->isError);
        $this->assertSame('authentication_failed', $this->payload($r)['error']['code']);
    }

    public function testMissingPermissionIsForbiddenAndToolNotExecuted(): void
    {
        $executed = false;
        $tool = $this->tool(static function () use (&$executed) {
            $executed = true;

            return ['x' => 1];
        }, Permission::RecordsDelete);
        $r = $this->invoker(Role::Editor)->invoke($tool, [], null);
        $this->assertTrue($r->isError);
        $this->assertSame('forbidden', $this->payload($r)['error']['code']);
        $this->assertFalse($executed);
    }

    public function testValidationErrorsExposeFieldDetails(): void
    {
        $r = $this->invoker(Role::Admin)->invoke($this->tool(static fn () => throw new ValidationException(['email' => 'invalido'])), [], null);
        $this->assertTrue($r->isError);
        $p = $this->payload($r);
        $this->assertSame('validation_error', $p['error']['code']);
        $this->assertSame(['email' => 'invalido'], $p['error']['details']['errors']);
    }

    public function testNotFoundMapping(): void
    {
        $r = $this->invoker(Role::Admin)->invoke($this->tool(static fn () => throw new NotFoundException('No existe')), [], null);
        $this->assertSame('not_found', $this->payload($r)['error']['code']);
    }

    public function testDatabaseErrorsDoNotLeakInternals(): void
    {
        $secret = "SQLSTATE[HY000] [1045] Access denied for user 'root'@'10.0.0.5' password: hunter2";
        $r = $this->invoker(Role::Admin)->invoke(
            $this->tool(static fn () => throw new DatabaseException('Error al ejecutar la operacion en la base de datos.', 0, new \PDOException($secret))),
            [],
            null,
        );
        $json = json_encode($r->content[0]);
        $this->assertTrue($r->isError);
        $this->assertSame('database_error', $this->payload($r)['error']['code']);
        $this->assertStringNotContainsString('hunter2', $json);
        $this->assertStringNotContainsString('10.0.0.5', $json);
        $this->assertStringNotContainsString('SQLSTATE', $json);
    }

    public function testUnexpectedExceptionsAreMasked(): void
    {
        $r = $this->invoker(Role::Admin)->invoke($this->tool(static fn () => throw new \RuntimeException('/var/www/secret/path.php line 12')), [], null);
        $this->assertTrue($r->isError);
        $this->assertSame('internal_error', $this->payload($r)['error']['code']);
        $this->assertStringNotContainsString('/var/www', json_encode($r->content[0]));
    }
}
