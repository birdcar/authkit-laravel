<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\FGA;

readonly class FGAAccessResult
{
    public function __construct(
        public bool $allowed,
        public string $userId,
        public string $permission,
        public FGAResource $resource,
    ) {}
}
