<?php

declare(strict_types=1);

namespace McpPhp\Domain;

/** Criterios de consulta ya validados (todos los nombres de columna estan en lista blanca). */
final class RecordCriteria
{
    public const MAX_PER_PAGE = 100;

    /**
     * @param list<Filter>      $filters   se combinan con AND
     * @param list<string>      $textColumns columnas donde buscar $text (OR)
     * @param list<string>|null $fields    columnas a devolver (null = todas)
     */
    public function __construct(
        public readonly array $filters = [],
        public readonly ?string $text = null,
        public readonly array $textColumns = [],
        public readonly ?string $name = null,
        public readonly ?string $sortBy = null,
        public readonly string $sortDir = 'ASC',
        public readonly int $page = 1,
        public readonly int $perPage = 20,
        public readonly ?array $fields = null,
    ) {
    }
}
