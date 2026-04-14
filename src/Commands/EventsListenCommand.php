<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use WorkOS\AuthKit\Events\WorkOSEventReceived;
use WorkOS\AuthKit\Facades\WorkOS;
use WorkOS\AuthKit\Http\Controllers\WebhookController;
use WorkOS\AuthKit\Support\EventRouting;
use WorkOS\Resource\EventsOrder;

class EventsListenCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'workos:events-listen
        {--once : Poll a single page and exit}
        {--since= : ISO 8601 date to start from on first run (e.g. 2024-01-15)}
        {--sleep= : Seconds between polls when caught up (overrides config)}';

    /**
     * @var string
     */
    protected $description = 'Poll the WorkOS Events API for data sync';

    private bool $shouldStop = false;

    public function handle(EventRouting $routing): int
    {
        $eventTypes = $routing->eventTypesFor('events_api');

        if (empty($eventTypes)) {
            $this->warn('No event types configured for events_api routing. Check workos.events.routing config.');

            return self::SUCCESS;
        }

        $this->registerSignalHandlers();
        $this->info('Polling WorkOS Events API...');
        $this->line('Event types: '.implode(', ', $eventTypes));

        $cacheStore = $this->cacheStore();
        /** @var string $cacheKey */
        $cacheKey = config('workos.events.cache_key', 'workos.events.cursor');
        /** @var string|null $cursor */
        $cursor = $cacheStore->get($cacheKey);
        $pollInterval = (int) ($this->option('sleep') ?? config('workos.events.poll_interval', 5));
        $limit = (int) config('workos.events.limit', 100);

        $since = null;

        if ($cursor === null) {
            /** @var string|null $sinceOption */
            $sinceOption = $this->option('since');

            if ($sinceOption !== null) {
                $since = CarbonImmutable::parse($sinceOption)->utc()->format('Y-m-d\TH:i:s.v\Z');
                $this->info("First run — bootstrapping from --since={$since}");
            } else {
                $lookback = (int) config('workos.events.lookback_days', 7);
                $since = CarbonImmutable::now()->subDays($lookback)->utc()->format('Y-m-d\TH:i:s.v\Z');
                $this->info("First run — bootstrapping from {$lookback} days ago");
            }
        }

        $processed = 0;

        while (! $this->shouldStop) {
            try {
                $page = WorkOS::events()->listEvents(
                    after: $cursor,
                    limit: $limit,
                    order: EventsOrder::Asc,
                    events: $eventTypes,
                    rangeStart: $cursor === null ? $since : null,
                );
            } catch (\Exception $e) {
                $this->error("API request failed: {$e->getMessage()}");
                sleep(min($pollInterval * 2, 30));

                if ($this->option('once')) {
                    break;
                }

                $this->dispatchSignals();

                continue;
            }

            foreach ($page->data as $event) {
                $eventArray = $event->toArray();
                $this->processEvent($eventArray);
                $cursor = $eventArray['id'];
                $cacheStore->put($cacheKey, $cursor);
                $processed++;

                if ($this->shouldStop) {
                    break;
                }
            }

            $since = null;

            if ($this->option('once')) {
                break;
            }

            if (empty($page->data) || ! $page->hasMore()) {
                if ($processed > 0) {
                    $this->info("Processed {$processed} events. Caught up, sleeping {$pollInterval}s...");
                    $processed = 0;
                }
                sleep($pollInterval);
            } else {
                $cursor = $page->listMetadata['after'] ?? $cursor;
            }

            $this->dispatchSignals();
        }

        $this->info('Worker stopped gracefully.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function processEvent(array $event): void
    {
        /** @var string $eventType */
        $eventType = $event['event'] ?? 'unknown';
        /** @var array<string, mixed> $eventData */
        $eventData = $event['data'] ?? [];

        $this->line("<fg=green>Processing:</> {$eventType} ({$event['id']})");

        event(new WorkOSEventReceived($eventType, $eventData));

        $eventClass = WebhookController::EVENT_MAP[$eventType] ?? null;
        if ($eventClass !== null) {
            event(new $eventClass($eventData));
        }
    }

    private function registerSignalHandlers(): void
    {
        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn () => $this->shouldStop = true);
            pcntl_signal(SIGINT, fn () => $this->shouldStop = true);
        }
    }

    private function dispatchSignals(): void
    {
        if (extension_loaded('pcntl')) {
            pcntl_signal_dispatch();
        }
    }

    private function cacheStore(): Repository
    {
        /** @var string|null $store */
        $store = config('workos.events.cache_store');

        return Cache::store($store);
    }
}
