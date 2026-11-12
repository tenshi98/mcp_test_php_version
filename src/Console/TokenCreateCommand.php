<?php

declare(strict_types=1);

namespace McpPhp\Console;

use McpPhp\Exceptions\ValidationException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'token:create', description: 'Emite un token personal para STDIO/automatizacion (se muestra una sola vez).')]
final class TokenCreateCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption('email', null, InputOption::VALUE_REQUIRED)
            ->addOption('user-id', null, InputOption::VALUE_REQUIRED)
            ->addOption('label', null, InputOption::VALUE_REQUIRED, 'Etiqueta descriptiva', 'cli')
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Vigencia en dias', '30')
            ->addOption('scopes', null, InputOption::VALUE_REQUIRED, 'Scopes separados por coma (por defecto, todos los del rol)')
            ->addOption('raw', null, InputOption::VALUE_NONE, 'Imprime solo el token (para scripts).');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $userId = $this->resolveUserId($input);
        $days = (string) $input->getOption('days');
        if (!ctype_digit($days) || (int) $days < 1) {
            throw new ValidationException(['days' => 'Debe ser un entero >= 1.']);
        }
        $scopes = $input->getOption('scopes') ? array_map('trim', explode(',', (string) $input->getOption('scopes'))) : null;
        $r = $this->app->tokenService()->issue($userId, (string) $input->getOption('label'), (int) $days * 86400, $scopes);

        if ($input->getOption('raw')) {
            $output->writeln($r['token']);

            return 0;
        }
        $output->writeln("<info>Token emitido</info> id={$r['id']} expira={$r['expires_at']} UTC scopes=" . implode(' ', $r['scopes']));
        $output->writeln('<comment>Guardelo ahora: no se vuelve a mostrar.</comment>');
        $output->writeln('MCP_ACCESS_TOKEN=' . $r['token']);

        return 0;
    }
}
