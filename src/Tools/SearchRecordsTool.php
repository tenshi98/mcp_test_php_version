<?php

declare(strict_types=1);

namespace McpPhp\Tools;

use McpPhp\Authorization\Permission;
use McpPhp\Authorization\Principal;

final class SearchRecordsTool extends AbstractRecordTool
{
    public function name(): string
    {
        return 'search_records';
    }

    public function title(): string
    {
        return 'Buscar registros';
    }

    public function description(): string
    {
        return 'Busca en sample_records por texto, nombre, categoria, estado, rangos numericos y de fechas. '
            . 'Todos los filtros se combinan con AND. Incluye paginacion, orden y seleccion de campos.';
    }

    public function inputSchema(): array
    {
        $e = $this->records->entity();
        $props = [
            'text' => ['type' => 'string', 'maxLength' => 200, 'description' => 'Busca (LIKE, sin distinguir mayusculas) en nombre, apellido, correo, titulo, texto corto, descripcion y observaciones.'],
            'name' => ['type' => 'string', 'maxLength' => 160, 'description' => 'Busca en nombre, apellido o nombre completo.'],
            'category' => ['type' => 'string', 'maxLength' => 50, 'description' => 'Categoria exacta (p. ej. logistica, flota, tecnologia).'],
            'status' => $e->column('status')->jsonSchema(),
            'is_active' => ['type' => 'boolean'],
            'min_quantity' => ['type' => 'integer'],
            'max_quantity' => ['type' => 'integer'],
            'min_price' => ['type' => 'number'],
            'max_price' => ['type' => 'number'],
            'min_rating' => ['type' => 'number'],
            'max_rating' => ['type' => 'number'],
            'date_field' => ['type' => 'string', 'enum' => $e->dateColumns(), 'default' => 'event_date', 'description' => 'Columna de fecha para date_from/date_to.'],
            'date_from' => ['type' => 'string', 'format' => 'date', 'description' => 'Desde (YYYY-MM-DD, inclusive).'],
            'date_to' => ['type' => 'string', 'format' => 'date', 'description' => 'Hasta (YYYY-MM-DD, inclusive).'],
            'null_fields' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => $e->nullableColumns()], 'description' => 'Solo registros donde estos campos opcionales sean NULL.'],
            'not_null_fields' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => $e->nullableColumns()], 'description' => 'Solo registros donde estos campos NO sean NULL.'],
        ];

        return ['type' => 'object', 'properties' => $props + $this->listingProperties(), 'additionalProperties' => false];
    }

    public function requiredPermission(): Permission
    {
        return Permission::RecordsRead;
    }

    public function annotations(): array
    {
        return ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false];
    }

    public function execute(array $arguments, Principal $principal): array
    {
        return $this->records->search($arguments);
    }
}
