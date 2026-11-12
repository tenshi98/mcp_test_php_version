<?php

declare(strict_types=1);

namespace McpPhp\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'token:revoke', description: 'Revoca un token personal por id.')]
final class TokenRevokeCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED);
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $this->app->tokenService()->revoke((int) $input->getArgument('id'));
        $output->writeln('<info>Token revocado.</info>');

        return 0;
    }
}
