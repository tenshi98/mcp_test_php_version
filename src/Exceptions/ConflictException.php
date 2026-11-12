<?php

declare(strict_types=1);

namespace McpPhp\Exceptions;

class ConflictException extends McpPhpException
{
    public function errorCode(): string
    {
        return 'conflict';
    }
}
