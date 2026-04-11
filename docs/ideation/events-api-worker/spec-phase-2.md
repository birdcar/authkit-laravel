# Implementation Spec: Events API Worker - Phase 2

**Contract**: ./contract.md
**Estimated Effort**: L

## Technical Approach

Phase 2 rewrites the `EventsListenCommand` as a proper REST polling worker. The command polls `GET /events` with cursor-based pagination, persists the cursor in Laravel Cache, and dispatches typed events through the same system as webhooks. It runs as a long-lived process (like `queue:work`) with graceful signal handling, configurable poll intervals, and automatic backoff when caught up.

Since the WorkOS PHP SDK has no Events service class, the command calls the API directly via Laravel's `Http` facade with bearer token auth. The `EventRouting` service from Phase 1 provides the filtered list of event types to request.

The worker follows WorkOS's recommended pattern: single worker, sequential processing, cursor persisted after each event. When a page returns data, the next page is fetched immediately. When a page returns empty, the worker sleeps for the configured poll interval before checking again.

## Feedback Strategy

**Inner-loop command**: `composer test -- --filter=EventsListenCommandTest`

**Playground**: Test suite with `Http::fake()` and `Cache::fake()` — the command's behavior is entirely testable through Laravel's HTTP and cache fakes without hitting the real API.

**Why this approach**: The command is a loop over HTTP calls with cache persistence. Faked HTTP responses let us simulate pagination, empty results, and errors precisely.

## File Changes

### New Files

| File Path | Purpose |
|---|---|
| `tests/Feature/EventsListenCommandTest.php` | Tests for the rewritten polling worker |

### Modified Files

| File Path | Changes |
|---|---|
| `src/Commands/EventsListenCommand.php` | Complete rewrite: REST polling, cursor persistence, signal handling, --once/--since/--sleep options |
| `src/WorkOSServiceProvider.php` | No changes needed — command is already registered |

### Deleted Files

None — the command file is rewritten in place.

## Implementation Details

### EventsListenCommand Rewrite

**Pattern to follow**: `src/Commands/SyncUsersCommand.php` for cursor-based pagination loop; Laravel's `queue:work` command for signal handling and long-lived process patterns

**Overview**: Complete rewrite of the existing SSE-based command into a REST polling worker with cursor persistence, graceful shutdown, and configurable behavior.

```php
<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use WorkOS\AuthKit\Events\WebhookReceived;
use WorkOS\AuthKit\Http\Controllers\WebhookController;
use WorkOS\AuthKit\Support\EventRouting;

class EventsListenCommand extends Command
{
    protected $signature = 'workos:events-listen
        {--once : Poll a single page and exit}
        {--since= : ISO 8601 date to start from on first run (e.g. 2024-01-15)}
        {--sleep= : Seconds between polls when caught up (overrides config)}';

    protected $description = 'Poll the WorkOS Events API for data sync';

    private bool $shouldStop = false;

    public function handle(EventRouting $routing): int
    {
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
        $this->line('Event types: ' . implode(', ', $eventTypes));

        $cacheStore = $this->cacheStore();
        $cacheKey = config('workos.events.cache_key', 'workos.events.cursor');
        $cursor = $cacheStore->get($cacheKey);
        $pollInterval = (int) ($this->option('sleep') ?? config('workos.events.poll_interval', 5));
        $limit = (int) config('workos.events.limit', 100);

        // First run bootstrap
        if ($cursor === null) {
            $since = $this->option('since');
            if ($since !== null) {
                $this->info("First run — bootstrapping from --since={$since}");
            } else {
                $lookback = (int) config('workos.events.lookback_days', 7);
                $since = CarbonImmutable::now()->subDays($lookback)->toIso8601String();
                $this->info("First run — bootstrapping from {$lookback} days ago");
            }
        }

        $processed = 0;

        while (! $this->shouldStop) {
            $params = [
                'limit' => $limit,
                'order' => 'asc',
                'events' => $eventTypes,
            ];

            if ($cursor !== null) {
                $params['after'] = $cursor;
            } elseif (isset($since)) {
                $params['range_start'] = $since;
            }

            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->get('https://api.workos.com/events', $params);

            if (! $response->successful()) {
                $this->error("API request failed: {$response->status()} {$response->body()}");
                sleep(min($pollInterval * 2, 30));
                continue;
            }

            $data = $response->json('data', []);
            $after = $response->json('list_metadata.after');

            foreach ($data as $event) {
                $this->processEvent($event);
                $cursor = $event['id'];
                $cacheStore->put($cacheKey, $cursor);
                $processed++;

                if ($this->shouldStop) {
                    break;
                }
            }

            // Clear since after first successful request
            unset($since);

            if ($this->option('once')) {
                break;
            }

            if (empty($data) || $after === null) {
                // Caught up — sleep before next poll
                if ($processed > 0) {
                    $this->info("Processed {$processed} events. Caught up, sleeping {$pollInterval}s...");
                    $processed = 0;
                }
                sleep($pollInterval);
            }
            // More pages available — continue immediately

            $this->dispatchSignals();
        }

        $this->info('Worker stopped gracefully.');
        return self::SUCCESS;
    }

    private function processEvent(array $event): void
    {
        $eventType = $event['event'] ?? 'unknown';
        $eventData = $event['data'] ?? [];

        $this->line("<fg=green>Processing:</> {$eventType} ({$event['id']})");

        event(new WebhookReceived($eventType, $eventData));

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

    private function cacheStore(): \Illuminate\Contracts\Cache\Repository
    {
        $store = config('workos.events.cache_store');

        return Cache::store($store);
    }
}
```

