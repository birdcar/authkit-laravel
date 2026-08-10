<?php

declare(strict_types=1);

namespace Workbench\App\Listeners;

use Authkit\Authkit\Events\GenericWorkosEvent;
use Authkit\Authkit\Events\Workos\OrganizationMembershipCreated;
use Illuminate\Support\Facades\Log;

/**
 * The listener-recipe worked example: one listener, both branches of the
 * bounded-typing doctrine. Typed projection-feeding events (here,
 * organization_membership.created) get a dedicated handler; every event type
 * outside the bounded typed set arrives as GenericWorkosEvent through the
 * second handler. Both transports (the authkit:work poller and verified
 * webhooks) dispatch the same objects, so this one listener covers both.
 *
 * This is the shape `php artisan make:workos-listener LogWorkosEvents`
 * scaffolds for a consuming app (hand-written here — workbench files are
 * authored directly rather than generated, per this repo's convention; the
 * generator itself is covered by MakeWorkosListenerTest).
 *
 * Two handler methods instead of one __invoke because the two branches take
 * different event-type parameters — the same structure the package's own
 * InvalidateFgaCache listener uses for the identical problem.
 */
final class LogWorkosEvents
{
    public function handleMembershipCreated(OrganizationMembershipCreated $event): void
    {
        Log::info('workos membership created', [
            'resource_id' => $event->resourceId(),
            'payload' => $event->payload,
        ]);
    }

    public function handleGeneric(GenericWorkosEvent $event): void
    {
        Log::info('workos generic event', [
            'type' => $event->type,
            'payload' => $event->payload,
        ]);
    }
}
