# Events API and Webhooks

Understand the difference between webhooks and the Events API, configure event routing, and run the polling worker.

## Overview

WorkOS provides two mechanisms for syncing data to your application:

1. **Webhooks** — Real-time HTTP callbacks from WorkOS when events occur
2. **Events API** — REST endpoint you poll to fetch historical and real-time events with cursor-based pagination

The package supports both, and you can route specific event categories through either mechanism (or both). This flexibility lets you optimize for your infrastructure — use webhooks for immediate sync, Events API for resilient backfilling, or a hybrid approach.

## Concepts

### Webhooks
- **Push model** — WorkOS initiates the connection when an event occurs
- **Real-time** — Events delivered within seconds
- **Requires internet access** — WorkOS must reach your endpoint
- **Best for** — Immediate user/org sync, live features that react to WorkOS changes
- **Risk** — Network failures may miss events; retry logic depends on your implementation

### Events API
- **Pull model** — Your application polls WorkOS for events at regular intervals
- **Eventually consistent** — Events available within seconds, pulled on a schedule
- **Cursor persistence** — Automatically persists position; resumable after restarts
- **Resilient** — No incoming network dependency; catch up from last cursor
- **Best for** — Reliable backfilling, high-concurrency workloads, systems requiring audit trails
- **Trade-off** — Slight delay (poll interval) vs guaranteed delivery

### Event Categories
The package organizes events into categories for easier routing:

- `user` — User creation, updates, deletion
- `organization` — Org creation, updates, deletion
- `organization_membership` — Membership create/update/delete
- `session` — Session lifecycle and authentication events
- `authentication` — Auth method succeeded events
- `dsync` — Directory sync (LDAP, SCIM) events

## Configuration

The `config/workos.php` file controls event routing and the polling worker:

```php
'events' => [
    'routing' => [
        'categories' => [
            'user' => 'webhooks',                    // env: WORKOS_SYNC_USER
            'organization' => 'webhooks',            // env: WORKOS_SYNC_ORGANIZATION
            'organization_membership' => 'webhooks',  // env: WORKOS_SYNC_MEMBERSHIP
            'dsync' => 'events_api',                 // env: WORKOS_SYNC_DSYNC
            'session' => 'webhooks',                 // env: WORKOS_SYNC_SESSION
            'authentication' => 'webhooks',          // env: WORKOS_SYNC_AUTH
        ],

        'overrides' => [
            // Example: force 'user.created' via events_api regardless of category
            // 'user.created' => 'events_api',
        ],
    ],

    'poll_interval' => 5,                            // env: WORKOS_EVENTS_POLL_INTERVAL
    'lookback_days' => 7,                            // env: WORKOS_EVENTS_LOOKBACK_DAYS
    'limit' => 100,                                  // env: WORKOS_EVENTS_LIMIT
    'cache_store' => null,                           // env: WORKOS_EVENTS_CACHE_STORE (null = default)
    'cache_key' => 'workos.events.cursor',
],
```

### Routing Values

Each category can be set to:

- `'webhooks'` — Only sync via webhooks (if enabled and received)
- `'events_api'` — Only sync via the polling worker
- `'both'` — Accept events from either source (deduplication happens naturally)

### Environment Variables

For each category:

```env
# Default: webhooks
WORKOS_SYNC_USER=webhooks
WORKOS_SYNC_ORGANIZATION=webhooks
WORKOS_SYNC_MEMBERSHIP=webhooks
WORKOS_SYNC_DSYNC=events_api
WORKOS_SYNC_SESSION=webhooks
WORKOS_SYNC_AUTH=webhooks

# Events API polling configuration
WORKOS_EVENTS_POLL_INTERVAL=5          # Seconds between polls when caught up
WORKOS_EVENTS_LOOKBACK_DAYS=7          # Days to backfill on first run if no cursor
WORKOS_EVENTS_LIMIT=100                # Events per API request (max 1000)
WORKOS_EVENTS_CACHE_STORE=             # Cache store for cursor persistence (null = default)
```

### Per-Event-Type Overrides

For fine-grained control, override specific event types in the `overrides` array:

```php
'overrides' => [
    'user.created' => 'events_api',      // Force user.created via Events API
    'organization.created' => 'webhooks', // Force org.created via webhooks
    'session.created' => 'both',          // Accept from either source
],
```

This takes precedence over category routing.

## The workos:events-listen Command

The `workos:events-listen` command is a persistent polling worker that fetches events from the Events API and dispatches them as Laravel events.

### Usage

Start the worker:

```bash
php artisan workos:events-listen
```

Poll once and exit (for debugging):

```bash
php artisan workos:events-listen --once
```

Start from a specific date on first run:

```bash
php artisan workos:events-listen --since=2024-01-15
```

Override poll interval (seconds):

```bash
php artisan workos:events-listen --sleep=10
```

Combine options:

```bash
php artisan workos:events-listen --sleep=10 --since=2024-01-15
```

