<?php

declare(strict_types=1);

namespace McpPhp\Console;

use McpPhp\Authorization\Role;
use McpPhp\Exceptions\ValidationException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'user:create', description: 'Agrega un usuario a la lista de usuarios permitidos.')]
final class UserCreateCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED)
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Nombre completo')
            ->addOption('role', null, InputOption::VALUE_REQUIRED, 'admin | editor | viewer', 'viewer');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $email = (string) $input->getArgument('email');
        $role = Role::tryFrom((string) $input->getOption('role'));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || $role === null) {
            throw new ValidationException(['args' => 'Correo invalido o rol no valido (admin|editor|viewer).']);
        }
        $id = $this->app->userRepository()->create((string) ($input->getOption('name') ?: $email), $email, $role->value);
        $this->app->securityLogger()->info('user.created', $id, ['role' => $role->value, 'via' => 'cli']);
        $output->writeln("<info>Usuario creado</info> id=$id email=$email rol={$role->value}");

        return 0;
    }
}
