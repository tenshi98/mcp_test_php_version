<?php

declare(strict_types=1);

namespace McpPhp\Domain;

final class Filter
{
    public const EQ = '=';
    public const GTE = '>=';
    public const LTE = '<=';
    public const LIKE = 'LIKE';
    public const IS_NULL = 'IS NULL';
    public const NOT_NULL = 'IS NOT NULL';

    public function __construct(
        public readonly string $column,
        public readonly string $operator,
        public readonly int|float|string|bool|null $value = null,
    ) {
    }
}
