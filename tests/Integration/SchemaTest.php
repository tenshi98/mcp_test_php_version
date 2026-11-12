<?php

declare(strict_types=1);

namespace McpPhp\Tests\Integration;

use McpPhp\Exceptions\DatabaseException;
use McpPhp\Tests\Support\DatabaseTestCase;

final class SchemaTest extends DatabaseTestCase
{
    public function testAllTablesAreCreated(): void
    {
        $tables = $this->app->connection()->pdo()->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
        foreach (['allowed_users', 'tokens', 'oauth_users', 'oauth_clients', 'oauth_auth_codes', 'oauth_access_tokens', 'oauth_refresh_tokens', 'security_events', 'sample_records'] as $t) {
            $this->assertContains($t, $tables);
        }
    }

    public function testThirtySampleRecordsWithVariedData(): void
    {
        $db = $this->app->connection();
        $this->assertSame(30, (int) $db->run('SELECT COUNT(*) FROM sample_records')->fetchColumn());
        $this->assertGreaterThan(3, (int) $db->run('SELECT COUNT(DISTINCT category) FROM sample_records')->fetchColumn());
        $this->assertSame(3, (int) $db->run('SELECT COUNT(DISTINCT status) FROM sample_records')->fetchColumn());
        foreach (['birth_date', 'description', 'price', 'notes', 'rating'] as $col) {
            $this->assertGreaterThan(0, (int) $db->run("SELECT COUNT(*) FROM sample_records WHERE $col IS NULL")->fetchColumn(), "$col debe tener NULLs");
            $this->assertGreaterThan(0, (int) $db->run("SELECT COUNT(*) FROM sample_records WHERE $col IS NOT NULL")->fetchColumn(), "$col debe tener valores");
        }
        $this->assertGreaterThan(0, (int) $db->run('SELECT COUNT(*) FROM sample_records WHERE is_active = 0')->fetchColumn());
        $this->assertGreaterThan(200, (int) $db->run('SELECT MAX(CHAR_LENGTH(description)) FROM sample_records')->fetchColumn(), 'hay descripciones extensas');
    }

    public function testSeedUsersExist(): void
    {
        $this->assertCount(4, $this->app->userRepository()->all());
    }

    public function testForeignKeysAreEnforced(): void
    {
        $this->expectException(DatabaseException::class);
        $this->app->connection()->run("INSERT INTO tokens (user_id, token_hash, expires_at) VALUES (9999, :h, '2030-01-01')", ['h' => str_repeat('a', 64)]);
    }

    public function testUserEmailIsUnique(): void
    {
        $this->expectException(\McpPhp\Exceptions\ConflictException::class);
        $this->app->userRepository()->create('Otro', 'ADMIN@example.com', 'viewer');
    }

    public function testDeletingUserCascadesToTokensAndIdentities(): void
    {
        $uid = $this->userId('viewer@example.com');
        $this->app->tokenService()->issue($uid, 'x', 3600);
        $this->app->userRepository()->linkOAuthIdentity('dev', 'sub1', $uid, 'viewer@example.com', 'V');
        $db = $this->app->connection();
        $db->run('DELETE FROM allowed_users WHERE id = :id', ['id' => $uid]);
        $this->assertSame(0, (int) $db->run('SELECT COUNT(*) FROM tokens WHERE user_id = :u', ['u' => $uid])->fetchColumn());
        $this->assertSame(0, (int) $db->run('SELECT COUNT(*) FROM oauth_users WHERE user_id = :u', ['u' => $uid])->fetchColumn());
    }

    public function testInstallIsRepeatableWithFresh(): void
    {
        $installer = $this->app->schemaInstaller();
        $installer->dropAll();
        $this->assertCount(5, $installer->install());
        $installer->dropAll();
        $this->assertCount(3, $installer->install(false), 'sin seeds solo tablas, indices y relaciones');
        $this->assertSame(0, (int) $this->app->connection()->run('SELECT COUNT(*) FROM sample_records')->fetchColumn());
    }
}
