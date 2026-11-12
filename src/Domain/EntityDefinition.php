<?php

declare(strict_types=1);

namespace McpPhp\Domain;

/**
 * Describe una tabla CRUD. Para exponer otra tabla basta con crear otra
 * definicion; repositorio, validador y servicio son genericos.
 */
final class EntityDefinition
{
    /** @var array<string, Column> */
    private array $columns = [];

    /** @param list<Column> $columns */
    public function __construct(
        public readonly string $name,
        public readonly string $table,
        array $columns,
        public readonly string $primaryKey = 'id',
        public readonly string $defaultSort = 'id',
        public readonly string $updatedAtColumn = 'updated_at',
    ) {
        foreach ($columns as $c) {
            $this->columns[$c->name] = $c;
        }
    }

    /** @return array<string, Column> */
    public function columns(): array
    {
        return $this->columns;
    }

    public function has(string $name): bool
    {
        return isset($this->columns[$name]);
    }

    public function column(string $name): Column
    {
        return $this->columns[$name] ?? throw new \InvalidArgumentException("Columna desconocida: $name");
    }

    /** @return list<string> */
    public function columnNames(): array
    {
        return array_keys($this->columns);
    }

    /** @return array<string, Column> columnas que el cliente puede escribir */
    public function writable(): array
    {
        return array_filter($this->columns, static fn (Column $c) => !$c->readonly);
    }

    /** @return list<string> */
    public function searchableColumns(): array
    {
        return array_values(array_map(
            static fn (Column $c) => $c->name,
            array_filter($this->columns, static fn (Column $c) => $c->searchable),
        ));
    }

    /** @return list<string> columnas de fecha (Date/DateTime) filtrables */
    public function dateColumns(): array
    {
        return array_values(array_map(
            static fn (Column $c) => $c->name,
            array_filter($this->columns, static fn (Column $c) => in_array($c->type, [ColumnType::Date, ColumnType::DateTime], true)),
        ));
    }

    /** @return list<string> columnas que pueden ser NULL */
    public function nullableColumns(): array
    {
        return array_values(array_map(
            static fn (Column $c) => $c->name,
            array_filter($this->columns, static fn (Column $c) => $c->nullable),
        ));
    }

    /** Definicion de la tabla de pruebas `sample_records`. */
    public static function sampleRecords(): self
    {
        $C = ColumnType::class;

        return new self('record', 'sample_records', [
            new Column('id', ColumnType::Int, readonly: true, description: 'Identificador autogenerado'),
            new Column('first_name', ColumnType::String, required: true, maxLength: 80, searchable: true, description: 'Nombre'),
            new Column('last_name', ColumnType::String, required: true, maxLength: 80, searchable: true, description: 'Apellido'),
            new Column('email', ColumnType::Email, required: true, maxLength: 190, searchable: true, description: 'Correo (unico)'),
            new Column('birth_date', ColumnType::Date, nullable: true, description: 'Fecha de nacimiento (YYYY-MM-DD)'),
            new Column('event_date', ColumnType::Date, nullable: true, description: 'Fecha del evento (YYYY-MM-DD)'),
            new Column('title', ColumnType::String, required: true, maxLength: 150, searchable: true, description: 'Titulo'),
            new Column('short_text', ColumnType::String, nullable: true, maxLength: 255, searchable: true, description: 'Texto corto'),
            new Column('description', ColumnType::Text, nullable: true, maxLength: 65000, searchable: true, description: 'Descripcion extensa'),
            new Column('quantity', ColumnType::Int, min: -2147483648, max: 2147483647, description: 'Cantidad entera (por defecto 0)'),
            new Column('price', ColumnType::Decimal, nullable: true, min: 0, max: 9999999999.99, scale: 2, description: 'Precio con 2 decimales'),
            new Column('rating', ColumnType::Decimal, nullable: true, min: 0, max: 99.99, scale: 2, description: 'Puntuacion 0-99.99'),
            new Column('is_active', ColumnType::Bool, description: 'Activo (por defecto true)'),
            new Column('category', ColumnType::String, required: true, maxLength: 50, description: 'Categoria'),
            new Column('status', ColumnType::Enum, values: ['draft', 'active', 'archived'], description: 'Estado (por defecto draft)'),
            new Column('notes', ColumnType::Text, nullable: true, maxLength: 65000, searchable: true, description: 'Observaciones'),
            new Column('created_at', ColumnType::DateTime, readonly: true, description: 'Fecha de creacion (automatica)'),
            new Column('updated_at', ColumnType::DateTime, readonly: true, description: 'Fecha de modificacion (automatica)'),
        ]);
    }
}
