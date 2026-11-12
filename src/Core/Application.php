<?php

declare(strict_types=1);

namespace McpPhp\Core;

use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use McpPhp\Authentication\HttpPrincipalResolver;
use McpPhp\Authentication\PrincipalResolverInterface;
use McpPhp\Authentication\StdioPrincipalResolver;
use McpPhp\Authorization\AuthorizationService;
use McpPhp\Database\Connection;
use McpPhp\Database\SchemaInstaller;
use McpPhp\Domain\EntityDefinition;
use McpPhp\Exceptions\ConfigurationException;
use McpPhp\Logging\SecurityLogger;
use McpPhp\Mcp\ServerFactory;
use McpPhp\OAuth\AccessTokenValidator;
use McpPhp\OAuth\AuthorizationServerFactory;
use McpPhp\OAuth\Identity\DevIdentityProvider;
use McpPhp\OAuth\Identity\GenericOAuthProvider;
use McpPhp\OAuth\Identity\IdentityLinkService;
use McpPhp\OAuth\Identity\IdentityProviderInterface;
use McpPhp\OAuth\OAuthConfig;
use McpPhp\Http\CompositeTokenValidator;
use Mcp\Server\Transport\Http\OAuth\AuthorizationTokenValidatorInterface;
use McpPhp\Mcp\ToolInvoker;
use McpPhp\Mcp\ToolRegistry;
use McpPhp\Repositories\PdoRecordRepository;
use McpPhp\Repositories\RecordRepositoryInterface;
use McpPhp\Repositories\TokenRepository;
use McpPhp\Repositories\UserRepository;
use McpPhp\Services\RecordService;
use McpPhp\Tokens\TokenGenerator;
use McpPhp\Tokens\TokenService;
use McpPhp\Validators\CriteriaFactory;
use McpPhp\Validators\RecordValidator;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Raiz de composicion: crea y comparte (singleton perezoso) los servicios.
 * Es deliberadamente simple (sin framework); los tests pueden inyectar piezas con set().
 */
class Application
{
    /** @var array<string, mixed> */
    private array $instances = [];

    public function __construct(public readonly Config $config, private readonly ?string $clientIp = null)
    {
    }

    public static function boot(string $basePath): self
    {
        return new self(Config::load($basePath), $_SERVER['REMOTE_ADDR'] ?? null);
    }

    public function set(string $id, mixed $instance): void
    {
        $this->instances[$id] = $instance;
    }

    /** @template T @param callable(): T $factory @return T */
    private function share(string $id, callable $factory): mixed
    {
        return $this->instances[$id] ??= $factory();
    }

    public function clock(): ClockInterface
    {
        return $this->share('clock', fn () => new SystemClock());
    }

    public function connection(): Connection
    {
        return $this->share('db', fn () => Connection::mysql(
            $this->config->get('DB_HOST', '127.0.0.1'),
            $this->config->int('DB_PORT', 3306),
            $this->config->require('DB_NAME'),
            $this->config->require('DB_USER'),
            $this->config->get('DB_PASSWORD', ''),
        ));
    }

    public function logger(): LoggerInterface
    {
        return $this->share('logger', fn () => $this->makeLogger('app', 'app.log'));
    }

    private function makeLogger(string $channel, string $file): LoggerInterface
    {
        $dir = $this->config->path('LOG_PATH', 'storage/logs');
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        $level = Level::fromName(ucfirst(strtolower($this->config->get('LOG_LEVEL', 'info'))));
        $handler = new StreamHandler($dir . '/' . $file, $level, true, 0640);
        $handler->setFormatter(new LineFormatter("[%datetime%] %channel%.%level_name%: %message% %context%\n", 'Y-m-d H:i:s', false, true));

        // IMPORTANTE: nunca escribir en stdout (en STDIO es el canal del protocolo).
        return new Logger($channel, [$handler]);
    }

    public function securityLogger(): SecurityLogger
    {
        return $this->share('security', fn () => new SecurityLogger(
            $this->makeLogger('security', 'security.log'),
            $this->connection(),
            $this->clientIp,
        ));
    }

    public function schemaInstaller(): SchemaInstaller
    {
        return new SchemaInstaller($this->connection(), $this->config->basePath() . '/database');
    }

    public function userRepository(): UserRepository
    {
        return $this->share('users', fn () => new UserRepository($this->connection()));
    }

    public function tokenGenerator(): TokenGenerator
    {
        return $this->share('token_generator', fn () => new TokenGenerator($this->config->require('APP_KEY')));
    }

    public function tokenService(): TokenService
    {
        return $this->share('tokens', fn () => new TokenService(
            new TokenRepository($this->connection()),
            $this->userRepository(),
            $this->tokenGenerator(),
            $this->securityLogger(),
            $this->clock(),
            $this->config->int('TOKEN_MAX_TTL_DAYS', 90) * 86400,
        ));
    }

