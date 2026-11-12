<?php

declare(strict_types=1);

namespace McpPhp\Tools;

use McpPhp\Authorization\Permission;
use McpPhp\Authorization\Principal;

final class ListRecordsTool extends AbstractRecordTool
{
    public function name(): string
    {
        return 'list_records';
    }

    public function title(): string
    {
        return 'Listar registros';
    }

    public function description(): string
    {
        return 'Lista registros de sample_records con paginacion, ordenamiento y seleccion de campos.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => $this->listingProperties(), 'additionalProperties' => false];
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
        return $this->records->list($arguments);
    }
}
