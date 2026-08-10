<?php

declare(strict_types=1);

namespace Authkit\Authkit\FeatureFlags;

/**
 * A Pennant scope normalized to the WorkOS resource it maps onto — the two
 * targets the WorkOS feature-flag list endpoints accept.
 */
final readonly class WorkosFeatureScope
{
    /**
     * @param  'user'|'organization'  $type
     * @param  string  $id  WorkOS ID, e.g. "user_01H..." or "org_01H..."
     */
    public function __construct(
        public string $type,
        public string $id,
    ) {}
}
