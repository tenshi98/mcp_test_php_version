<?php

declare(strict_types=1);

namespace McpPhp\Console;

use McpPhp\Exceptions\ConfigurationException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'oauth:keys', description: 'Genera el par de claves RSA con que se firman los access tokens JWT.')]
final class OAuthKeysCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Sobrescribe claves existentes (invalida todos los tokens emitidos).');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $priv = $this->app->config->path('OAUTH_PRIVATE_KEY', 'storage/keys/private.pem');
        $pub = $this->app->config->path('OAUTH_PUBLIC_KEY', 'storage/keys/public.pem');
        if ((is_file($priv) || is_file($pub)) && !$input->getOption('force')) {
            throw new ConfigurationException('Ya existen claves. Use --force para regenerarlas (invalida los tokens actuales).');
        }
        $key = openssl_pkey_new(['private_key_bits' => 3072, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($key === false || !openssl_pkey_export($key, $privatePem)) {
            throw new ConfigurationException('No se pudo generar la clave RSA (revise la extension openssl).');
        }
        $details = openssl_pkey_get_details($key);
        @mkdir(dirname($priv), 0700, true);
        file_put_contents($priv, $privatePem);
        chmod($priv, 0600);
        file_put_contents($pub, $details['key']);
        chmod($pub, 0644);
        $output->writeln("<info>Claves generadas:</info>\n  $priv (privada, 0600)\n  $pub");

        return 0;
    }
}