### Command Signature

```
workos:events-listen
  {--once : Poll a single page and exit}
  {--since= : ISO 8601 date to start from on first run (e.g. 2024-01-15)}
  {--sleep= : Seconds between polls when caught up (overrides config)}
```

### How It Works

1. **Initialization** — Checks if cursor exists; if not, uses `--since` or falls back to `lookback_days`
2. **Polling Loop** — Continuously fetches events from the Events API
3. **Event Processing** — Each event is dispatched as a Laravel event (same as webhooks)
4. **Cursor Persistence** — Event ID is stored in the configured cache store
5. **Backoff** — On API error, waits 2x poll_interval (max 30s) and retries
6. **Graceful Shutdown** — Responds to SIGTERM/SIGINT signals when `pcntl` extension is available

### Cursor Persistence

The worker stores the last processed event ID in cache to resume from where it left off. Configure the cache store:

```php
// config/workos.php
'events' => [
    'cache_store' => 'redis',  // Use Redis for cursor persistence
    'cache_key' => 'workos.events.cursor',
],
```

Defaults to your `CACHE_DRIVER` if not specified. Use `redis` or `memcached` for distributed systems; file-based stores work for single-server setups.

### First Run Behavior

On the very first run (no cursor exists):

```bash
# Use --since to start from a specific date
php artisan workos:events-listen --since=2024-01-15
# Output: First run — bootstrapping from --since=2024-01-15

# Or automatically backfill from 7 days ago (configurable)
php artisan workos:events-listen
# Output: First run — bootstrapping from 7 days ago
```

The lookback period is configured in `config/workos.php`:

```php
'lookback_days' => env('WORKOS_EVENTS_LOOKBACK_DAYS', 7),
```

### Error Recovery

If the API request fails:

1. Error is logged
2. Worker waits `poll_interval * 2` (capped at 30s) before retrying
3. On `--once`, worker exits with an error code
4. In continuous mode, worker keeps retrying indefinitely

### Signal Handling

If your server supports POSIX signals (Linux/Mac, not Windows):

- **SIGTERM** — Graceful shutdown (finishes current request, saves cursor, exits)
- **SIGINT** (Ctrl+C) — Same as SIGTERM
- **Other signals** — Worker logs and continues

To stop the worker gracefully:

```bash
kill -TERM <pid>
```

## Running as a Background Process

### Using Supervisor

Create a Supervisor configuration file at `/etc/supervisor/conf.d/workos-events.conf`:

```ini
[program:workos-events]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/app/artisan workos:events-listen
autostart=true
autorestart=true
stopasgroup=true
redirect_stderr=true
stdout_logfile=/path/to/app/storage/logs/workos-events.log
stdout_logfile_maxbytes=10MB
stdout_logfile_backups=10
numprocs=1
user=www-data
```

Start the worker:

```bash
supervisorctl reread
supervisorctl update
supervisorctl start workos-events:*
```

Monitor:

```bash
supervisorctl tail workos-events
```

### Using Laravel Horizon (if you have Redis)

If you're already using Horizon, you can queue the polling:

```php
// app/Console/Kernel.php
protected function schedule(Schedule $schedule)
{
    $schedule->command('workos:events-listen --once')
        ->everyFiveMinutes()
        ->onOneServer()
        ->withoutOverlapping(10);
}
```

**Note:** This is less efficient than the persistent worker but works if you prefer centralized job queuing.

### Using Systemd

Create `/etc/systemd/system/workos-events.service`:

```ini
[Unit]
Description=WorkOS Events API Polling Worker
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/path/to/app
ExecStart=/usr/bin/php artisan workos:events-listen
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
```

Enable and start:

```bash
sudo systemctl enable workos-events
sudo systemctl start workos-events
```

Monitor:

```bash
sudo systemctl status workos-events
sudo journalctl -u workos-events -f
```

## Hybrid Approach (Webhooks + Events API)

Use both mechanisms for resilience:

```php
'events' => [
    'routing' => [
        'categories' => [
            // Immediate sync via webhooks
            'user' => 'webhooks',
            'organization' => 'webhooks',

            // Resilient backfill via Events API (e.g., for SCIM)
            'dsync' => 'events_api',
        ],

        // Critical events via both sources (deduplication automatic)
        'overrides' => [
            'session.created' => 'both',
        ],
    ],
],
```

In this setup:

- User/org changes sync immediately via webhooks
- Directory sync events are polled reliably from Events API
- Session creation events are accepted from either source (the event dispatcher deduplicates naturally since the action is idempotent)

## Event Dispatching

Regardless of source (webhooks or Events API), events are dispatched identically:

```php
use WorkOS\AuthKit\Events\Webhooks\WorkOSUserCreated;

Event::listen(WorkOSUserCreated::class, function ($event) {
    // $event->data contains the user data
    // Handle identically whether it came from webhook or Events API
});
```

The `WebhookReceived` event is also dispatched with the raw event type and data:

