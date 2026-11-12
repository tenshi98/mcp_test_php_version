<?php

declare(strict_types=1);

namespace McpPhp\Authorization;

/** Permisos atomicos. El valor coincide con el scope OAuth equivalente. */
enum Permission: string
{
    case RecordsRead = 'records:read';
    case RecordsWrite = 'records:write';
    case RecordsDelete = 'records:delete';

    /** @return list<string> */
    public static function allScopes(): array
    {
        return array_map(static fn (self $p) => $p->value, self::cases());
    }
}
