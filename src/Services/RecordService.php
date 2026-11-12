<?php

declare(strict_types=1);

namespace McpPhp\Services;

use McpPhp\Domain\EntityDefinition;
use McpPhp\Exceptions\NotFoundException;
use McpPhp\Logging\SecurityLogger;
use McpPhp\Repositories\RecordRepositoryInterface;
use McpPhp\Validators\CriteriaFactory;
use McpPhp\Validators\RecordValidator;

/** Logica de negocio del CRUD (independiente de MCP y de PDO). */
final class RecordService
{
    public function __construct(
        private readonly EntityDefinition $entity,
        private readonly RecordRepositoryInterface $repository,
        private readonly RecordValidator $validator,
        private readonly CriteriaFactory $criteria,
        private readonly SecurityLogger $audit,
    ) {
    }

    public function entity(): EntityDefinition
    {
        return $this->entity;
    }

    /** @param array<string, mixed> $args @return array<string, mixed> */
    public function list(array $args): array
    {
        return $this->repository->paginate($this->criteria->fromArguments($args, false))->toArray();
    }

    /** @param array<string, mixed> $args @return array<string, mixed> */
    public function search(array $args): array
    {
        return $this->repository->paginate($this->criteria->fromArguments($args, true))->toArray();
    }

    /**
     * @param list<string>|null $fields
     * @return array<string, mixed>
     */
    public function get(mixed $id, ?array $fields = null): array
    {
        $id = $this->validator->validateId($id);
        if ($fields !== null) {
            $fields = $this->criteria->fromArguments(['fields' => $fields], false)->fields;
        }

        return $this->repository->find($id, $fields) ?? throw new NotFoundException("No existe el registro con id $id.");
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function create(array $data, ?int $actorId = null): array
    {
        $clean = $this->validator->validateForCreate($data);
        $id = $this->repository->insert($clean);
        $this->audit->info('record.created', $actorId, ['id' => $id, 'entity' => $this->entity->name]);

        return $this->get($id);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function update(mixed $id, array $data, ?int $actorId = null): array
    {
        $id = $this->validator->validateId($id);
        $clean = $this->validator->validateForUpdate($data);
        if (!$this->repository->exists($id)) {
            throw new NotFoundException("No existe el registro con id $id.");
        }
        $this->repository->update($id, $clean);
        $this->audit->info('record.updated', $actorId, ['id' => $id, 'fields' => array_keys($clean), 'entity' => $this->entity->name]);

        return $this->get($id);
    }

    /**
     * Eliminacion en dos pasos: sin $confirm solo devuelve una vista previa del registro
     * y NO borra nada.
     *
     * @return array<string, mixed>
     */
    public function delete(mixed $id, bool $confirm, ?int $actorId = null): array
    {
        $id = $this->validator->validateId($id);
        $record = $this->repository->find($id) ?? throw new NotFoundException("No existe el registro con id $id.");

        if (!$confirm) {
            return [
                'deleted' => false,
                'confirmation_required' => true,
                'message' => "No se elimino nada. Para borrar el registro $id vuelva a llamar con confirm=true.",
                'record' => $record,
            ];
        }

        $deleted = $this->repository->delete($id);
        $this->audit->warning('record.deleted', $actorId, ['id' => $id, 'entity' => $this->entity->name]);

        return ['deleted' => $deleted, 'id' => $id, 'record' => $record];
    }
}
