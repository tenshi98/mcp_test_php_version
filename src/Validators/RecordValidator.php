<?php

declare(strict_types=1);

namespace McpPhp\Validators;

use McpPhp\Domain\Column;
use McpPhp\Domain\ColumnType;
use McpPhp\Domain\EntityDefinition;
use McpPhp\Exceptions\ValidationException;

/** Valida y normaliza los datos de escritura contra la EntityDefinition. */
final class RecordValidator
{
    public function __construct(private readonly EntityDefinition $entity)
    {
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed> datos normalizados listos para persistir
     */
    public function validateForCreate(array $data): array
    {
        return $this->validate($data, true);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function validateForUpdate(array $data): array
    {
        $clean = $this->validate($data, false);
        if ($clean === []) {
            throw new ValidationException(['fields' => 'Debe indicar al menos un campo a modificar.']);
        }

        return $clean;
    }

    public function validateId(mixed $id): int
    {
        if (is_string($id) && ctype_digit($id)) {
            $id = (int) $id;
        }
        if (!is_int($id) || $id < 1) {
            throw new ValidationException(['id' => 'Debe ser un entero positivo.']);
        }

        return $id;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function validate(array $data, bool $creating): array
    {
        $errors = [];
        $clean = [];
        $writable = $this->entity->writable();

        foreach (array_keys($data) as $key) {
            if (!isset($writable[$key])) {
                $errors[(string) $key] = $this->entity->has((string) $key) ? 'Campo de solo lectura.' : 'Campo desconocido.';
            }
        }

        foreach ($writable as $name => $col) {
            if (!array_key_exists($name, $data)) {
                if ($creating && $col->required) {
                    $errors[$name] = 'Campo obligatorio.';
                }
                continue;
            }
            $value = $data[$name];
            if ($value === null) {
                if (!$col->nullable) {
                    $errors[$name] = 'No admite NULL.';
                    continue;
                }
                $clean[$name] = null;
                continue;
            }
            try {
                $clean[$name] = $this->coerce($col, $value);
            } catch (\InvalidArgumentException $e) {
                $errors[$name] = $e->getMessage();
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $clean;
    }

    /** @throws \InvalidArgumentException con mensaje apto para el cliente */
    private function coerce(Column $col, mixed $value): mixed
    {
        switch ($col->type) {
            case ColumnType::Int:
                if (is_string($value) && preg_match('/^-?\d+$/', $value)) {
                    $value = (int) $value;
                }
                if (!is_int($value)) {
                    throw new \InvalidArgumentException('Debe ser un entero.');
                }
                $this->range($col, $value);

                return $value;

            case ColumnType::Decimal:
                if (is_string($value) && is_numeric($value)) {
                    $value = $value + 0;
                }
                if (!is_int($value) && !is_float($value)) {
                    throw new \InvalidArgumentException('Debe ser un numero.');
                }
                if (!is_finite((float) $value)) {
                    throw new \InvalidArgumentException('Numero no valido.');
                }
                $this->range($col, $value);
                if (abs(round((float) $value, $col->scale) - (float) $value) > 1e-9) {
                    throw new \InvalidArgumentException("Admite como maximo {$col->scale} decimales.");
                }

                return round((float) $value, $col->scale);

            case ColumnType::Bool:
                if (is_bool($value)) {
                    return $value;
                }
                if (in_array($value, [0, 1, '0', '1', 'true', 'false'], true)) {
                    return $value === 1 || $value === '1' || $value === 'true';
                }
                throw new \InvalidArgumentException('Debe ser booleano (true/false).');

            case ColumnType::Date:
                if (!is_string($value) || !self::isValidDate($value)) {
                    throw new \InvalidArgumentException('Fecha invalida; use el formato YYYY-MM-DD.');
                }

                return $value;

            case ColumnType::Enum:
                if (!is_string($value) || !in_array($value, $col->values, true)) {
                    throw new \InvalidArgumentException('Valor no permitido; use uno de: ' . implode(', ', $col->values) . '.');
                }

                return $value;

            case ColumnType::Email:
                $value = is_string($value) ? trim($value) : $value;
                if (!is_string($value) || $value === '' || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                    throw new \InvalidArgumentException('Correo electronico invalido.');
                }
                $this->length($col, $value);

                return strtolower($value);

            case ColumnType::String:
            case ColumnType::Text:
                if (!is_string($value)) {
                    throw new \InvalidArgumentException('Debe ser texto.');
                }
                $value = trim($value);
                if ($value === '' && !$col->nullable) {
                    throw new \InvalidArgumentException('No puede estar vacio.');
                }
                if (!mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value)) {
                    throw new \InvalidArgumentException('Contiene caracteres no permitidos.');
                }
                $this->length($col, $value);

                return $value;

            default:
                throw new \InvalidArgumentException('Tipo de columna no soportado para escritura.');
        }
    }

    private function range(Column $col, int|float $value): void
    {
        if ($col->min !== null && $value < $col->min) {
            throw new \InvalidArgumentException("Debe ser >= {$col->min}.");
        }
        if ($col->max !== null && $value > $col->max) {
            throw new \InvalidArgumentException("Debe ser <= {$col->max}.");
        }
    }

    private function length(Column $col, string $value): void
    {
        if ($col->maxLength !== null && mb_strlen($value) > $col->maxLength) {
            throw new \InvalidArgumentException("Longitud maxima {$col->maxLength} caracteres.");
        }
    }

    public static function isValidDate(string $value): bool
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $d !== false && $d->format('Y-m-d') === $value;
    }
}
