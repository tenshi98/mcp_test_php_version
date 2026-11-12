<?php

declare(strict_types=1);

namespace McpPhp\Console;

use McpPhp\Core\Application;
use McpPhp\Exceptions\McpPhpException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

abstract class BaseCommand extends Command
{
    public function __construct(protected readonly Application $app)
    {
        parent::__construct();
    }

    abstract protected function handle(InputInterface $input, OutputInterface $output): int;

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            return $this->handle($input, $output);
        } catch (McpPhpException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            if ($output->isVerbose() && $e->getPrevious()) {
                $output->writeln('<comment>' . $e->getPrevious()->getMessage() . '</comment>');
            }

            return Command::FAILURE;
        }
    }

    /** Resuelve --email o --user-id a un id de usuario. */
    protected function resolveUserId(InputInterface $input): int
    {
        $email = $input->getOption('email');
        $id = $input->getOption('user-id');
        if ($email) {
            $u = $this->app->userRepository()->findByEmail((string) $email);
        } elseif ($id) {
            $u = $this->app->userRepository()->findById((int) $id);
        } else {
            throw new \McpPhp\Exceptions\ValidationException(['user' => 'Indique --email o --user-id.']);
        }
        if ($u === null) {
            throw new \McpPhp\Exceptions\NotFoundException('Usuario no encontrado.');
        }

        return (int) $u['id'];
    }
}
