<?php

declare(strict_types=1);

namespace McpPhp\Tests\Unit;

use McpPhp\Authentication\PrincipalFactory;
use McpPhp\Authorization\AuthorizationService;
use McpPhp\Authorization\Permission;
use McpPhp\Authorization\Principal;
use McpPhp\Authorization\Role;
use McpPhp\Exceptions\AuthenticationException;
use McpPhp\Exceptions\AuthorizationException;
use McpPhp\Logging\SecurityLogger;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class AuthorizationTest extends TestCase
{
    private function principal(Role $role, array $scopes): Principal
    {
        return new Principal(1, 'a@b.c', 'A', $role, $scopes, 'test');
    }

    public function testRolesGrantExpectedPermissions(): void
    {
        $all = Permission::allScopes();
        $this->assertTrue($this->principal(Role::Admin, $all)->can(Permission::RecordsDelete));
        $this->assertTrue($this->principal(Role::Editor, $all)->can(Permission::RecordsWrite));
        $this->assertFalse($this->principal(Role::Editor, $all)->can(Permission::RecordsDelete));
        $this->assertFalse($this->principal(Role::Viewer, $all)->can(Permission::RecordsWrite));
        $this->assertTrue($this->principal(Role::Viewer, $all)->can(Permission::RecordsRead));
    }

    public function testScopesNarrowTheRolePermissions(): void
    {
        $p = $this->principal(Role::Admin, ['records:read']);
        $this->assertTrue($p->can(Permission::RecordsRead));
        $this->assertFalse($p->can(Permission::RecordsDelete), 'un admin con un token de solo lectura no puede borrar');
        $this->assertSame(['records:read'], $p->effectiveScopes());
    }

    public function testScopesCannotEscalateTheRole(): void
    {
        $p = $this->principal(Role::Viewer, Permission::allScopes());
        $this->assertSame(['records:read'], $p->effectiveScopes());
    }

    public function testServiceThrowsWhenPermissionMissing(): void
    {
        $svc = new AuthorizationService(new SecurityLogger(new NullLogger()));
        $svc->assertCan($this->principal(Role::Admin, Permission::allScopes()), Permission::RecordsDelete, 'delete_record');
        $this->expectException(AuthorizationException::class);
        $svc->assertCan($this->principal(Role::Viewer, Permission::allScopes()), Permission::RecordsDelete, 'delete_record');
    }

    public function testPrincipalFactoryRejectsInactiveUsers(): void
    {
        foreach (['suspended', 'disabled'] as $status) {
            try {
                PrincipalFactory::fromUser(['id' => 1, 'email' => 'a@b.c', 'name' => 'A', 'role' => 'admin', 'status' => $status], [], 'x');
                $this->fail("debio rechazar $status");
            } catch (AuthenticationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testPrincipalFactoryRejectsUnknownRole(): void
    {
        $this->expectException(AuthenticationException::class);
        PrincipalFactory::fromUser(['id' => 1, 'email' => 'a@b.c', 'name' => 'A', 'role' => 'root', 'status' => 'active'], [], 'x');
    }
}
