<?php

declare(strict_types=1);

namespace Authkit\Authkit\Observers;

use Authkit\Authkit\Jobs\CreateWorkosOrganization;
use Authkit\Authkit\Jobs\DeleteWorkosOrganization;
use Authkit\Authkit\Jobs\UpdateWorkosOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * Dispatches Jobs rather than calling the SDK inline, so a slow or failing
 * WorkOS call never blocks the request that created the local row.
 */
final class WorkosOrganizationObserver
{
    public function created(Model $organization): void
    {
        $this->dispatch(new CreateWorkosOrganization($organization));
    }

    public function updated(Model $organization): void
    {
        // Only a name change is remote-visible state; workos_id writes are
        // the sync jobs' own quiet saves and everything else is app-local.
        if (! $organization->wasChanged('name')) {
            return;
        }

        $this->dispatch(new UpdateWorkosOrganization($organization));
    }

    public function deleted(Model $organization): void
    {
        if (! (bool) config('authkit.organization.delete_remote_on_delete', true)) {
            return;
        }

        // Captured at observer time, never re-derived from a possibly-deleted
        // local row — see DeleteWorkosOrganization for why it carries a string.
        $workosId = $organization->getAttribute('workos_id');

        if (! is_string($workosId) || $workosId === '') {
            return; // never synced remotely — nothing to delete there
        }

        $this->dispatch(new DeleteWorkosOrganization($workosId));
    }

    private function dispatch(object $job): void
    {
        if (config('authkit.organization.sync_mode', 'queue') === 'sync') {
            dispatch_sync($job);

            return;
        }

        dispatch($job);
    }
}
