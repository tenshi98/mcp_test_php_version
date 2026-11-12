<?php

declare(strict_types=1);

namespace McpPhp\Domain;

enum ColumnType: string
{
    case Int = 'int';
    case Decimal = 'decimal';
    case String = 'string';
    case Text = 'text';
    case Email = 'email';
    case Date = 'date';
    case DateTime = 'datetime';
    case Bool = 'bool';
    case Enum = 'enum';
}
