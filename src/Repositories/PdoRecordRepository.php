<?php

declare(strict_types=1);

namespace McpPhp\Repositories;

use McpPhp\Database\Connection;
use McpPhp\Domain\ColumnType;
use McpPhp\Domain\EntityDefinition;
use McpPhp\Domain\Filter;
use McpPhp\Domain\Page;
use McpPhp\Domain\RecordCriteria;
use McpPhp\Exceptions\ConflictException;
use McpPhp\Exceptions\DatabaseException;

/**
 * Repositorio generico basado en PDO. Los NOMBRES de columna provienen siempre
 * de la EntityDefinition (lista blanca); los VALORES siempre van como parametros.
 */
final class PdoRecordRepository implements RecordRepositoryInterface
{
    public function __construct(private readonly Connection $db, private readonly EntityDefinition $entity)
    {
    }

    public function find(int $id, ?array $fields = null): ?array
    {
        $sql = sprintf('SELECT %s FROM %s WHERE %s = :id', $this->selectList($fields), $this->q($this->entity->table), $this->q($this->entity->primaryKey));
        $row = $this->db->run($sql, ['id' => $id])->fetch();

        return $row === false ? null : $this->normalize($row);
    }

    public function exists(int $id): bool
    {
        $sql = sprintf('SELECT 1 FROM %s WHERE %s = :id', $this->q($this->entity->table), $this->q($this->entity->primaryKey));

        return $this->db->run($sql, ['id' => $id])->fetchColumn() !== false;
    }

    public function paginate(RecordCriteria $c): Page
    {
        [$where, $params] = $this->buildWhere($c);
        $table = $this->q($this->entity->table);

        $total = (int) $this->db->run("SELECT COUNT(*) FROM $table$where", $params)->fetchColumn();

        $sortBy = $c->sortBy !== null && $this->entity->has($c->sortBy) ? $c->sortBy : $this->entity->defaultSort;
        $dir = strtoupper($c->sortDir) === 'DESC' ? 'DESC' : 'ASC';
        $perPage = max(1, min($c->perPage, RecordCriteria::MAX_PER_PAGE));
        $page = max(1, $c->page);

        $sql = sprintf(
            'SELECT %s FROM %s%s ORDER BY %s %s, %s ASC LIMIT %d OFFSET %d',
            $this->selectList($c->fields),
            $table,
            $where,
            $this->q($sortBy),
            $dir,
            $this->q($this->entity->primaryKey),
            $perPage,
            ($page - 1) * $perPage,
        );
        $rows = array_map($this->normalize(...), $this->db->run($sql, $params)->fetchAll());

        return new Page($rows, $total, $page, $perPage);
    }

    public function insert(array $data): int
    {
        $this->assertKnown(array_keys($data));
        try {
            if ($data === []) {
                $sql = sprintf('INSERT INTO %s () VALUES ()', $this->q($this->entity->table));
                $this->db->run($sql);
            } else {
                $cols = array_map($this->q(...), array_keys($data));
                $ph = array_map(static fn (string $k) => ':' . $k, array_keys($data));
                $sql = sprintf('INSERT INTO %s (%s) VALUES (%s)', $this->q($this->entity->table), implode(', ', $cols), implode(', ', $ph));
                $this->db->run($sql, $this->bindable($data));
            }
        } catch (DatabaseException $e) {
            $this->rethrowConflict($e);
        }

        return $this->db->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $this->assertKnown(array_keys($data));
        $sets = array_map(fn (string $k) => $this->q($k) . ' = :' . $k, array_keys($data));
        if ($this->entity->has($this->entity->updatedAtColumn)) {
            $sets[] = $this->q($this->entity->updatedAtColumn) . ' = CURRENT_TIMESTAMP';
        }
        $sql = sprintf('UPDATE %s SET %s WHERE %s = :__id', $this->q($this->entity->table), implode(', ', $sets), $this->q($this->entity->primaryKey));
        try {
            $this->db->run($sql, $this->bindable($data) + ['__id' => $id]);
        } catch (DatabaseException $e) {
            $this->rethrowConflict($e);
        }
    }

