<?php

declare(strict_types=1);

namespace McpPhp\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'db:install', description: 'Ejecuta los scripts SQL de database/ (tablas, indices, relaciones y datos de prueba).')]
final class DbInstallCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption('no-seed', null, InputOption::VALUE_NONE, 'No cargar usuarios ni datos de prueba.')
            ->addOption('fresh', null, InputOption::VALUE_NONE, 'Borra TODAS las tablas antes de instalar.');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $installer = $this->app->schemaInstaller();
        if ($input->getOption('fresh')) {
            $installer->dropAll();
            $output->writeln('<comment>Tablas existentes eliminadas.</comment>');
        }
        foreach ($installer->install(!$input->getOption('no-seed')) as $script) {
            $output->writeln("  <info>OK</info> $script");
        }

        return 0;
    }
}
