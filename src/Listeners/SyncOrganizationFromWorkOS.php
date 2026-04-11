<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Listeners;

use WorkOS\AuthKit\Events\Sync\WorkOSOrganizationCreated;
use WorkOS\AuthKit\Events\Sync\WorkOSOrganizationUpdated;

class SyncOrganizationFromWorkOS
{
    public function handle(WorkOSOrganizationUpdated|WorkOSOrganizationCreated $event): void
    {
        /** @var class-string $organizationModel */
        $organizationModel = config('workos.organization_model');

        if (! method_exists($organizationModel, 'where')) {
            return;
        }

        $organization = $organizationModel::where('workos_id', $event->organizationId())->first();

        if ($organization === null) {
            return;
        }

        $organization->update([
            'name' => $event->name(),
        ]);
    }
}