    public function delete(int $id): bool
    {
        $sql = sprintf('DELETE FROM %s WHERE %s = :id', $this->q($this->entity->table), $this->q($this->entity->primaryKey));

        return $this->db->run($sql, ['id' => $id])->rowCount() > 0;
    }

    /** @return array{0: string, 1: array<string, scalar|null>} */
    private function buildWhere(RecordCriteria $c): array
    {
        $clauses = [];
        $params = [];
        $n = 0;

        if ($c->text !== null && $c->text !== '' && $c->textColumns !== []) {
            $this->assertKnown($c->textColumns);
            $like = '%' . self::escapeLike($c->text) . '%';
            $parts = [];
            foreach ($c->textColumns as $col) {
                $key = 'p' . $n++;
                $params[$key] = $like;
                $parts[] = $this->q($col) . " LIKE :$key ESCAPE '\\\\'";
            }
            $clauses[] = '(' . implode(' OR ', $parts) . ')';
        }

        if ($c->name !== null && $c->name !== '') {
            $like = '%' . self::escapeLike($c->name) . '%';
            $k1 = 'p' . $n++;
            $k2 = 'p' . $n++;
            $k3 = 'p' . $n++;
            $params[$k1] = $params[$k2] = $params[$k3] = $like;
            $clauses[] = "(`first_name` LIKE :$k1 ESCAPE '\\\\' OR `last_name` LIKE :$k2 ESCAPE '\\\\' OR CONCAT(`first_name`, ' ', `last_name`) LIKE :$k3 ESCAPE '\\\\')";
        }

        foreach ($c->filters as $f) {
            $this->assertKnown([$f->column]);
            $col = $this->q($f->column);
            if ($f->operator === Filter::IS_NULL || $f->operator === Filter::NOT_NULL) {
                $clauses[] = "$col {$f->operator}";
                continue;
            }
            if (!in_array($f->operator, [Filter::EQ, Filter::GTE, Filter::LTE, Filter::LIKE], true)) {
                throw new \InvalidArgumentException('Operador no permitido.');
            }
            $key = 'p' . $n++;
            if ($f->operator === Filter::LIKE) {
                $clauses[] = "$col LIKE :$key ESCAPE '\\\\'";
                $params[$key] = '%' . self::escapeLike((string) $f->value) . '%';
            } else {
                $clauses[] = "$col {$f->operator} :$key";
                $params[$key] = is_bool($f->value) ? (int) $f->value : $f->value;
            }
        }

        return [$clauses === [] ? '' : ' WHERE ' . implode(' AND ', $clauses), $params];
    }

    /** Escapa los comodines de LIKE para que el texto del usuario se busque literalmente. */
    public static function escapeLike(string $text): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $text);
    }

    /** @param list<string>|null $fields */
    private function selectList(?array $fields): string
    {
        $fields = $fields === null || $fields === [] ? $this->entity->columnNames() : $fields;
        $this->assertKnown($fields);

        return implode(', ', array_map($this->q(...), $fields));
    }

    /** @param list<string> $names */
    private function assertKnown(array $names): void
    {
        foreach ($names as $n) {
            if (!is_string($n) || !$this->entity->has($n)) {
                throw new \InvalidArgumentException('Columna no permitida.');
            }
        }
    }

    private function q(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, scalar|null>
     */
    private function bindable(array $data): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            $out[$k] = is_bool($v) ? (int) $v : $v;
        }

        return $out;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function normalize(array $row): array
    {
        foreach ($row as $name => $value) {
            if ($value === null || !$this->entity->has($name)) {
                continue;
            }
            $row[$name] = match ($this->entity->column($name)->type) {
                ColumnType::Int => (int) $value,
                ColumnType::Decimal => (float) $value,
                ColumnType::Bool => (bool) $value,
                default => $value,
            };
        }

        return $row;
    }

    private function rethrowConflict(DatabaseException $e): never
    {
        $prev = $e->getPrevious();
        if ($prev instanceof \PDOException && (int) ($prev->errorInfo[1] ?? 0) === 1062) {
            throw new ConflictException('Ya existe un registro con ese valor unico (por ejemplo, el correo).', 0, $prev);
        }
        throw $e;
    }
}
