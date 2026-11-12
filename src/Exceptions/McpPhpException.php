<?php

declare(strict_types=1);

namespace McpPhp\Exceptions;

/**
 * Base de todas las excepciones controladas del proyecto.
 *
 * El mensaje de una excepcion hija es SIEMPRE seguro de mostrar al cliente
 * (no contiene SQL, rutas ni detalles internos). Los detalles tecnicos se
 * pasan como $previous y solo se escriben en el log.
 */
abstract class McpPhpException extends \RuntimeException
{
    /** Codigo estable legible por maquina (p. ej. "validation_error"). */
    abstract public function errorCode(): string;

    /** @return array<string, mixed> Detalles adicionales seguros para el cliente. */
    public function details(): array
    {
        return [];
    }
}
