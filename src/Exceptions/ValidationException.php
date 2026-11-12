<?php

declare(strict_types=1);

namespace McpPhp\Exceptions;

final class ValidationException extends McpPhpException
{
    /** @param array<string, string> $errors campo => mensaje */
    public function __construct(private readonly array $errors, string $message = '')
    {
        parent::__construct($message !== '' ? $message : 'Parametros invalidos: ' . self::summarize($errors));
    }

    public function errorCode(): string
    {
        return 'validation_error';
    }

    public function details(): array
    {
        return ['errors' => $this->errors];
    }

    /** @return array<string, string> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @param array<string, string> $errors */
    private static function summarize(array $errors): string
    {
        $parts = [];
        foreach ($errors as $field => $msg) {
            $parts[] = "$field: $msg";
        }

        return implode('; ', $parts);
    }
}
