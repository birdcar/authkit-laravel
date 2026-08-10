<?php

declare(strict_types=1);

namespace Authkit\Authkit\Models;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * The events poller's durable cursor: one row, read at the top of every poll
 * cycle and written once per fully-dispatched batch. Committing AFTER dispatch
 * is what makes delivery at-least-once — a crash between dispatch and commit
 * replays the batch on restart, and never silently drops it.
 *
 * @property string|null $last_event_id
 * @property CarbonImmutable|null $last_event_occurred_at
 */
final class WorkosEventCursor extends Model
{
    protected $table = 'workos_event_cursor';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'last_event_occurred_at' => 'immutable_datetime',
        ];
    }

    public static function current(): self
    {
        // Self-initializing: firstOrCreate([]) always returns the single row,
        // creating it on first boot so no seeder is required.
        return self::query()->firstOrCreate([]);
    }

    public function commit(string $eventId, DateTimeImmutable $occurredAt): void
    {
        $this->forceFill([
            'last_event_id' => $eventId,
            'last_event_occurred_at' => $occurredAt,
        ])->save();
    }
}
