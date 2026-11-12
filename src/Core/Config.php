<?php

declare(strict_types=1);

namespace McpPhp\Core;

use Dotenv\Dotenv;
use McpPhp\Exceptions\ConfigurationException;

/**
 * Acceso tipado a la configuracion (variables de entorno / archivo .env).
 * Las variables reales del entorno tienen prioridad sobre el .env.
 */
final class Config
{
    /** @param array<string, string> $values */
    public function __construct(private readonly array $values, private readonly string $basePath)
    {
    }

    /** Carga .env (si existe) y las variables reales del entorno. */
    public static function load(string $basePath): self
    {
        if (is_file($basePath . '/.env')) {
            Dotenv::createImmutable($basePath)->safeLoad();
        }

        $values = [];
        foreach (array_merge($_ENV, getenv() ?: [], $_SERVER) as $k => $v) {
            if (is_string($k) && is_scalar($v) && preg_match('/^[A-Z][A-Z0-9_]*$/', $k)) {
                $values[$k] = (string) $v;
            }
        }

        return new self($values, $basePath);
    }

    /** @param array<string, scalar|null> $values */
    public static function fromArray(array $values, string $basePath = ''): self
    {
        return new self(array_map(static fn ($v) => (string) $v, array_filter($values, static fn ($v) => $v !== null)), $basePath);
    }

    public function basePath(): string
    {
        return $this->basePath;
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $v = $this->values[$key] ?? null;

        return ($v === null || $v === '') ? $default : $v;
    }

    public function require(string $key): string
    {
        $v = $this->get($key);
        if ($v === null) {
            throw new ConfigurationException("Falta la variable de configuracion obligatoria $key.");
        }

        return $v;
    }

    public function int(string $key, int $default): int
    {
        $v = $this->get($key);

        return $v !== null && is_numeric($v) ? (int) $v : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $v = $this->get($key);
        if ($v === null) {
            return $default;
        }

        return in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
    }

    /** @return list<string> Lista separada por comas. */
    public function list(string $key, string $default = ''): array
    {
        $v = $this->get($key, $default) ?? '';

        return array_values(array_filter(array_map('trim', explode(',', $v)), static fn ($s) => $s !== ''));
    }

    /** Resuelve una ruta relativa respecto a la raiz del proyecto. */
    public function path(string $key, string $default): string
    {
        $p = $this->get($key, $default) ?? $default;

        return str_starts_with($p, '/') ? $p : $this->basePath . '/' . ltrim($p, './');
    }

    public function isProduction(): bool
    {
        return $this->get('APP_ENV', 'production') === 'production';
    }
}