**Key decisions**:

- **`Http::withToken()` over SDK `Client`**: The SDK's `Client::request()` uses static methods and its own curl client. Laravel's `Http` facade is testable with `Http::fake()`, which is essential for this command's test suite.
- **Cache over database**: Cursor is a single string value. Cache is simpler, doesn't require a migration, and supports file/redis/memcached/database backends already.
- **`pcntl_async_signals(true)`**: Required for signals to fire without explicit `pcntl_signal_dispatch()` calls during blocking `sleep()`. We also call `dispatchSignals()` in the loop for non-sleep scenarios.
- **`extension_loaded('pcntl')` guard**: pcntl is not available in all environments (notably Windows, some shared hosts). The worker still functions without it — it just won't handle signals gracefully.
- **Cursor persisted after each event, not after each page**: Follows WorkOS recommendation. If the process dies mid-page, we resume from the last processed event, not the start of the page.
- **`--once` exits after one page**: Useful for cron-based setups where the system scheduler handles the interval, or for testing.
- **Error backoff**: On API failure, sleep for `min(poll_interval * 2, 30)` seconds. Simple doubling with a cap. No exponential backoff — the worker is persistent and will retry on the next iteration.
- **`unset($since)` after first request**: The `range_start` parameter is only used for bootstrapping. After the first successful request, pagination switches to cursor-based `after` parameter.

**Implementation steps**:

1. Delete the entire body of the existing `EventsListenCommand`
2. Rewrite with the new signature, properties, and `handle()` method
3. Add `EventRouting` type-hint to `handle()` for automatic injection
4. Implement `processEvent()` — identical to current, but with event ID in output
5. Implement `registerSignalHandlers()` and `dispatchSignals()`
6. Implement `cacheStore()` helper
7. Wire the bootstrap logic (cursor check → --since → lookback_days fallback)
8. Wire the polling loop with backoff

**Feedback loop**:

- **Playground**: Create `tests/Feature/EventsListenCommandTest.php` with `Http::fake()` and cache setup before writing the command
- **Experiment**: Test with faked multi-page response (cursor present → immediate next page), faked empty response (triggers sleep), faked error response (triggers error backoff), `--once` flag (exits after one page), `--since` flag (sends `range_start`), cursor persistence (verify cache is written after each event)
- **Check command**: `composer test -- --filter=EventsListenCommandTest`

## Testing Requirements

### Feature Tests

| Test File | Coverage |
|---|---|
| `tests/Feature/EventsListenCommandTest.php` | Full command behavior: polling, cursor persistence, event dispatch, options, error handling |

**Key test cases**:

