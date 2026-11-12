<?php

declare(strict_types=1);

namespace McpPhp\Tests\Support;

/** Cliente MCP minimo que lanza bin/mcp-server y habla JSON-RPC por STDIO. */
final class McpStdioProcess
{
    /** @var resource */
    private $proc;
    /** @var array<int, resource> */
    private array $pipes = [];
    private int $nextId = 1;
    /** @var list<string> lineas de stdout que no eran JSON valido (no debe haber ninguna) */
    public array $invalidStdoutLines = [];

    /** @param array<string, string> $env */
    public function __construct(array $env)
    {
        $root = dirname(__DIR__, 2);
        $env += ['PATH' => getenv('PATH') ?: '/usr/bin:/bin', 'HOME' => sys_get_temp_dir(), 'LOG_PATH' => sys_get_temp_dir() . '/mcp-php-tests-stdio'];
        $proc = proc_open([PHP_BINARY, $root . '/bin/mcp-server'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $this->pipes, $root, $env);
        if (!is_resource($proc)) {
            throw new \RuntimeException('No se pudo lanzar bin/mcp-server');
        }
        $this->proc = $proc;
        stream_set_blocking($this->pipes[1], true);
    }

    public function __destruct()
    {
        $this->close();
    }

    /** @param array<string, mixed> $params @return array<string, mixed>|null */
    public function request(string $method, array $params = [], float $timeout = 15.0): ?array
    {
        $id = $this->nextId++;
        $this->send(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => (object) $params]);

        return $this->readResponse($id, $timeout);
    }

    /** @param array<string, mixed> $params */
    public function notify(string $method, array $params = []): void
    {
        $this->send(['jsonrpc' => '2.0', 'method' => $method] + ($params ? ['params' => $params] : []));
    }

    public function initialize(): array
    {
        $r = $this->request('initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'phpunit', 'version' => '1']]);
        $this->notify('notifications/initialized');

        return $r ?? [];
    }

    /** @param array<string, mixed> $arguments @return array<string, mixed> */
    public function callTool(string $name, array $arguments = []): array
    {
        return $this->request('tools/call', ['name' => $name, 'arguments' => (object) $arguments]) ?? [];
    }

    /** @param array<string, mixed> $msg */
    private function send(array $msg): void
    {
        fwrite($this->pipes[0], json_encode($msg) . "\n");
        fflush($this->pipes[0]);
    }

    /** @return array<string, mixed>|null */
    private function readResponse(int $id, float $timeout): ?array
    {
        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            $read = [$this->pipes[1]];
            $w = $e = null;
            if (stream_select($read, $w, $e, 0, 200000) > 0) {
                $line = fgets($this->pipes[1]);
                if ($line === false) {
                    return null;
                }
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $msg = json_decode($line, true);
                if (!is_array($msg)) {
                    $this->invalidStdoutLines[] = $line;
                    continue;
                }
                if (($msg['id'] ?? null) === $id) {
                    return $msg;
                }
            }
        }

        return null;
    }

    /** @return array{exit: int, stderr: string} */
    public function close(): array
    {
        if (!is_resource($this->proc)) {
            return ['exit' => -1, 'stderr' => ''];
        }
        @fclose($this->pipes[0]);
        $deadline = microtime(true) + 3;
        $status = proc_get_status($this->proc);
        while ($status['running'] && microtime(true) < $deadline) {
            usleep(50000);
            $status = proc_get_status($this->proc);
        }
        if ($status['running']) {
            proc_terminate($this->proc);
        }
        $exit = $status['running'] ? -1 : (int) $status['exitcode'];
        $stderr = '';
        if (is_resource($this->pipes[2])) {
            stream_set_blocking($this->pipes[2], false);
            $stderr = (string) stream_get_contents($this->pipes[2]);
        }
        @fclose($this->pipes[1]);
        @fclose($this->pipes[2]);
        proc_close($this->proc);
        $this->proc = null;

        return ['exit' => $exit, 'stderr' => $stderr];
    }
}
