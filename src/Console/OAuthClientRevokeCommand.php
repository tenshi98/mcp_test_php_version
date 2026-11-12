<?php

declare(strict_types=1);

namespace McpPhp\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'oauth:client:revoke', description: 'Desactiva un cliente OAuth y revoca todos sus tokens.')]
final class OAuthClientRevokeCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addArgument('client_id', InputArgument::REQUIRED);
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $ok = $this->app->authorizationServerFactory()->clients()->revoke((string) $input->getArgument('client_id'));
        $this->app->securityLogger()->warning('oauth.client_revoked', null, ['client_id' => (string) $input->getArgument('client_id')]);
        $output->writeln($ok ? '<info>Cliente revocado.</info>' : '<error>Cliente no encontrado.</error>');

        return $ok ? 0 : 1;
    }
}