    public function entity(): EntityDefinition
    {
        return $this->share('entity', fn () => EntityDefinition::sampleRecords());
    }

    public function recordRepository(): RecordRepositoryInterface
    {
        return $this->share('record_repo', fn () => new PdoRecordRepository($this->connection(), $this->entity()));
    }

    public function recordService(): RecordService
    {
        return $this->share('record_service', fn () => new RecordService(
            $this->entity(),
            $this->recordRepository(),
            new RecordValidator($this->entity()),
            new CriteriaFactory($this->entity()),
            $this->securityLogger(),
        ));
    }

    public function authorization(): AuthorizationService
    {
        return $this->share('authorization', fn () => new AuthorizationService($this->securityLogger()));
    }

    public function toolRegistry(): ToolRegistry
    {
        return $this->share('tool_registry', function () {
            $factories = require $this->config->basePath() . '/config/tools.php';
            $registry = new ToolRegistry();
            foreach ($factories as $factory) {
                $registry->register($factory($this));
            }

            return $registry;
        });
    }

    public function stdioPrincipalResolver(string $token): PrincipalResolverInterface
    {
        return new StdioPrincipalResolver($this->tokenService(), $token);
    }

    public function httpPrincipalResolver(): PrincipalResolverInterface
    {
        return new HttpPrincipalResolver($this->userRepository());
    }

    public function serverFactory(PrincipalResolverInterface $resolver, bool $withSessions = false): ServerFactory
    {
        return new ServerFactory(
            $this->toolRegistry(),
            new ToolInvoker($resolver, $this->authorization(), $this->logger()),
            $this->logger(),
            $withSessions ? $this->config->path('SESSION_PATH', 'storage/sessions') : null,
        );
    }

    // ---- OAuth 2.1 (solo transporte HTTP) -------------------------------------

    public function oauthConfig(): OAuthConfig
    {
        return $this->share('oauth_config', fn () => OAuthConfig::fromConfig($this->config));
    }

    public function authorizationServerFactory(): AuthorizationServerFactory
    {
        return $this->share('oauth_factory', fn () => new AuthorizationServerFactory($this->oauthConfig(), $this->connection(), $this->userRepository()));
    }

    public function identityLinkService(): IdentityLinkService
    {
        return new IdentityLinkService($this->userRepository(), $this->securityLogger());
    }

    /** Proveedor de identidad externo seleccionado con OAUTH_IDP (dev | google | microsoft | generic). */
    public function identityProvider(): IdentityProviderInterface
    {
        return $this->share('idp', function () {
            $kind = $this->config->get('OAUTH_IDP', 'google');
            $oauth = $this->oauthConfig();
            if ($kind === 'dev') {
                return new DevIdentityProvider($this->config->isProduction(), $oauth->endpoint('/oauth/dev-login'));
            }
            $id = $this->config->require('OAUTH_IDP_CLIENT_ID');
            $secret = $this->config->require('OAUTH_IDP_CLIENT_SECRET');
            if ($kind === 'generic') {
                return new GenericOAuthProvider(
                    $this->config->get('OAUTH_IDP_NAME', 'generic'),
                    $id,
                    $secret,
                    $this->config->require('OAUTH_IDP_AUTHORIZE_URL'),
                    $this->config->require('OAUTH_IDP_TOKEN_URL'),
                    $this->config->require('OAUTH_IDP_USERINFO_URL'),
                    $this->config->get('OAUTH_IDP_SCOPES', 'openid email profile'),
                    [],
                    $this->logger(),
                );
            }

            return GenericOAuthProvider::preset($kind, $id, $secret, $this->logger());
        });
    }

    public function accessTokenValidator(): AuthorizationTokenValidatorInterface
    {
        return $this->share('token_validator', function () {
            $oauth = $this->oauthConfig();
            $pem = @file_get_contents($oauth->publicKeyPath);
            if ($pem === false) {
                throw new ConfigurationException('No se encontro la clave publica OAuth. Genere las claves con: php bin/console oauth:keys');
            }
            $jwt = new AccessTokenValidator(
                $pem,
                $oauth->issuer,
                $oauth->resource,
                $this->authorizationServerFactory()->accessTokens(),
                $this->userRepository(),
                $this->securityLogger(),
                $this->clock(),
            );

            return new CompositeTokenValidator($jwt, $this->config->bool('MCP_HTTP_ALLOW_PERSONAL_TOKENS', false) ? $this->tokenService() : null);
        });
    }

    /** Falla pronto si falta configuracion critica. */
    public function assertConfigured(): void
    {
        foreach (['DB_NAME', 'DB_USER', 'APP_KEY'] as $key) {
            if ($this->config->get($key) === null) {
                throw new ConfigurationException("Falta $key en .env (copie .env.example a .env).");
            }
        }
    }
}
