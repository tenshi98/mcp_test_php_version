<?php

declare(strict_types=1);

namespace McpPhp\Authorization;

enum Role: string
{
    case Admin = 'admin';
    case Editor = 'editor';
    case Viewer = 'viewer';

    /** @return list<Permission> */
    public function permissions(): array
    {
        return match ($this) {
            self::Admin => [Permission::RecordsRead, Permission::RecordsWrite, Permission::RecordsDelete],
            self::Editor => [Permission::RecordsRead, Permission::RecordsWrite],
            self::Viewer => [Permission::RecordsRead],
        };
    }
}
