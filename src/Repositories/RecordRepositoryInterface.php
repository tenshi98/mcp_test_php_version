<?php

declare(strict_types=1);

namespace McpPhp\Repositories;

use McpPhp\Domain\Page;
use McpPhp\Domain\RecordCriteria;

interface RecordRepositoryInterface
{
    /**
     * @param list<string>|null $fields
     * @return array<string, mixed>|null
     */
    public function find(int $id, ?array $fields = null): ?array;

    public function exists(int $id): bool;

    public function paginate(RecordCriteria $criteria): Page;

    /** @param array<string, mixed> $data @return int id generado */
    public function insert(array $data): int;

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void;

    public function delete(int $id): bool;
}
