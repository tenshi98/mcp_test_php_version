<?php

declare(strict_types=1);

namespace McpPhp\Mcp;

use McpPhp\Exceptions\ConfigurationException;

/** Coleccion de herramientas disponibles. */
final class ToolRegistry
{
    /** @var array<string, ToolInterface> */
    private array $tools = [];

    /** @param iterable<ToolInterface> $tools */
    public function __construct(iterable $tools = [])
    {
        foreach ($tools as $tool) {
            $this->register($tool);
        }
    }

    public function register(ToolInterface $tool): void
    {
        $name = $tool->name();
        if (!preg_match('/^[a-zA-Z0-9_.-]{1,128}$/', $name)) {
            throw new ConfigurationException("Nombre de herramienta invalido: $name");
        }
        if (isset($this->tools[$name])) {
            throw new ConfigurationException("Herramienta duplicada: $name");
        }
        $schema = $tool->inputSchema();
        if (($schema['type'] ?? null) !== 'object') {
            throw new ConfigurationException("El inputSchema de $name debe ser de tipo object.");
        }
        $this->tools[$name] = $tool;
    }

    /** @return array<string, ToolInterface> */
    public function all(): array
    {
        return $this->tools;
    }

    public function get(string $name): ?ToolInterface
    {
        return $this->tools[$name] ?? null;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->tools);
    }
}
