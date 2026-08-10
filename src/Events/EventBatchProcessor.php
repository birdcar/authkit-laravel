<?php

declare(strict_types=1);

namespace Authkit\Authkit\Events;

use Authkit\Authkit\Contracts\WorkosClientManager;
use Authkit\Authkit\Models\WorkosEventCursor;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use WorkOS\Exception\BadRequestException;
use WorkOS\Exception\NotFoundException;
use WorkOS\PaginatedResponse;
use WorkOS\Resource\EventSchema;
use WorkOS\Resource\PaginationOrder;
use WorkOS\Service\Events;

/**
 * Fetches one page of WorkOS events, dispatches every event in it through
 * Laravel's event bus, and commits the cursor only after the ENTIRE page
 * dispatched without throwing.
 *
 * That ordering is the delivery contract: a crash (or a throwing listener)
 * after some events dispatched but before the cursor save replays the whole
 * batch on the next run — at-least-once, never at-most-once. Listeners must
 * therefore be idempotent.
 */
final class EventBatchProcessor
{
    public function __construct(
        private readonly WorkosClientManager $clients,
        private readonly WorkosEventMapper $mapper,
    ) {}

    /**
     * Fetch and dispatch the next batch, then commit the cursor.
     * Returns the number of events processed (0 = nothing new).
     *
     * @throws Throwable if any listener throws mid-batch — the cursor is
     *                   deliberately NOT committed in that case, so the
     *                   batch replays on the next run.
     */
    public function runOnce(WorkosEventCursor $cursor, int $batchLimit): int
    {
        $page = $this->fetchPage($cursor, $batchLimit);

        $dispatched = 0;
        $last = null;

        foreach ($page->data as $event) {
            if (! $event instanceof EventSchema) {
                continue;
            }

            event($this->mapper->map($event));

            $last = $event;
            $dispatched++;
        }

        if ($last !== null) {
            // Asc ordering makes "the page's last event" a valid forward-moving
            // cursor rather than an arbitrary page boundary.
            $cursor->commit($last->id, $last->createdAt);
        }

        return $dispatched;
    }

    private function fetchPage(WorkosEventCursor $cursor, int $batchLimit): PaginatedResponse
    {
        $events = $this->events();

        $lastEventId = $cursor->last_event_id;

        if (is_string($lastEventId) && $lastEventId !== '') {
            try {
                return $events->listEvents(
                    after: $lastEventId,
                    limit: $batchLimit,
                    order: PaginationOrder::Asc,
                );
            } catch (BadRequestException|NotFoundException) {
                // Stored cursor no longer resolvable (outside the retention
                // window). Fall through to the rangeStart path below. Anything
                // broader (5xx, timeout, rate limit) is deliberately NOT caught
                // here — a real outage must retry the same cursor, not be
                // misdiagnosed as staleness.
            }
        }

        return $events->listEvents(
            limit: $batchLimit,
            order: PaginationOrder::Asc,
            rangeStart: $this->rangeStart($cursor),
        );
    }

    private function rangeStart(WorkosEventCursor $cursor): string
    {
        $from = $cursor->last_event_occurred_at;

        if (! $from instanceof DateTimeImmutable) {
            $from = now()
                ->subMinutes((int) config('authkit.events.backfill_minutes', 5))
                ->toImmutable();
        }

        // WorkOS rejects date-only and microsecond formats — the API accepts
        // exactly this shape: 3-digit milliseconds, UTC, trailing Z.
        return $from->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }

    private function events(): Events
    {
        return $this->clients->client()->events();
    }
}
