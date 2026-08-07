<?php

declare(strict_types=1);

namespace Authkit\Authkit\Events;

/**
 * Acknowledgment event, not a blocker: browsers may silently drop a cookie past
 * ~4KB, so the app would believe it set a session the browser actually rejected.
 * The documented fix is a Dashboard-side JWT-template change, out of this
 * package's reach — this event exists so an operator can see it happening.
 */
final readonly class SessionCookieOversized
{
    public function __construct(
        public int $bytes,
        public int $maxBytes,
    ) {}
}
