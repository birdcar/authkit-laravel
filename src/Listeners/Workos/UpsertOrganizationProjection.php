<?php

declare(strict_types=1);

namespace Authkit\Authkit\Listeners\Workos;

use Authkit\Authkit\Events\Workos\OrganizationCreated;
use Authkit\Authkit\Events\Workos\OrganizationUpdated;
use Authkit\Authkit\Listeners\Workos\Concerns\ResolvesProjectionModels;

/**
 * Idempotent upsert keyed on workos_id. Saving through the model on purpose:
 * a row created here fires HasWorkosOrganization's create observer, whose job
 * sees workos_id already set and no-ops (the same round-trip the login-time
 * upsert relies on).
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

        $organization = $model::query()->firstWhere('workos_id', $event->resourceId())
            ?? $model::query()->newModelInstance();

        $attributes = ['workos_id' => $event->resourceId()];

        $name = $event->payload['name'] ?? null;

        if (is_string($name) && $name !== '') {
            $attributes['name'] = $name;
        }

        // forceFill: the org model is app-owned and its $fillable is unknowable
        // here (login-time upsert precedent).
        $organization->forceFill($attributes)->save();
    }
}
