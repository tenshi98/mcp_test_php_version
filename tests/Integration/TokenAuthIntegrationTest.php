<?php

declare(strict_types=1);

namespace McpPhp\Tests\Integration;

use McpPhp\Exceptions\AuthenticationException;
use McpPhp\Tests\Support\DatabaseTestCase;

final class TokenAuthIntegrationTest extends DatabaseTestCase
{
    public function testTokenLifecycleAgainstDatabase(): void
    {
        $svc = $this->app->tokenService();
        $uid = $this->userId('editor@example.com');
        $t = $svc->issue($uid, 'laptop', 7200);

        $row = $this->app->connection()->run('SELECT * FROM tokens WHERE id = :id', ['id' => $t['id']])->fetch();
        $this->assertNotSame($t['token'], $row['token_hash'], 'solo se guarda el hash');
        $this->assertStringNotContainsString($t['token'], json_encode($row));
        $this->assertSame('active', $row['status']);

        $p = $svc->authenticate($t['token']);
        $this->assertSame('editor', $p->role->value);
        $this->assertNotNull($this->app->connection()->run('SELECT last_used_at FROM tokens WHERE id = :id', ['id' => $t['id']])->fetchColumn());

        $svc->revoke($t['id']);
        $row = $this->app->connection()->run('SELECT * FROM tokens WHERE id = :id', ['id' => $t['id']])->fetch();
        $this->assertSame('revoked', $row['status']);
        $this->assertNotNull($row['revoked_at']);
        $this->expectException(AuthenticationException::class);
        $svc->authenticate($t['token']);
    }

    public function testExpiredTokenAgainstDatabase(): void
    {
        $svc = $this->app->tokenService();
        $t = $svc->issue($this->userId('admin@example.com'), 'x', 3600);
        $this->clock->advance('+2 hours');
        $this->expectException(AuthenticationException::class);
        $svc->authenticate($t['token']);
    }

    public function testSuspendingUserKillsTokens(): void
    {
        $svc = $this->app->tokenService();
        $uid = $this->userId('editor@example.com');
        $t = $svc->issue($uid, 'x', 3600);
        $this->app->userRepository()->setStatus($uid, 'suspended');
        try {
            $svc->authenticate($t['token']);
            $this->fail('un usuario suspendido no debe autenticarse');
        } catch (AuthenticationException) {
            $this->addToAssertionCount(1);
        }
        $this->app->userRepository()->setStatus($uid, 'active');
        $this->assertSame($uid, $svc->authenticate($t['token'])->userId, 'al reactivar vuelve a funcionar mientras no expire');
    }

    public function testRevokeAllForUserAndListing(): void
    {
        $svc = $this->app->tokenService();
        $uid = $this->userId('admin@example.com');
        $a = $svc->issue($uid, 'a', 3600);
        $b = $svc->issue($uid, 'b', 3600);
        $this->assertSame(2, $svc->revokeAllForUser($uid));
        foreach ($svc->list($uid) as $row) {
            $this->assertSame('revoked', $row['status']);
            $this->assertArrayNotHasKey('token_hash', $row, 'el listado no expone hashes');
        }
        $this->expectException(AuthenticationException::class);
        $svc->authenticate($a['token']);
    }

    public function testPurgeRemovesOldTokensOnly(): void
    {
        $svc = $this->app->tokenService();
        $uid = $this->userId('admin@example.com');
        $old = $svc->issue($uid, 'old', 3600);
        $keep = $svc->issue($uid, 'keep', 3600);
        $svc->revoke($old['id']);
        $db = $this->app->connection();
        $db->run("UPDATE tokens SET revoked_at = UTC_TIMESTAMP() - INTERVAL 60 DAY WHERE id = :id", ['id' => $old['id']]);
        $repo = new \McpPhp\Repositories\TokenRepository($db);
        $this->assertSame(1, $repo->purge(30));
        $this->assertSame($uid, $svc->authenticate($keep['token'])->userId);
    }

    public function testSecurityEventsAreRecordedWithoutSecrets(): void
    {
        $svc = $this->app->tokenService();
        $t = $svc->issue($this->userId('admin@example.com'), 'x', 3600);
        try {
            $svc->authenticate('mcp_' . str_repeat('Z', 43));
        } catch (AuthenticationException) {
        }
        $svc->revoke($t['id']);
        $all = json_encode($this->events());
        $this->assertCount(1, $this->events('token.issued'));
        $this->assertCount(1, $this->events('token.rejected'));
        $this->assertCount(1, $this->events('token.revoked'));
        $this->assertStringNotContainsString($t['token'], $all);
        $this->assertStringNotContainsString(str_repeat('Z', 43), $all);
    }
}
