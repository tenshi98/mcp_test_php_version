<?php

declare(strict_types=1);

namespace McpPhp\OAuth\Endpoints;

/** Sesion PHP nativa con cookie endurecida (solo para el flujo interactivo de autorizacion). */
final class WebSession
{
    public function __construct(private readonly bool $secure, private readonly string $savePath = '')
    {
    }

    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        if ($this->savePath !== '') {
            if (!is_dir($this->savePath)) {
                @mkdir($this->savePath, 0700, true);
            }
            session_save_path($this->savePath);
        }
        session_name('mcp_oauth');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/oauth',
            'secure' => $this->secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_start();
    }

    public function get(string $key): mixed
    {
        $this->start();

        return $_SESSION[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $this->start();
        $_SESSION[$key] = $value;
    }

    public function forget(string ...$keys): void
    {
        $this->start();
        foreach ($keys as $k) {
            unset($_SESSION[$k]);
        }
    }

    public function regenerate(): void
    {
        $this->start();
        session_regenerate_id(true);
    }
}
