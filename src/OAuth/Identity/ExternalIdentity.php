<?php

declare(strict_types=1);

namespace McpPhp\OAuth\Identity;

final class ExternalIdentity
{
    public function __construct(
        public readonly string $provider,
        public readonly string $subject,
        public readonly string $email,
        public readonly bool $emailVerified,
        public readonly string $name = '',
    ) {
    }
}
