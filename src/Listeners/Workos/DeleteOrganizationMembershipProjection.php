<?php

declare(strict_types=1);

namespace Authkit\Authkit\Listeners\Workos;

use Authkit\Authkit\Events\Workos\OrganizationMembershipDeleted;
use Authkit\Authkit\Models\WorkosMembership;

/**
 * Delete-if-exists via the query builder — a .deleted for a membership never
 * projected locally is a no-op, not an exception.
 */
final class DeleteOrganizationMembershipProjection
{
    public function handle(OrganizationMembershipDeleted $event): void
    {
        WorkosMembership::query()
            ->where('workos_id', $event->resourceId())
            ->delete();
    }
}
