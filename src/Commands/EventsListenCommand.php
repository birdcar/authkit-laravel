<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use WorkOS\AuthKit\Events\WorkOSEventReceived;
use WorkOS\AuthKit\Http\Controllers\WebhookController;
use WorkOS\AuthKit\Support\EventRouting;

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
        /** @var string $apiKey */
        $apiKey = config('workos.api_key');

        if (empty($apiKey)) {
            $this->error('WorkOS API key not configured.');

            return self::FAILURE;
        }

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
            /** @var array<string, mixed> $params */
            $params = [
                'limit' => $limit,
                'order' => 'asc',
                'events' => $eventTypes,
            ];

            if ($cursor !== null) {
                $params['after'] = $cursor;
            } elseif ($since !== null) {
                $params['range_start'] = $since;
            }

            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->get('https://api.workos.com/events', $params);

            if (! $response->successful()) {
                $this->error("API request failed: {$response->status()} {$response->body()}");
                sleep(min($pollInterval * 2, 30));

                if ($this->option('once')) {
                    break;
                }

                $this->dispatchSignals();

                continue;
            }

            /** @var array<int, array<string, mixed>> $data */
            $data = $response->json('data', []);
            /** @var string|null $after */
            $after = $response->json('list_metadata.after');

            foreach ($data as $event) {
                $this->processEvent($event);
                /** @var string $eventId */
                $eventId = $event['id'];
                $cursor = $eventId;
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

            if (empty($data) || $after === null) {
                if ($processed > 0) {
                    $this->info("Processed {$processed} events. Caught up, sleeping {$pollInterval}s...");
                    $processed = 0;
                }
                sleep($pollInterval);
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
