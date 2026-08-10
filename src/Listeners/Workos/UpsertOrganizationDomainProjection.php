<?php

declare(strict_types=1);

namespace Authkit\Authkit\Listeners\Workos;

use Authkit\Authkit\Events\Workos\OrganizationDomainCreated;
use Authkit\Authkit\Events\Workos\OrganizationDomainUpdated;
use Authkit\Authkit\Events\Workos\OrganizationDomainVerificationFailed;
use Authkit\Authkit\Events\Workos\OrganizationDomainVerified;
use Authkit\Authkit\Models\WorkosOrganizationDomain;

/**
 * Idempotent upsert keyed on workos_id. Only payload keys that are actually
 * present are written: the organization_domain.verification_failed payload
 * carries no top-level `state` at all (only `reason` + nested state), so a
 * blanket column overwrite would null out fields the event never spoke about.
 */
final class UpsertOrganizationDomainProjection
{
    public function handle(
        OrganizationDomainCreated|OrganizationDomainUpdated|OrganizationDomainVerified|OrganizationDomainVerificationFailed $event,
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
