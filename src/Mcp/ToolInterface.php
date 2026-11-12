<?php

declare(strict_types=1);

namespace McpPhp\Mcp;

use McpPhp\Authorization\Permission;
use McpPhp\Authorization\Principal;

/**
 * Contrato de una herramienta MCP. Para agregar una herramienta nueva basta
 * implementar esta interfaz y registrarla en config/tools.php: no hay que tocar el nucleo.
 */
interface ToolInterface
{
    /** Nombre unico (snake_case, hasta 128 caracteres). */
    public function name(): string;

    public function title(): string;

    public function description(): string;

    /** JSON Schema (draft 2020-12) de los argumentos; debe ser type=object. */
    public function inputSchema(): array;

    /** Permiso que debe tener el Principal para ejecutarla. */
    public function requiredPermission(): Permission;

    /** @return array{readOnlyHint?: bool, destructiveHint?: bool, idempotentHint?: bool, openWorldHint?: bool} */
    public function annotations(): array;

    /**
     * Ejecuta la herramienta. Debe devolver un array asociativo (se enviara como
     * structuredContent y como texto JSON). Para errores lance una McpPhpException.
     *
     * @param array<string, mixed> $arguments argumentos ya validados contra inputSchema()
     * @return array<string, mixed>
     */
    public function execute(array $arguments, Principal $principal): array;
}
