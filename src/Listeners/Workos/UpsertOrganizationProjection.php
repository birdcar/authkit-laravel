<?php

declare(strict_types=1);

namespace Authkit\Authkit\Listeners\Workos;

use Authkit\Authkit\Events\Workos\OrganizationCreated;
use Authkit\Authkit\Events\Workos\OrganizationUpdated;
use Authkit\Authkit\Listeners\Workos\Concerns\ResolvesProjectionModels;

/**
 * Idempotent upsert keyed on workos_id. A row CREATED here saves through the
 * model on purpose: HasWorkosOrganization's create observer fires and its job
 * sees workos_id already set and no-ops (the same round-trip the login-time
 * upsert relies on). A row UPDATED here saves quietly: this is remote-sourced
 * state, and a loud save would fire the trait's rename observer and echo the
 * name WorkOS just told us about straight back to WorkOS.
 */
final class UpsertOrganizationProjection
{
    use ResolvesProjectionModels;

    public function handle(OrganizationCreated|OrganizationUpdated $event): void
    {
        $model = $this->organizationProjectionModel();

        if ($model === null) {
            return; // app hasn't wired an org model — org context isn't in use
        }

        $organization = $model::query()->firstWhere('workos_id', $event->resourceId());
        $exists = $organization !== null;
        $organization ??= $model::query()->newModelInstance();

        $attributes = ['workos_id' => $event->resourceId()];

        $name = $event->payload['name'] ?? null;

        if (is_string($name) && $name !== '') {
            $attributes['name'] = $name;
        }

        // forceFill: the org model is app-owned and its $fillable is unknowable
        // here (login-time upsert precedent).
        $organization->forceFill($attributes);

        $exists ? $organization->saveQuietly() : $organization->save();
    }
}
