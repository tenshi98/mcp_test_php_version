<?php

declare(strict_types=1);

namespace McpPhp\Http;

use Psr\Http\Message\ResponseInterface;

/** Envia una respuesta PSR-7 al cliente (admite cuerpos en streaming, p. ej. SSE). */
final class ResponseEmitter
{
    public function emit(ResponseInterface $response): void
    {
        if (!headers_sent()) {
            http_response_code($response->getStatusCode());
            foreach ($response->getHeaders() as $name => $values) {
                foreach ($values as $i => $value) {
                    header("$name: $value", $i === 0);
                }
            }
        }
        $body = $response->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }
        while (!$body->eof()) {
            echo $body->read(8192);
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
        }
    }
}
