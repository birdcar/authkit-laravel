<?php

declare(strict_types=1);

namespace Authkit\Authkit\Events;

use DateTimeImmutable;

/**
 * Fallback event for every WorkOS event type outside the bounded typed set —
 * including entire product areas this package doesn't model (dsync.*, role.*,
 * ...) and any type WorkOS adds in the future. Deliberately NOT a subclass of
 * AbstractWorkosEvent: it carries $type (the raw WorkOS event string), which
 * the typed events don't need since their class name already encodes the type.
 *
 * Delivery is at-least-once — keep listeners idempotent, keyed on the
 * resource's own id ($payload['id']), never on $id.
 */
final class GenericWorkosEvent
{
    public function __construct(
        public readonly string $type,
        public readonly string $id,
        /** @var array<string, mixed> raw `data` from the WorkOS event */
        public readonly array $payload,
        public readonly DateTimeImmutable $occurredAt,
    ) {}
}
