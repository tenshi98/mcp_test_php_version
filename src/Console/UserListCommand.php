<?php

declare(strict_types=1);

namespace McpPhp\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'user:list', description: 'Lista los usuarios permitidos.')]
final class UserListCommand extends BaseCommand
{
    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $rows = array_map(static fn ($u) => [$u['id'], $u['email'], $u['name'], $u['role'], $u['status']], $this->app->userRepository()->all());
        (new Table($output))->setHeaders(['id', 'email', 'nombre', 'rol', 'estado'])->setRows($rows)->render();

        return 0;
    }
}
