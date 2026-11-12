<?php

declare(strict_types=1);

namespace McpPhp\Exceptions;

class AuthorizationException extends McpPhpException
{
    public function errorCode(): string
    {
        return 'forbidden';
    }
}
