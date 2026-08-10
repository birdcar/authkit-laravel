<?php

declare(strict_types=1);

namespace Authkit\Authkit\Listeners\Workos;

use Authkit\Authkit\Events\Workos\OrganizationMembershipCreated;
use Authkit\Authkit\Events\Workos\OrganizationMembershipUpdated;
use Authkit\Authkit\Models\WorkosMembership;

/**
 * Idempotent upsert keyed on workos_id (the membership's own om_... id) —
 * matching the columns the login-time membership upsert writes, so both paths
 * feed the same projection shape MembershipProjectionResolver reads.
 */
final class UpsertOrganizationMembershipProjection
{
    public function handle(OrganizationMembershipCreated|OrganizationMembershipUpdated $event): void
    {
        $attributes = [];

        foreach (['organization_id', 'user_id', 'status'] as $column) {
            $value = $event->payload[$column] ?? null;

            if (is_string($value)) {
                $attributes[$column] = $value;
            }
        }

        $role = $event->payload['role'] ?? null;

        if (is_array($role) && is_string($role['slug'] ?? null)) {
            $attributes['role'] = $role['slug'];
        }

        WorkosMembership::query()->updateOrCreate(
            ['workos_id' => $event->resourceId()],
            $attributes,
        );
    }
}
