<?php

declare(strict_types=1);

namespace Authkit\Authkit\Authorization\Listeners;

use Authkit\Authkit\Authorization\FgaChecker;
use Authkit\Authkit\Events\GenericWorkosEvent;
use Authkit\Authkit\Events\Workos\OrganizationMembershipCreated;
use Authkit\Authkit\Events\Workos\OrganizationMembershipDeleted;
use Authkit\Authkit\Events\Workos\OrganizationMembershipUpdated;

/**
 * Busts the FGA check cache when a WorkOS-side change can shift a check()
 * outcome. Listens on BOTH sidecar channels: the typed membership events
 * (memberships are a declared projection, so they get typed classes) and the
 * generic fallback (role/permission/group events are outside the bounded
 * typed set by contract decision — they only ever arrive as
 * GenericWorkosEvent). Invalidation is a single generation-counter bump, so
 * at-least-once delivery needs no idempotency care here.
 */
final class InvalidateFgaCache
{
    public function __construct(private readonly FgaChecker $fga) {}

    public function handleMembershipEvent(
        OrganizationMembershipCreated|OrganizationMembershipUpdated|OrganizationMembershipDeleted $event,
    ): void {
        $this->fga->forgetCache();
    }

    public function handleGenericEvent(GenericWorkosEvent $event): void
    {
        if ($this->isAuthorizationRelevant($event->type)) {
            $this->fga->forgetCache();
        }
    }

    /**
     * Confirmed against the live WorkOS Events API type catalog during spec
     * review (spec-phase-12 Open Item 1): there is NO role_assignment.*,
     * authorization_resource.*, or group_role_assignment.* event type —
     * assigning a role and editing the resource hierarchy produce no event at
     * all, so Dashboard-side edits of those kinds are bounded by cache TTL
     * only (spec-phase-12 Failure Mode 1b). The real, existing types that can
     * shift a check() outcome are role/permission DEFINITION changes and
     * group changes:
     */
    private function isAuthorizationRelevant(string $type): bool
    {
        return str_starts_with($type, 'role.')
            || str_starts_with($type, 'organization_role.')
            || str_starts_with($type, 'permission.')
            || str_starts_with($type, 'group.');
    }
}
