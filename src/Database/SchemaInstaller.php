<?php

declare(strict_types=1);

namespace McpPhp\Database;

use McpPhp\Exceptions\DatabaseException;

/**
 * Ejecuta los scripts SQL de database/ en orden (01_*.sql, 02_*.sql ...).
 */
final class SchemaInstaller
{
    public function __construct(private readonly Connection $connection, private readonly string $sqlDir)
    {
    }

    /** @return list<string> scripts disponibles ordenados (sin 00_create_database) */
    public function scripts(bool $withSeeds = true): array
    {
        $files = glob(rtrim($this->sqlDir, '/') . '/[0-9][0-9]_*.sql') ?: [];
        sort($files);

        return array_values(array_filter($files, static function (string $f) use ($withSeeds): bool {
            $name = basename($f);
            if (str_starts_with($name, '00_')) {
                return false;
            }

            return $withSeeds || !str_contains($name, 'seed');
        }));
    }

    /** @return list<string> nombres de los scripts ejecutados */
    public function install(bool $withSeeds = true): array
    {
        $done = [];
        foreach ($this->scripts($withSeeds) as $file) {
            $this->runFile($file);
            $done[] = basename($file);
        }

        return $done;
    }

    public function dropAll(): void
    {
        $pdo = $this->connection->pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN) as $table) {
            $pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '``', (string) $table) . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    public function runFile(string $file): void
    {
        $sql = file_get_contents($file);
        if ($sql === false) {
            throw new DatabaseException('No se pudo leer el script SQL ' . basename($file));
        }
        foreach (self::splitStatements($sql) as $statement) {
            try {
                $this->connection->pdo()->exec($statement);
            } catch (\PDOException $e) {
                throw new DatabaseException('Fallo el script ' . basename($file) . ': ' . $e->getMessage(), 0, $e);
            }
        }
    }

    /**
     * Divide un script en sentencias. Respeta literales entre comillas
     * y elimina comentarios de linea "-- ".
     *
     * @return list<string>
     */
    public static function splitStatements(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $quote = null;
        $len = strlen($sql);
        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];
            if ($quote !== null) {
                $buffer .= $ch;
                if ($ch === '\\' && $i + 1 < $len) {
                    $buffer .= $sql[++$i];
                } elseif ($ch === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($ch === '-' && ($sql[$i + 1] ?? '') === '-' && in_array($sql[$i + 2] ?? ' ', [' ', "\t", "\n", "\r"], true)) {
                $nl = strpos($sql, "\n", $i);
                $i = $nl === false ? $len : $nl;
                $buffer .= "\n";
                continue;
            }
            if ($ch === "'" || $ch === '"' || $ch === '`') {
                $quote = $ch;
                $buffer .= $ch;
                continue;
            }
            if ($ch === ';') {
                if (trim($buffer) !== '') {
                    $statements[] = trim($buffer);
                }
                $buffer = '';
                continue;
            }
            $buffer .= $ch;
        }
        if (trim($buffer) !== '') {
            $statements[] = trim($buffer);
        }

        return $statements;
    }
}
