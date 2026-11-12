<?php

declare(strict_types=1);

namespace McpPhp\Console;

use McpPhp\Repositories\TokenRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'maintenance:purge', description: 'Elimina tokens vencidos/revocados, codigos y tokens OAuth caducados y eventos antiguos.')]
final class PurgeCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption('days', null, InputOption::VALUE_REQUIRED, 'Antiguedad minima en dias', '30')
            ->addOption('events-days', null, InputOption::VALUE_REQUIRED, 'Antiguedad de eventos de seguridad a borrar', '365');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $days = max(1, (int) $input->getOption('days'));
        $db = $this->app->connection();
        $tokens = (new TokenRepository($db))->purge($days);
        $codes = $db->run('DELETE FROM oauth_auth_codes WHERE expires_at < (UTC_TIMESTAMP() - INTERVAL :d DAY)', ['d' => $days])->rowCount();
        $rt = $db->run('DELETE FROM oauth_refresh_tokens WHERE expires_at < (UTC_TIMESTAMP() - INTERVAL :d DAY)', ['d' => $days])->rowCount();
        $at = $db->run('DELETE FROM oauth_access_tokens WHERE expires_at < (UTC_TIMESTAMP() - INTERVAL :d DAY)', ['d' => $days])->rowCount();
        $ev = $db->run('DELETE FROM security_events WHERE created_at < (UTC_TIMESTAMP() - INTERVAL :d DAY)', ['d' => max(1, (int) $input->getOption('events-days'))])->rowCount();
        $output->writeln("Tokens personales: $tokens | codigos OAuth: $codes | refresh: $rt | access: $at | eventos: $ev");

        return 0;
    }
}
