<?php

declare(strict_types=1);

namespace McpPhp\Tools;

use McpPhp\Authorization\Permission;
use McpPhp\Authorization\Principal;

final class CreateRecordTool extends AbstractRecordTool
{
    public function name(): string
    {
        return 'create_record';
    }

    public function title(): string
    {
        return 'Crear registro';
    }

    public function description(): string
    {
        return 'Crea un registro en sample_records y devuelve el registro insertado (con id y fechas generados).';
    }

    public function inputSchema(): array
    {
        $props = [];
        $required = [];
        foreach ($this->records->entity()->writable() as $name => $col) {
            $props[$name] = $col->jsonSchema();
            if ($col->required) {
                $required[] = $name;
            }
        }

        return ['type' => 'object', 'properties' => $props, 'required' => $required, 'additionalProperties' => false];
    }

    public function requiredPermission(): Permission
    {
        return Permission::RecordsWrite;
    }

    public function execute(array $arguments, Principal $principal): array
    {
        return ['record' => $this->records->create($arguments, $principal->userId)];
    }
}
