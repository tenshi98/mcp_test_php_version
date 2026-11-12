<?php

declare(strict_types=1);

namespace McpPhp\Console;

use McpPhp\Exceptions\ValidationException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'oauth:client:create', description: 'Registra manualmente un cliente OAuth (alternativa al registro dinamico).')]
final class OAuthClientCreateCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED)
            ->addOption('redirect-uri', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Redirect URI (repetible)')
            ->addOption('confidential', null, InputOption::VALUE_NONE, 'Cliente confidencial (genera client_secret)');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $uris = (array) $input->getOption('redirect-uri');
        if ($uris === []) {
            throw new ValidationException(['redirect-uri' => 'Indique al menos una --redirect-uri.']);
        }
        $r = $this->app->authorizationServerFactory()->clients()->register((string) $input->getArgument('name'), $uris, (bool) $input->getOption('confidential'));
        $output->writeln('client_id=' . $r['client_id']);
        if ($r['client_secret'] !== null) {
            $output->writeln('client_secret=' . $r['client_secret'] . '  <comment>(se muestra una sola vez)</comment>');
        }

        return 0;
    }
}
