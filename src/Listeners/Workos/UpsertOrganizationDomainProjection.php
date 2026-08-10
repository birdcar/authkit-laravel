<?php

declare(strict_types=1);

namespace Authkit\Authkit\Listeners\Workos;

use Authkit\Authkit\Events\Workos\OrganizationDomainCreated;
use Authkit\Authkit\Events\Workos\OrganizationDomainUpdated;
use Authkit\Authkit\Models\WorkosOrganizationDomain;

/**
 * Idempotent upsert keyed on workos_id. Only payload keys that are actually
 * present are written, so a sparse payload never nulls out fields the event
 * didn't speak about. Verification outcomes (verified/verification_failed)
 * are owned by UpdateOrganizationDomainVerificationState, which knows their
 * event semantics (state stamping, token clearing) — this listener owns row
 * existence for created/updated only.
 */
final class UpsertOrganizationDomainProjection
{
    public function handle(
        OrganizationDomainCreated|OrganizationDomainUpdated $event,
    ): void {
        $attributes = [];

        foreach (['organization_id', 'domain', 'state', 'verification_prefix', 'verification_token'] as $column) {
            $value = $event->payload[$column] ?? null;

            if (is_string($value)) {
                $attributes[$column] = $value;
            }
        }

        WorkosOrganizationDomain::query()->updateOrCreate(
            ['workos_id' => $event->resourceId()],
            $attributes,
        );
    }
}
