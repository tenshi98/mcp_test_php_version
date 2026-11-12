<?php

declare(strict_types=1);

namespace McpPhp\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'oauth:revoke-user', description: 'Revoca las identidades OAuth y todos los tokens OAuth de un usuario (desvincula).')]
final class OAuthRevokeUserCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption('email', null, InputOption::VALUE_REQUIRED)->addOption('user-id', null, InputOption::VALUE_REQUIRED);
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $id = $this->resolveUserId($input);
        $n = $this->app->userRepository()->revokeOAuthAuthorizations($id);
        $this->app->securityLogger()->warning('oauth.user_revoked', $id, ['access_tokens' => $n]);
        $output->writeln("<info>Autorizaciones OAuth revocadas</info> (access tokens: $n). El usuario debera volver a vincular su identidad.");

        return 0;
    }
}
