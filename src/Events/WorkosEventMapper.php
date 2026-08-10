<?php

declare(strict_types=1);

namespace Authkit\Authkit\Events;

use Authkit\Authkit\Events\Workos\AbstractWorkosEvent;
use Authkit\Authkit\Events\Workos\OrganizationCreated;
use Authkit\Authkit\Events\Workos\OrganizationDeleted;
use Authkit\Authkit\Events\Workos\OrganizationDomainCreated;
use Authkit\Authkit\Events\Workos\OrganizationDomainDeleted;
use Authkit\Authkit\Events\Workos\OrganizationDomainUpdated;
use Authkit\Authkit\Events\Workos\OrganizationDomainVerificationFailed;
use Authkit\Authkit\Events\Workos\OrganizationDomainVerified;
use Authkit\Authkit\Events\Workos\OrganizationMembershipCreated;
use Authkit\Authkit\Events\Workos\OrganizationMembershipDeleted;
use Authkit\Authkit\Events\Workos\OrganizationMembershipUpdated;
use Authkit\Authkit\Events\Workos\OrganizationUpdated;
use Authkit\Authkit\Events\Workos\UserCreated;
use Authkit\Authkit\Events\Workos\UserDeleted;
use Authkit\Authkit\Events\Workos\UserUpdated;
use WorkOS\Resource\EventSchema;

/**
 * The single source of truth for "which WorkOS event types are typed". Both
 * transports — the authkit:work poller and the webhook controller — map through
 * this one class, which is what makes "same Laravel event objects across
 * transports" structural rather than a convention someone can drift from.
 *
 * This is also the ONE events-pipeline class allowed to import a \WorkOS\ type:
 * it is the deliberate boundary between the SDK's wire shape and the package's
 * domain shape (the same role the guard layer gives the SDK's SessionManager).
 */
final class WorkosEventMapper
{
    /** @var array<string, class-string<AbstractWorkosEvent>> */
    private const array TYPE_MAP = [
        'user.created' => UserCreated::class,
        'user.updated' => UserUpdated::class,
        'user.deleted' => UserDeleted::class,
        'organization.created' => OrganizationCreated::class,
        'organization.updated' => OrganizationUpdated::class,
        'organization.deleted' => OrganizationDeleted::class,
        'organization_domain.created' => OrganizationDomainCreated::class,
        'organization_domain.updated' => OrganizationDomainUpdated::class,
        'organization_domain.deleted' => OrganizationDomainDeleted::class,
        'organization_domain.verified' => OrganizationDomainVerified::class,
        'organization_domain.verification_failed' => OrganizationDomainVerificationFailed::class,
        'organization_membership.created' => OrganizationMembershipCreated::class,
        'organization_membership.updated' => OrganizationMembershipUpdated::class,
        'organization_membership.deleted' => OrganizationMembershipDeleted::class,
    ];

    public function map(EventSchema $event): AbstractWorkosEvent|GenericWorkosEvent
    {
        $class = self::TYPE_MAP[$event->event] ?? null;

        if ($class === null) {
            // Unknown and out-of-scope types (dsync.*, role.*, anything WorkOS
            // ships tomorrow) flow through untyped rather than throwing — the
            // pipeline must never crash on a type it doesn't model.
            return new GenericWorkosEvent(
                type: $event->event,
                id: $event->id,
                payload: $event->data,
                occurredAt: $event->createdAt,
            );
        }

        return new $class(
            id: $event->id,
            payload: $event->data,
            occurredAt: $event->createdAt,
        );
    }
}
