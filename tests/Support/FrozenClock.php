<?php

declare(strict_types=1);

namespace McpPhp\Tests\Support;

use Psr\Clock\ClockInterface;

/** Reloj controlable para probar expiraciones sin esperar. */
final class FrozenClock implements ClockInterface
{
    public function __construct(private \DateTimeImmutable $now = new \DateTimeImmutable('2026-01-01 12:00:00', new \DateTimeZone('UTC')))
    {
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(string $modifier): void
    {
        $this->now = $this->now->modify($modifier);
    }
}
