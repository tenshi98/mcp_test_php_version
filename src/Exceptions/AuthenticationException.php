<?php

declare(strict_types=1);

namespace McpPhp\Exceptions;

class AuthenticationException extends McpPhpException
{
    public function errorCode(): string
    {
        return 'authentication_failed';
    }
}
