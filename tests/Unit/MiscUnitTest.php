<?php

declare(strict_types=1);

namespace McpPhp\Tests\Unit;

use Mcp\Server\Transport\Http\OAuth\AuthorizationResult;
use Mcp\Server\Transport\Http\OAuth\AuthorizationTokenValidatorInterface;
use McpPhp\Core\Config;
use McpPhp\Database\SchemaInstaller;
use McpPhp\Http\CompositeTokenValidator;
use McpPhp\Logging\SecurityLogger;
use McpPhp\OAuth\Identity\DevIdentityProvider;
use McpPhp\OAuth\OAuthConfig;
use McpPhp\Repositories\PdoRecordRepository;
use McpPhp\Tests\Support\FrozenClock;
use McpPhp\Tests\Support\InMemoryRepositories;
use McpPhp\Tokens\TokenGenerator;
use McpPhp\Tokens\TokenService;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

final class MiscUnitTest extends TestCase
{
    public function testSqlScriptSplitterHandlesCommentsAndQuotes(): void
    {
        $sql = "-- comentario; con punto y coma\nCREATE TABLE a (x INT);\nINSERT INTO a VALUES ('uno;dos', \"t;x\");\n-- fin\nSELECT 1";
        $this->assertSame(
            ['CREATE TABLE a (x INT)', "INSERT INTO a VALUES ('uno;dos', \"t;x\")", 'SELECT 1'],
            SchemaInstaller::splitStatements($sql),
        );
    }

    public function testLikeEscapingNeutralizesWildcards(): void
    {
        $this->assertSame('100\\%\\_\\\\', PdoRecordRepository::escapeLike('100%_\\'));
    }

    public function testConfigTypedGetters(): void
    {
        $c = Config::fromArray(['A' => '5', 'B' => 'true', 'C' => 'x, y ,z', 'D' => '', 'APP_ENV' => 'production'], '/base');
        $this->assertSame(5, $c->int('A', 0));
        $this->assertTrue($c->bool('B'));
        $this->assertSame(['x', 'y', 'z'], $c->list('C'));
        $this->assertNull($c->get('D'));
        $this->assertSame('/base/storage/x', $c->path('NOPE', 'storage/x'));
        $this->assertSame('/abs', $c->path('NOPE', '/abs'));
        $this->assertTrue($c->isProduction());
        $this->expectException(\McpPhp\Exceptions\ConfigurationException::class);
        $c->require('MISSING');
    }

    public function testOAuthConfigRequiresHttpsInProduction(): void
    {
        $base = ['OAUTH_ENCRYPTION_KEY' => str_repeat('k', 44), 'APP_URL' => 'http://mcp.example.com'];
        $this->assertSame('http://mcp.example.com/mcp', OAuthConfig::fromConfig(Config::fromArray($base + ['APP_ENV' => 'local']))->resource);
        $this->expectException(\McpPhp\Exceptions\ConfigurationException::class);
        OAuthConfig::fromConfig(Config::fromArray($base + ['APP_ENV' => 'production']));
    }

    public function testOAuthConfigRequiresEncryptionKey(): void
    {
        $this->expectException(\McpPhp\Exceptions\ConfigurationException::class);
        OAuthConfig::fromConfig(Config::fromArray(['APP_ENV' => 'local']));
    }

    public function testDevIdentityProviderIsForbiddenInProduction(): void
    {
        $this->expectException(\McpPhp\Exceptions\ConfigurationException::class);
        new DevIdentityProvider(true, 'http://x/login');
    }

    public function testSecurityLoggerRedactsSecrets(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<array<string, mixed>> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = $context;
            }
        };
        (new SecurityLogger($logger))->warning('x', 1, ['token' => 'mcp_secret', 'nested' => ['password' => 'p', 'ok' => 'v'], 'client_secret' => 's']);
        $ctx = $logger->records[0];
        $this->assertSame('[redacted]', $ctx['token']);
        $this->assertSame('[redacted]', $ctx['nested']['password']);
        $this->assertSame('v', $ctx['nested']['ok']);
        $this->assertSame('[redacted]', $ctx['client_secret']);
    }

    public function testCompositeValidatorRoutesPersonalTokensOnlyWhenEnabled(): void
    {
        $oauth = new class implements AuthorizationTokenValidatorInterface {
            public ?string $seen = null;

            public function validate(string $accessToken): AuthorizationResult
            {
                $this->seen = $accessToken;

                return AuthorizationResult::unauthorized('invalid_token');
            }
        };
        $gen = new TokenGenerator(str_repeat('k', 40));
        $tokens = InMemoryRepositories::tokens();
        $svc = new TokenService($tokens, InMemoryRepositories::users([['id' => 1, 'email' => 'a@b.c', 'name' => 'A', 'role' => 'admin', 'status' => 'active']]), $gen, new SecurityLogger(new \Psr\Log\NullLogger()), new FrozenClock());
        $personal = $svc->issue(1, 't', 3600)['token'];

        // desactivado (por defecto): el token personal va al validador OAuth y se rechaza
        $off = new CompositeTokenValidator($oauth, null);
        $this->assertFalse($off->validate($personal)->isAllowed());
        $this->assertSame($personal, $oauth->seen);

        // activado
        $on = new CompositeTokenValidator($oauth, $svc);
        $r = $on->validate($personal);
        $this->assertTrue($r->isAllowed());
        $this->assertSame('personal_token', $r->getAccessToken()->getClaim('auth_method'));
        $this->assertFalse($on->validate('mcp_' . str_repeat('A', 43))->isAllowed());
    }
}
