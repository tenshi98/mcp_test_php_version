<?php

declare(strict_types=1);

namespace McpPhp\Console;

use McpPhp\Core\Application;
use Symfony\Component\Console\Application as ConsoleApp;

final class ConsoleFactory
{
    public static function create(string $basePath): ConsoleApp
    {
        $app = Application::boot($basePath);
        $console = new ConsoleApp('mcp-php-crud-server', '1.0.0');
        foreach ([
            new DbInstallCommand($app),
            new GenerateKeyCommand($app),
            new UserCreateCommand($app),
            new UserListCommand($app),
            new UserStatusCommand($app),
            new TokenCreateCommand($app),
            new TokenListCommand($app),
            new TokenRevokeCommand($app),
            new OAuthKeysCommand($app),
            new OAuthClientCreateCommand($app),
            new OAuthClientRevokeCommand($app),
            new OAuthRevokeUserCommand($app),
            new PurgeCommand($app),
        ] as $cmd) {
            $console->add($cmd);
        }

        return $console;
    }
}
