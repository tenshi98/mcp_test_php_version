<?php

declare(strict_types=1);

namespace McpPhp\Tools;

use McpPhp\Authorization\Permission;
use McpPhp\Authorization\Principal;

final class GetRecordTool extends AbstractRecordTool
{
    public function name(): string
    {
        return 'get_record';
    }

    public function title(): string
    {
        return 'Obtener registro por ID';
    }

    public function description(): string
    {
        return 'Devuelve un registro de sample_records por su id (opcionalmente solo algunos campos).';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['id' => $this->idProperty(), 'fields' => $this->fieldsProperty()],
            'required' => ['id'],
            'additionalProperties' => false,
        ];
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
        return ['record' => $this->records->get($arguments['id'], $arguments['fields'] ?? null)];
    }
}
