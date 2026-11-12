<?php

declare(strict_types=1);

namespace McpPhp\Console;

use McpPhp\Exceptions\ValidationException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'user:status', description: 'Cambia el estado de un usuario (active|suspended|disabled). Al suspender/deshabilitar revoca sus tokens y autorizaciones OAuth.')]
final class UserStatusCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addArgument('status', InputArgument::REQUIRED)
            ->addOption('email', null, InputOption::VALUE_REQUIRED)
            ->addOption('user-id', null, InputOption::VALUE_REQUIRED);
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $status = (string) $input->getArgument('status');
        if (!in_array($status, ['active', 'suspended', 'disabled'], true)) {
            throw new ValidationException(['status' => 'Use active, suspended o disabled.']);
        }
        $id = $this->resolveUserId($input);
        $this->app->userRepository()->setStatus($id, $status);
        $this->app->securityLogger()->warning('user.status_changed', $id, ['status' => $status]);
        if ($status !== 'active') {
            $n = $this->app->tokenService()->revokeAllForUser($id);
            $m = $this->app->userRepository()->revokeOAuthAuthorizations($id);
            $output->writeln("Tokens personales revocados: $n; tokens OAuth revocados: $m");
        }
        $output->writeln("<info>Usuario $id => $status</info>");

        return 0;
    }
}
