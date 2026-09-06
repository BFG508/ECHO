<?php

declare(strict_types=1);

namespace Echo\Security;

final class RateLimitResult
{
    public function __construct(
        public readonly bool $allowed,
        public readonly int $retryAfterSeconds,
    ) {}
}
