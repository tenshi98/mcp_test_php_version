<?php

declare(strict_types=1);

namespace McpPhp\Tests\Support;

use McpPhp\Core\Application;
use McpPhp\Core\Config;
use McpPhp\Database\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Base de las pruebas de integracion. Crea (o recrea) una base de datos exclusiva de pruebas
 * y reinstala el esquema + datos de prueba antes de CADA test. Nunca toca la base de desarrollo.
 *
 * Variables: DB_HOST, DB_PORT, TEST_DB_NAME, TEST_DB_ADMIN_USER, TEST_DB_ADMIN_PASSWORD
 */
abstract class DatabaseTestCase extends TestCase
{
    public const APP_KEY = 'integration-test-app-key-0123456789abcdef0123456789';

    protected Application $app;
    protected FrozenClock $clock;
    protected static ?bool $dbAvailable = null;

    /** @return array<string, string> */
    public static function dbEnv(): array
    {
        return [
            'DB_HOST' => getenv('DB_HOST') ?: '127.0.0.1',
            'DB_PORT' => getenv('DB_PORT') ?: '3306',
            'DB_NAME' => getenv('TEST_DB_NAME') ?: 'mcp_server_test',
            'DB_USER' => getenv('TEST_DB_ADMIN_USER') ?: 'root',
            'DB_PASSWORD' => getenv('TEST_DB_ADMIN_PASSWORD') ?: '',
        ];
    }

    protected function setUp(): void
    {
        $env = self::dbEnv();
        if (static::$dbAvailable === null) {
            static::$dbAvailable = false;
            if (extension_loaded('pdo_mysql') && getenv('TEST_DB_ADMIN_USER')) {
                try {
                    $admin = Connection::mysql($env['DB_HOST'], (int) $env['DB_PORT'], 'mysql', $env['DB_USER'], $env['DB_PASSWORD']);
                    $admin->pdo()->exec('CREATE DATABASE IF NOT EXISTS `' . $env['DB_NAME'] . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
                    static::$dbAvailable = true;
                } catch (\Throwable) {
                }
            }
        }
        if (!static::$dbAvailable) {
            $this->markTestSkipped('Base de datos de pruebas no disponible (ver docs/TESTING.md).');
        }

        $this->clock = new FrozenClock(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $this->app = $this->makeApp($env);
        $installer = $this->app->schemaInstaller();
        $installer->dropAll();
        $installer->install(true);
    }

    /** @param array<string, string> $env */
    protected function makeApp(array $env, array $extra = []): Application
    {
        $logDir = sys_get_temp_dir() . '/mcp-php-tests-' . getmypid();
        $config = Config::fromArray($env + $extra + [
            'APP_ENV' => 'testing',
            'APP_KEY' => self::APP_KEY,
            'LOG_PATH' => $logDir,
            'OAUTH_ENCRYPTION_KEY' => base64_encode(str_repeat('e', 32)),
        ], dirname(__DIR__, 2));
        $app = new Application($config);
        $app->set('logger', new NullLogger());
        $app->set('clock', $this->clock);

        return $app;
    }

    /** @return list<array<string, mixed>> */
    protected function events(?string $type = null): array
    {
        $sql = 'SELECT * FROM security_events' . ($type ? ' WHERE event_type = :t' : '') . ' ORDER BY id';

        return $this->app->connection()->run($sql, $type ? ['t' => $type] : [])->fetchAll();
    }

    protected function userId(string $email): int
    {
        return (int) $this->app->userRepository()->findByEmail($email)['id'];
    }
}
