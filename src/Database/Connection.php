<?php

declare(strict_types=1);

namespace McpPhp\Database;

use McpPhp\Exceptions\DatabaseException;

/**
 * Conexion PDO perezosa. Siempre en modo excepcion, sin emulacion de
 * consultas preparadas (los parametros viajan separados del SQL).
 */
final class Connection
{
    private ?\PDO $pdo = null;

    public function __construct(
        private readonly string $dsn,
        private readonly string $user,
        #[\SensitiveParameter] private readonly string $password,
    ) {
    }

    public static function mysql(string $host, int $port, string $database, string $user, #[\SensitiveParameter] string $password): self
    {
        return new self(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $database),
            $user,
            $password,
        );
    }

    /** Util en pruebas unitarias: inyecta un PDO ya creado. */
    public static function fromPdo(\PDO $pdo): self
    {
        $c = new self('', '', '');
        $c->pdo = $pdo;

        return $c;
    }

    public function pdo(): \PDO
    {
        if ($this->pdo === null) {
            if (!extension_loaded('pdo_mysql')) {
                throw new DatabaseException('El servidor no tiene la extension PHP pdo_mysql.');
            }
            try {
                $this->pdo = new \PDO($this->dsn, $this->user, $this->password, [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                    \PDO::ATTR_EMULATE_PREPARES => false,
                    \PDO::ATTR_STRINGIFY_FETCHES => false,
                ]);
                $this->pdo->exec("SET time_zone = '+00:00'");
            } catch (\PDOException $e) {
                // El mensaje original puede contener host/usuario: solo va como $previous.
                throw new DatabaseException('No se pudo conectar con la base de datos.', 0, $e);
            }
        }

        return $this->pdo;
    }

    /**
     * Ejecuta una consulta preparada.
     *
     * @param array<int|string, scalar|null> $params
     */
    public function run(string $sql, array $params = []): \PDOStatement
    {
        try {
            $stmt = $this->pdo()->prepare($sql);
            foreach ($params as $key => $value) {
                $name = is_int($key) ? $key + 1 : (str_starts_with($key, ':') ? $key : ':' . $key);
                $type = match (true) {
                    $value === null => \PDO::PARAM_NULL,
                    is_bool($value) => \PDO::PARAM_BOOL,
                    is_int($value) => \PDO::PARAM_INT,
                    default => \PDO::PARAM_STR,
                };
                $stmt->bindValue($name, $value, $type);
            }
            $stmt->execute();

            return $stmt;
        } catch (\PDOException $e) {
            throw new DatabaseException('Error al ejecutar la operacion en la base de datos.', 0, $e);
        }
    }

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public function transaction(callable $fn): mixed
    {
        $pdo = $this->pdo();
        $pdo->beginTransaction();
        try {
            $result = $fn();
            $pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function lastInsertId(): int
    {
        return (int) $this->pdo()->lastInsertId();
    }
}
