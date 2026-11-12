<?php

declare(strict_types=1);

namespace McpPhp\Domain;

/** Metadatos de una columna: sirven para validar, filtrar, ordenar y generar el JSON Schema. */
final class Column
{
    /**
     * @param list<string> $values   valores permitidos si type = Enum
     * @param int|float|null $min    minimo (Int/Decimal)
     * @param int|float|null $max    maximo (Int/Decimal)
     */
    public function __construct(
        public readonly string $name,
        public readonly ColumnType $type,
        public readonly bool $required = false,
        public readonly bool $nullable = false,
        public readonly ?int $maxLength = null,
        public readonly int|float|null $min = null,
        public readonly int|float|null $max = null,
        public readonly int $scale = 2,
        public readonly array $values = [],
        public readonly bool $searchable = false,
        public readonly bool $readonly = false,
        public readonly string $description = '',
    ) {
    }

    /** Fragmento JSON Schema de la columna (para inputSchema de las herramientas). */
    public function jsonSchema(): array
    {
        $schema = match ($this->type) {
            ColumnType::Int => ['type' => 'integer'],
            ColumnType::Decimal => ['type' => 'number'],
            ColumnType::Bool => ['type' => 'boolean'],
            ColumnType::Date => ['type' => 'string', 'format' => 'date'],
            ColumnType::DateTime => ['type' => 'string'],
            ColumnType::Email => ['type' => 'string', 'format' => 'email'],
            ColumnType::Enum => ['type' => 'string', 'enum' => $this->values],
            default => ['type' => 'string'],
        };
        if ($this->maxLength !== null) {
            $schema['maxLength'] = $this->maxLength;
        }
        if ($this->min !== null) {
            $schema['minimum'] = $this->min;
        }
        if ($this->max !== null) {
            $schema['maximum'] = $this->max;
        }
        if ($this->nullable && $this->type !== ColumnType::Enum) {
            $schema['type'] = [$schema['type'], 'null'];
        }
        if ($this->description !== '') {
            $schema['description'] = $this->description;
        }

        return $schema;
    }
}
