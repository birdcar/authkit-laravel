<?php

declare(strict_types=1);

namespace Authkit\Authkit\Listeners\Workos;

use Authkit\Authkit\Events\Workos\OrganizationDomainDeleted;
use Authkit\Authkit\Models\WorkosOrganizationDomain;

/**
 * Delete-if-exists via the query builder — a .deleted for a domain never
 * projected locally is a no-op, not an exception.
 */
final class DeleteOrganizationDomainProjection
{
    public function handle(OrganizationDomainDeleted $event): void
    {
        WorkosOrganizationDomain::query()
            ->where('workos_id', $event->resourceId())
            ->delete();
    }
}
