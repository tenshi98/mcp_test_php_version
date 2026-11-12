<?php

declare(strict_types=1);

namespace McpPhp\Exceptions;

class DatabaseException extends McpPhpException
{
    public function errorCode(): string
    {
        return 'database_error';
    }
}
