<?php

declare(strict_types=1);

namespace McpPhp\Validators;

use McpPhp\Domain\ColumnType;
use McpPhp\Domain\EntityDefinition;
use McpPhp\Domain\Filter;
use McpPhp\Domain\RecordCriteria;
use McpPhp\Exceptions\ValidationException;

/** Convierte los argumentos de list_records / search_records en un RecordCriteria validado. */
final class CriteriaFactory
{
    public function __construct(private readonly EntityDefinition $entity)
    {
    }

    /** @param array<string, mixed> $args */
    public function fromArguments(array $args, bool $search): RecordCriteria
    {
        $errors = [];
        $filters = [];

        $page = $this->intArg($args, 'page', 1, 1, 1_000_000, $errors);
        $perPage = $this->intArg($args, 'per_page', 20, 1, RecordCriteria::MAX_PER_PAGE, $errors);

        $sortBy = $args['sort_by'] ?? null;
        if ($sortBy !== null && (!is_string($sortBy) || !$this->entity->has($sortBy))) {
            $errors['sort_by'] = 'Columna no valida. Opciones: ' . implode(', ', $this->entity->columnNames());
            $sortBy = null;
        }
        $sortDir = strtoupper((string) ($args['sort_dir'] ?? 'ASC'));
        if (!in_array($sortDir, ['ASC', 'DESC'], true)) {
            $errors['sort_dir'] = 'Use asc o desc.';
            $sortDir = 'ASC';
        }

        $fields = null;
        if (isset($args['fields'])) {
            if (!is_array($args['fields']) || !array_is_list($args['fields']) || $args['fields'] === []) {
                $errors['fields'] = 'Debe ser una lista no vacia de nombres de campo.';
            } else {
                foreach ($args['fields'] as $f) {
                    if (!is_string($f) || !$this->entity->has($f)) {
                        $errors['fields'] = 'Campo desconocido: ' . (is_string($f) ? mb_substr($f, 0, 40) : gettype($f));
                        break;
                    }
                }
                if (!isset($errors['fields'])) {
                    $fields = array_values(array_unique($args['fields']));
                }
            }
        }

        $text = null;
        $name = null;
        if ($search) {
            $text = $this->stringArg($args, 'text', 200, $errors);
            $name = $this->stringArg($args, 'name', 160, $errors);

            if (isset($args['category'])) {
                $filters[] = new Filter('category', Filter::EQ, $this->stringArg($args, 'category', 50, $errors));
            }
            if (isset($args['status'])) {
                $status = $args['status'];
                if (!is_string($status) || !in_array($status, $this->entity->column('status')->values, true)) {
                    $errors['status'] = 'Use uno de: ' . implode(', ', $this->entity->column('status')->values);
                } else {
                    $filters[] = new Filter('status', Filter::EQ, $status);
                }
            }
            if (isset($args['is_active'])) {
                if (!is_bool($args['is_active'])) {
                    $errors['is_active'] = 'Debe ser booleano.';
                } else {
                    $filters[] = new Filter('is_active', Filter::EQ, $args['is_active']);
                }
            }
            foreach (['quantity' => ColumnType::Int, 'price' => ColumnType::Decimal, 'rating' => ColumnType::Decimal] as $col => $type) {
                foreach (['min' => Filter::GTE, 'max' => Filter::LTE] as $prefix => $op) {
                    $key = "{$prefix}_$col";
                    if (!isset($args[$key])) {
                        continue;
                    }
                    $v = $args[$key];
                    $ok = $type === ColumnType::Int ? is_int($v) : (is_int($v) || is_float($v));
                    if (!$ok) {
                        $errors[$key] = $type === ColumnType::Int ? 'Debe ser entero.' : 'Debe ser numerico.';
                    } else {
                        $filters[] = new Filter($col, $op, $v);
                    }
                }
            }

            $dateField = $args['date_field'] ?? 'event_date';
            if (!is_string($dateField) || !in_array($dateField, $this->entity->dateColumns(), true)) {
                $errors['date_field'] = 'Use uno de: ' . implode(', ', $this->entity->dateColumns());
            } else {
                foreach (['date_from' => Filter::GTE, 'date_to' => Filter::LTE] as $key => $op) {
                    if (!isset($args[$key])) {
                        continue;
                    }
                    $d = $args[$key];
                    if (!is_string($d) || !RecordValidator::isValidDate($d)) {
                        $errors[$key] = 'Fecha invalida; use YYYY-MM-DD.';
                        continue;
                    }
                    $isDateTime = $this->entity->column($dateField)->type === ColumnType::DateTime;
                    $filters[] = new Filter(
                        $dateField,
                        $op,
                        $isDateTime ? ($op === Filter::GTE ? "$d 00:00:00" : "$d 23:59:59") : $d,
                    );
                }
            }

            foreach (['null_fields' => Filter::IS_NULL, 'not_null_fields' => Filter::NOT_NULL] as $key => $op) {
                if (!isset($args[$key])) {
                    continue;
                }
                $allowed = $this->entity->nullableColumns();
                if (!is_array($args[$key]) || array_diff($args[$key], $allowed) !== []) {
                    $errors[$key] = 'Lista de campos opcionales validos: ' . implode(', ', $allowed);
                    continue;
                }
                foreach ($args[$key] as $c) {
                    $filters[] = new Filter($c, $op);
                }
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return new RecordCriteria(
            filters: $filters,
            text: $text,
            textColumns: $text !== null ? $this->entity->searchableColumns() : [],
            name: $name,
            sortBy: $sortBy,
            sortDir: $sortDir,
            page: $page,
            perPage: $perPage,
            fields: $fields,
        );
    }

    /** @param array<string, mixed> $args @param array<string, string> $errors */
    private function intArg(array $args, string $key, int $default, int $min, int $max, array &$errors): int
    {
        if (!isset($args[$key])) {
            return $default;
        }
        $v = $args[$key];
        if (!is_int($v) || $v < $min || $v > $max) {
            $errors[$key] = "Debe ser un entero entre $min y $max.";

            return $default;
        }

        return $v;
    }

    /** @param array<string, mixed> $args @param array<string, string> $errors */
    private function stringArg(array $args, string $key, int $maxLen, array &$errors): ?string
    {
        if (!isset($args[$key])) {
            return null;
        }
        $v = $args[$key];
        if (!is_string($v) || mb_strlen($v) > $maxLen) {
            $errors[$key] = "Debe ser texto de hasta $maxLen caracteres.";

            return null;
        }
        $v = trim($v);

        return $v === '' ? null : $v;
    }
}
