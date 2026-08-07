<?php

declare(strict_types=1);

namespace Authkit\Authkit\Auth;

final readonly class RefreshOutcome
{
    public function __construct(
        public RefreshStatus $status,
        public ?string $sealedCookie = null,
    ) {}
}
