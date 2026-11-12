<?php

declare(strict_types=1);

namespace McpPhp\Exceptions;

class ConfigurationException extends McpPhpException
{
    public function errorCode(): string
    {
        return 'configuration_error';
    }
}
