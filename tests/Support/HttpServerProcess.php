<?php

declare(strict_types=1);

namespace McpPhp\Tests\Support;

/** Levanta `php -S` con public/index.php y la configuracion de pruebas. */
final class HttpServerProcess
{
    /** @var resource|null */
    private $proc = null;
    public readonly int $port;
    public readonly string $url;
    public readonly string $tmp;

    /** @param array<string, string> $env */
    public function __construct(array $env)
    {
        $s = stream_socket_server('tcp://127.0.0.1:0');
        $this->port = (int) explode(':', (string) stream_socket_get_name($s, false))[1];
        fclose($s);
        $this->url = 'http://127.0.0.1:' . $this->port;
        $this->tmp = sys_get_temp_dir() . '/mcp-http-test-' . getmypid() . '-' . $this->port;
        @mkdir($this->tmp . '/keys', 0700, true);

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $priv);
        file_put_contents($this->tmp . '/keys/private.pem', $priv);
        file_put_contents($this->tmp . '/keys/public.pem', openssl_pkey_get_details($key)['key']);

        $root = dirname(__DIR__, 2);
        $env += [
            'APP_ENV' => 'testing',
            'APP_URL' => $this->url,
            'OAUTH_PRIVATE_KEY' => $this->tmp . '/keys/private.pem',
            'OAUTH_PUBLIC_KEY' => $this->tmp . '/keys/public.pem',
            'OAUTH_ENCRYPTION_KEY' => base64_encode(str_repeat('e', 32)),
            'OAUTH_IDP' => 'dev',
            'SESSION_PATH' => $this->tmp . '/sessions',
            'LOG_PATH' => $this->tmp . '/logs',
            'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
            'HOME' => $this->tmp,
        ];
        $this->proc = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $this->port, '-t', $root . '/public', $root . '/public/index.php'],
            [0 => ['pipe', 'r'], 1 => ['file', $this->tmp . '/server.out', 'w'], 2 => ['file', $this->tmp . '/server.err', 'w']],
            $pipes,
            $root,
            $env,
        );
        for ($i = 0; $i < 100; $i++) {
            $c = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 0.2);
            if ($c) {
                fclose($c);

                return;
            }
            usleep(100000);
        }
        throw new \RuntimeException('El servidor HTTP de pruebas no arranco');
    }

    public function __destruct()
    {
        $this->stop();
    }

    public function stop(): void
    {
        if (is_resource($this->proc)) {
            proc_terminate($this->proc);
            proc_close($this->proc);
            $this->proc = null;
        }
    }
}
