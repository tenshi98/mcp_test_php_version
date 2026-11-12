<?php

declare(strict_types=1);

namespace McpPhp\Domain;

final class Page
{
    /** @param list<array<string, mixed>> $items */
    public function __construct(
        public readonly array $items,
        public readonly int $total,
        public readonly int $page,
        public readonly int $perPage,
    ) {
    }

    public function totalPages(): int
    {
        return $this->perPage > 0 ? (int) max(1, ceil($this->total / $this->perPage)) : 1;
    }

    /** @return array<string, mixed> */
    public function toArray(string $itemsKey = 'records'): array
    {
        return [
            $itemsKey => $this->items,
            'pagination' => [
                'page' => $this->page,
                'per_page' => $this->perPage,
                'total' => $this->total,
                'total_pages' => $this->totalPages(),
                'has_next' => $this->page < $this->totalPages(),
            ],
        ];
    }
}
