<?php

declare(strict_types=1);

namespace McpPhp\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:generate-key', description: 'Genera valores aleatorios para APP_KEY y OAUTH_ENCRYPTION_KEY.')]
final class GenerateKeyCommand extends BaseCommand
{
    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('APP_KEY=' . bin2hex(random_bytes(32)));
        $output->writeln('OAUTH_ENCRYPTION_KEY=' . base64_encode(random_bytes(32)));

        return 0;
    }
}
