<?php

declare(strict_types=1);

namespace McpPhp\Exceptions;

class NotFoundException extends McpPhpException
{
    public function errorCode(): string
    {
        return 'not_found';
    }
}
