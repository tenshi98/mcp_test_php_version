<?php

declare(strict_types=1);

namespace McpPhp\Tools;

use McpPhp\Mcp\ToolInterface;
use McpPhp\Services\RecordService;

/** Base comun de las herramientas CRUD: acceso al servicio y utilidades de esquema. */
abstract class AbstractRecordTool implements ToolInterface
{
    public function __construct(protected readonly RecordService $records)
    {
    }

    public function annotations(): array
    {
        return ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false];
    }

    /** @return array<string, mixed> propiedades comunes de ordenamiento y paginacion */
    protected function listingProperties(): array
    {
        $cols = $this->records->entity()->columnNames();

        return [
            'page' => ['type' => 'integer', 'minimum' => 1, 'default' => 1, 'description' => 'Numero de pagina (desde 1).'],
            'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20, 'description' => 'Registros por pagina (maximo 100).'],
            'sort_by' => ['type' => 'string', 'enum' => $cols, 'description' => 'Columna de ordenamiento (por defecto id).'],
            'sort_dir' => ['type' => 'string', 'enum' => ['asc', 'desc', 'ASC', 'DESC'], 'default' => 'asc'],
            'fields' => $this->fieldsProperty(),
        ];
    }

    /** @return array<string, mixed> */
    protected function fieldsProperty(): array
    {
        return [
            'type' => 'array',
            'items' => ['type' => 'string', 'enum' => $this->records->entity()->columnNames()],
            'minItems' => 1,
            'uniqueItems' => true,
            'description' => 'Campos a devolver (por defecto todos).',
        ];
    }

    protected function idProperty(): array
    {
        return ['type' => 'integer', 'minimum' => 1, 'description' => 'Identificador del registro.'];
    }
}
