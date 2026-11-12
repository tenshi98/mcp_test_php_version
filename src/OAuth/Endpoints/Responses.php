<?php

declare(strict_types=1);

namespace McpPhp\OAuth\Endpoints;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/** Utilidades para construir respuestas JSON/HTML con cabeceras de seguridad. */
final class Responses
{
    public function __construct(
        private readonly ResponseFactoryInterface $responses,
        private readonly StreamFactoryInterface $streams,
    ) {
    }

    /** @param array<string, mixed> $data */
    public function json(array $data, int $status = 200, bool $noStore = true): ResponseInterface
    {
        $r = $this->responses->createResponse($status)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streams->createStream(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)));

        return $noStore ? $r->withHeader('Cache-Control', 'no-store')->withHeader('Pragma', 'no-cache') : $r;
    }

    public function html(string $title, string $bodyHtml, int $status = 200): ResponseInterface
    {
        $t = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $page = <<<HTML
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>$t</title>
<style>body{font-family:system-ui,sans-serif;background:#f4f5f7;margin:0;display:grid;place-items:center;min-height:100vh}
main{background:#fff;max-width:30rem;width:92%;padding:2rem;border-radius:12px;box-shadow:0 2px 12px #0002}
h1{font-size:1.25rem;margin-top:0}button{padding:.6rem 1.2rem;border:0;border-radius:8px;font-size:1rem;cursor:pointer;margin-right:.5rem}
.ok{background:#1a73e8;color:#fff}.no{background:#e4e6eb}input[type=email]{width:100%;padding:.6rem;font-size:1rem;box-sizing:border-box;margin:.5rem 0 1rem}
code{background:#eef;padding:.1rem .3rem;border-radius:4px}</style></head><body><main><h1>$t</h1>$bodyHtml</main></body></html>
HTML;

        return $this->responses->createResponse($status)
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; form-action 'self' https: http:; frame-ancestors 'none'; base-uri 'none'")
            ->withBody($this->streams->createStream($page));
    }

    public function redirect(string $url): ResponseInterface
    {
        return $this->responses->createResponse(302)->withHeader('Location', $url)->withHeader('Cache-Control', 'no-store');
    }

    public function empty(int $status = 200): ResponseInterface
    {
        return $this->responses->createResponse($status)->withHeader('Cache-Control', 'no-store');
    }

    public static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
