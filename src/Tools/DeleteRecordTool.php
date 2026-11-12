<?php

declare(strict_types=1);

namespace McpPhp\Tools;

use McpPhp\Authorization\Permission;
use McpPhp\Authorization\Principal;

final class DeleteRecordTool extends AbstractRecordTool
{
    public function name(): string
    {
        return 'delete_record';
    }

    public function title(): string
    {
        return 'Eliminar registro';
    }

    public function description(): string
    {
        return 'Elimina PERMANENTEMENTE un registro por id. Requiere permiso records:delete (rol admin). '
            . 'Sin confirm=true no borra nada: devuelve una vista previa del registro y pide confirmacion.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => $this->idProperty(),
                'confirm' => ['type' => 'boolean', 'default' => false, 'description' => 'Debe ser true para ejecutar el borrado.'],
            ],
            'required' => ['id'],
            'additionalProperties' => false,
        ];
    }

    public function requiredPermission(): Permission
    {
        return Permission::RecordsDelete;
    }

    public function annotations(): array
    {
        return ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false];
    }

    public function execute(array $arguments, Principal $principal): array
    {
        return $this->records->delete($arguments['id'], ($arguments['confirm'] ?? false) === true, $principal->userId);
    }
}
