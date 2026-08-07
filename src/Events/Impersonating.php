<?php

declare(strict_types=1);

namespace Authkit\Authkit\Events;

use Illuminate\Contracts\Auth\Authenticatable;

final readonly class Impersonating
{
    /**
     * @param  array<string, mixed>|null  $impersonatorContext  Sealed-session `impersonator`
     *                                                          metadata (email/reason) when present — richer than the `act.sub` claim but
     *                                                          not cryptographically asserted, so treat `$impersonatorWorkosUserId` as
     *                                                          the authoritative signal.
     */
    public function __construct(
        public Authenticatable $user,
        public string $impersonatorWorkosUserId,
        public ?array $impersonatorContext,
    ) {}
}
