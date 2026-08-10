<?php

declare(strict_types=1);

namespace Authkit\Authkit\Listeners\Workos;

use Authkit\Authkit\Events\Workos\OrganizationDeleted;
use Authkit\Authkit\Listeners\Workos\Concerns\ResolvesProjectionModels;

/**
 * Query-builder delete on purpose, beyond the delete-if-exists idempotency: a
 * mass delete skips Eloquent model events, so WorkosOrganizationObserver never
 * queues a DeleteWorkosOrganization call for an org WorkOS itself just deleted
 * (the event that brought us here) — no echo round-trip back to the API.
 */
final class DeleteOrganizationProjection
{
    use ResolvesProjectionModels;

    public function handle(OrganizationDeleted $event): void
    {
        $model = $this->organizationProjectionModel();

        if ($model === null) {
            return; // app hasn't wired an org model — org context isn't in use
        }

        $model::query()
            ->where('workos_id', $event->resourceId())
            ->delete();
    }
}
