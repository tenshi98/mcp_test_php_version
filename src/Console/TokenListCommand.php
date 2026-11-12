<?php

declare(strict_types=1);

namespace McpPhp\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'token:list', description: 'Lista tokens personales (nunca muestra el valor).')]
final class TokenListCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption('email', null, InputOption::VALUE_REQUIRED)->addOption('user-id', null, InputOption::VALUE_REQUIRED);
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $uid = ($input->getOption('email') || $input->getOption('user-id')) ? $this->resolveUserId($input) : null;
        $rows = array_map(static fn ($t) => [$t['id'], $t['user_id'], $t['label'], $t['status'], $t['issued_at'], $t['expires_at'], $t['last_used_at'] ?? '-'], $this->app->tokenService()->list($uid));
        (new Table($output))->setHeaders(['id', 'usuario', 'etiqueta', 'estado', 'emitido', 'expira', 'ultimo uso'])->setRows($rows)->render();

        return 0;
    }
}