```php
use WorkOS\AuthKit\Events\WebhookReceived;

Event::listen(WebhookReceived::class, function (WebhookReceived $event) {
    // $event->type = 'user.created', 'organization.updated', etc.
    // $event->data = raw event payload
});
```

## Testing

### Mock the Events API

Use `Http::fake()` to test the polling worker:

```php
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Event;

public function test_events_api_polling()
{
    Http::fake([
        'https://api.workos.com/events' => Http::response([
            'data' => [
                [
                    'id' => 'evt_123',
                    'event' => 'user.created',
                    'data' => [
                        'id' => 'user_123',
                        'email' => 'john@example.com',
                    ],
                ],
            ],
            'list_metadata' => ['after' => null],
        ]),
    ]);

    Event::fake();

    $this->artisan('workos:events-listen --once')
        ->assertSuccessful();

    Event::assertDispatched('user.created');
}
```

### Test Cursor Persistence

```php
public function test_events_api_persists_cursor()
{
    Cache::shouldReceive('store')
        ->andReturn($store = Mockery::mock());
    
    $store->shouldReceive('put')
        ->with('workos.events.cursor', 'evt_456');

    // Simulate API response
    Http::fake([...]);

    $this->artisan('workos:events-listen --once');
}
```

## Troubleshooting

### "No event types configured for events_api routing"

The worker found no events configured to sync via `events_api`. Check your config:

```php
// Ensure at least one category routes to 'events_api' or 'both'
'routing' => [
    'categories' => [
        'dsync' => 'events_api',  // This enables the worker
    ],
],
```

### "API request failed: 401"

Your `WORKOS_API_KEY` is missing or invalid. Verify:

```bash
php artisan config:clear
echo $WORKOS_API_KEY
```

### "Worker stopped after a few iterations"

If running locally with limited time, use `--once`:

```bash
php artisan workos:events-listen --once
```

For production, ensure the command runs under Supervisor or a process manager that auto-restarts.

### "Cursor never persists"

Check your cache store configuration:

```php
// Verify store is writable
Cache::put('test', 'value');
dd(Cache::get('test'));
```

If using Redis, ensure Redis is running:

```bash
redis-cli ping  # Should output: PONG
```

### "Processing too slowly"

Increase the `limit` to fetch more events per request (max 1000):

```php
'limit' => env('WORKOS_EVENTS_LIMIT', 500),
```

Or reduce `poll_interval` to catch up faster:

```php
'poll_interval' => env('WORKOS_EVENTS_POLL_INTERVAL', 2),
```

### "High memory usage"

The worker maintains a long-running process. Monitor with:

```bash
ps aux | grep workos:events-listen
```

If memory grows unbounded, there may be a listener accumulating data. Ensure event listeners are stateless or reset between iterations.

## Best Practices

### 1. Choose the Right Mechanism

- **Webhooks** — Fast sync, acceptable for most user/org changes
- **Events API** — Reliable backfilling, historical audit trails, critical business events
- **Both** — High-reliability requirements, don't mind slight redundancy

### 2. Monitor the Worker

Set up logging and alerting:

```bash
# Tail worker logs
tail -f storage/logs/workos-events.log

# Alert if process exits
pgrep -f 'workos:events-listen' || send_alert "Worker down"
```

### 3. Idempotent Event Handlers

Listeners must handle duplicate events gracefully:

```php
Event::listen(WorkOSUserCreated::class, function ($event) {
    // Good — updateOrCreate is idempotent
    User::updateOrCreate(
        ['workos_id' => $event->data['id']],
        ['email' => $event->data['email']]
    );
});
```

### 4. Optimize Polling Interval

Balance latency vs API quota:

```php
// Aggressive polling (higher cost, lower latency)
'poll_interval' => 2,  // Poll every 2 seconds when caught up

// Conservative polling (lower cost, higher latency)
'poll_interval' => 30,  // Poll every 30 seconds when caught up
```

### 5. Use Appropriate Cache Store

- **File-based** — Single server, development
- **Redis/Memcached** — Distributed systems, production
- **Database** — Last resort; avoid for high-frequency polling

```php
'cache_store' => config('cache.default'),
```

### 6. Log All Sync Activity

Track what events are processed:

```php
Event::listen(WebhookReceived::class, function ($event) {
    \Log::info('Event received', [
        'type' => $event->type,
        'source' => 'events_api',  // or 'webhooks'
    ]);
});
```

### 7. Combine with Webhooks

For redundancy, enable both:

```php
'routing' => [
    'categories' => [
        'user' => 'both',
        'organization' => 'both',
    ],
],
```

Events that arrive via both sources are deduplicated naturally since your handlers use `updateOrCreate()`.

## Related Documentation

- [Webhooks](webhooks.md) — Webhook endpoint, event handling, signature verification
- [Configuration](configuration.md) — Complete config reference
- [Commands](commands.md) — All artisan commands (including `workos:sync-users`)
