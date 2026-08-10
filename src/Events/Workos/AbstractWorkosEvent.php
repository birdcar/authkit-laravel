<?php

declare(strict_types=1);

namespace Authkit\Authkit\Events\Workos;

use DateTimeImmutable;
use RuntimeException;

/**
 * Shared shape for the bounded set of typed WorkOS events. Every subclass is a
 * one-line body distinguished only by class name — the class IS the event type,
 * so no per-type payload shape exists to drift. Listeners read fields out of
 * $payload directly.
 *
 * WorkOS delivers events at-least-once across both transports (poller and
 * webhooks), so every listener bound to these events must be idempotent —
 * upsert or delete-if-exists keyed on resourceId(), never on $id.
 */
abstract class AbstractWorkosEvent
{
    public function __construct(
        public readonly string $id,
        /** @var array<string, mixed> raw `data` from the WorkOS event */
        public readonly array $payload,
        public readonly DateTimeImmutable $occurredAt,
    ) {}

    /**
     * The WorkOS id of the resource this event describes (e.g. the User,
     * Organization, Domain, or Membership id) — NOT $this->id, which is the
     * Event object's own id (`event_01H...`) and is different on every
     * delivery, even for repeat deliveries describing the same resource.
     * The event payload always carries the resource's own `id` field.
     */
    public function resourceId(): string
    {
        $resourceId = $this->payload['id'] ?? null;

        if (! is_string($resourceId) || $resourceId === '') {
            throw new RuntimeException(sprintf(
                'The [%s] event payload (event %s) carries no string `id` — cannot resolve the resource it describes.',
                static::class,
                $this->id,
            ));
        }

        return $resourceId;
    }
}
