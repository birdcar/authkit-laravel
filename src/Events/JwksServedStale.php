<?php

declare(strict_types=1);

namespace Authkit\Authkit\Events;

final readonly class JwksServedStale
{
    public function __construct(
        public string $reason,
    ) {}
}
