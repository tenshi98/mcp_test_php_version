<?php

declare(strict_types=1);

namespace McpPhp\Tests\Support;

/** Cliente HTTP (curl) con cookies y SIN seguir redirecciones, para probar el flujo OAuth paso a paso. */
final class TestHttpClient
{
    private string $cookieFile;

    public function __construct(private readonly string $base)
    {
        $this->cookieFile = tempnam(sys_get_temp_dir(), 'cj');
    }

    public function __destruct()
    {
        @unlink($this->cookieFile);
    }

    /**
     * @param array<string, string>|string|null $body array => form-urlencoded; string => crudo
     * @param array<string, string> $headers
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function request(string $method, string $path, array|string|null $body = null, array $headers = []): array
    {
        $url = str_starts_with($path, 'http') ? $path : $this->base . $path;
        $ch = curl_init($url);
        $h = [];
        foreach ($headers as $k => $v) {
            $h[] = "$k: $v";
        }
        if (is_array($body)) {
            $body = http_build_query($body);
            $h[] = 'Content-Type: application/x-www-form-urlencoded';
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR => $this->cookieFile,
            CURLOPT_COOKIEFILE => $this->cookieFile,
            CURLOPT_HTTPHEADER => $h,
            CURLOPT_TIMEOUT => 20,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $headers = [];
        foreach (explode("\r\n", substr($raw, 0, $size)) as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $headers[strtolower(trim($k))] = trim($v);
            }
        }

        return ['status' => $status, 'headers' => $headers, 'body' => substr($raw, $size)];
    }

    /** @return array<string, mixed> */
    public function json(array $response): array
    {
        $d = json_decode($response['body'], true);

        return is_array($d) ? $d : [];
    }
}