- **Happy path**: Faked response with 2 events → dispatches `WebhookReceived` + typed events for both, cursor persisted as last event ID
- **Pagination**: First response has `list_metadata.after`, second response empty → processes both pages, final cursor is last event ID from page 1 (or 2 if page 2 had events)
- **`--once` flag**: Processes one page and exits with SUCCESS
- **`--since` flag**: First request includes `range_start` parameter matching the provided date
- **Lookback default**: When no cursor and no `--since`, first request includes `range_start` based on `lookback_days` config
- **Cursor resume**: Pre-seed cache with a cursor → first request includes `after` parameter with that cursor value
- **Error recovery**: Faked 500 response → command outputs error, continues polling (with `--once`, it exits after the error)
- **No events configured**: When `EventRouting::eventTypesFor('events_api')` returns empty, command outputs warning and exits SUCCESS
- **Missing API key**: Command outputs error and exits FAILURE
- **Event dispatch parity**: Events dispatched by the worker match the same classes as `WebhookController` for identical event types
- **Unknown event type in response**: `WebhookReceived` dispatched, no typed event dispatched, no error

### Unit Tests

No dedicated unit tests needed — the command is tested as an integration through `$this->artisan()`. `EventRouting` unit tests from Phase 1 cover the routing logic.

## Error Handling

| Error Scenario | Handling Strategy |
|---|---|
| API returns 4xx/5xx | Log error via `$this->error()`, sleep for `min(poll_interval * 2, 30)`, continue polling |
| API returns malformed JSON | `$response->json()` returns null/empty, treated as empty page, triggers poll sleep |
| Cache write fails | Exception propagates — this is critical infrastructure failure, worker should crash and be restarted by supervisor |
| SIGTERM/SIGINT received | Set `shouldStop = true`, finish processing current event, persist cursor, exit cleanly |
| pcntl extension not loaded | Signal handlers silently skip registration. Worker runs but won't handle signals — must be killed with SIGKILL. Acceptable for dev environments. |
| Network timeout | Guzzle throws `ConnectionException`, caught by Laravel Http — returns failed response, handled by error recovery path |

## Failure Modes

| Component | Failure Mode | Trigger | Impact | Mitigation |
|---|---|---|---|---|
| Polling loop | Infinite error loop | WorkOS API persistently down | Command logs errors every `poll_interval * 2` seconds, fills log | Cap at 30s backoff; in production, supervisor config should have max restart limits |
| Cursor persistence | Stale cursor after cache eviction | Cache TTL expires or cache is flushed | Worker re-bootstraps from lookback_days, potentially reprocessing events | Set no TTL on cursor cache key (forever); document that cache store should be durable (file or redis, not array) |
| Cursor persistence | Cursor points to deleted event | Event retention expires (90 days) while worker was stopped for months | API may return error or start from beginning | Document: if worker is stopped for >90 days, clear the cursor manually and re-bootstrap |
| Event dispatch | Duplicate event processing | Worker restarts mid-page, re-fetches from last-persisted cursor | Same events dispatched twice | Listeners should be idempotent (existing `findByWorkOSId` + update pattern is inherently idempotent) |
| Bootstrap | Lookback window too large | `lookback_days` set to 30+, lots of events | First run takes a long time, processes huge backlog | Default is 7 days; document that large lookbacks should use `--since` with a specific date |
| HTTP client | Request timeout | WorkOS API slow to respond | Guzzle timeout exception → error recovery path | Laravel Http default timeout is 30s, which is reasonable; don't override |

## Validation Commands

```bash
# Static analysis
composer analyse

# Code style
composer format

# All tests
composer test

# Scoped test runs during development
composer test -- --filter=EventsListenCommandTest

# Smoke test (requires WORKOS_API_KEY in .env)
php artisan workos:events-listen --once
```

## Rollout Considerations

- **Process management**: Document that the worker should be run under a process manager (Supervisor, systemd, Docker restart policy) just like `queue:work`. Include example Supervisor config in README.
- **Monitoring**: Consumers should monitor the worker process. The command exits with FAILURE on fatal errors (missing API key), SUCCESS on graceful shutdown or `--once` completion.
- **Cache store**: Document that the cache store should be durable. The default `file` store works. `array` store will lose cursors on restart. Redis is recommended for production.

## Open Items

- [ ] Determine if `events` parameter in the WorkOS API accepts dotted event types directly or needs special formatting — verify against actual API response during smoke testing

---

_This spec is ready for implementation. Follow the patterns and validate at each step._
