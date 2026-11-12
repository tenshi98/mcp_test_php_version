<?php

declare(strict_types=1);

namespace McpPhp\Tools;

use McpPhp\Authorization\Permission;
use McpPhp\Authorization\Principal;

final class UpdateRecordTool extends AbstractRecordTool
{
    public function name(): string
    {
        return 'update_record';
    }

    public function title(): string
    {
        return 'Actualizar registro';
    }

    public function description(): string
    {
        return 'Actualiza parcialmente un registro por id: solo cambian los campos enviados (un campo opcional puede ponerse en null). '
            . 'updated_at se actualiza automaticamente. Devuelve el registro actualizado.';
    }

    public function inputSchema(): array
    {
        $props = ['id' => $this->idProperty()];
        foreach ($this->records->entity()->writable() as $name => $col) {
            $props[$name] = $col->jsonSchema();
        }

        return ['type' => 'object', 'properties' => $props, 'required' => ['id'], 'minProperties' => 2, 'additionalProperties' => false];
    }

    public function requiredPermission(): Permission
    {
        return Permission::RecordsWrite;
    }

    public function annotations(): array
    {
        return ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false];
    }

    public function execute(array $arguments, Principal $principal): array
    {
        $id = $arguments['id'];
        unset($arguments['id']);

        return ['record' => $this->records->update($id, $arguments, $principal->userId)];
    }
}
