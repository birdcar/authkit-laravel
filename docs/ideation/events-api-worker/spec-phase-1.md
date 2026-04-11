# Implementation Spec: Events API Worker - Phase 1

**Contract**: ./contract.md
**Estimated Effort**: M

## Technical Approach

Phase 1 establishes the configuration and routing infrastructure that enables hybrid event sync. A new top-level `workos.events` config key replaces the existing `workos.webhooks.sync_enabled` toggle with a structured routing system. Event categories (user, organization, organization_membership, dsync, session, authentication) default to a sync method (webhooks or events_api), and individual event types can override their category default.

The `WorkOSServiceProvider` is refactored so `configureEventListeners()` respects routing config — listeners only bind to events that are routed through a sync mechanism actually active in the application. A new `EventRouting` service class resolves which event types belong to which sync method, used by both the webhook controller and the future polling worker.

The `EVENT_MAP` stays on `WebhookController` (it's a simple constant, no reason to move it), but the new `EventRouting` class provides methods to filter it by configured sync method.

## Feedback Strategy

**Inner-loop command**: `composer test`

**Playground**: Test suite — this phase is pure config/service logic with no HTTP or CLI behavior. Pest tests validate routing resolution and listener registration.

**Why this approach**: All changes are internal service wiring. Tests run in seconds and catch routing logic errors immediately.

## File Changes

### New Files

| File Path | Purpose |
|---|---|
| `src/Support/EventRouting.php` | Resolves which event types are routed to which sync method (webhooks vs events_api) based on config |
| `tests/Unit/EventRoutingTest.php` | Tests for EventRouting resolution logic |
| `tests/Feature/EventListenerRegistrationTest.php` | Tests that listeners are only registered for correctly-routed events |

### Modified Files

| File Path | Changes |
|---|---|
| `config/workos.php` | Add `events` top-level key; remove `webhooks.sync_enabled`; keep `webhooks.enabled`, `webhooks.prefix`, `webhook_secret` |
| `src/WorkOSServiceProvider.php` | Refactor `configureEventListeners()` to use `EventRouting`; register `EventRouting` as singleton; update `configureWebhooks()` to check routing config |

### Deleted Files

None — the old `sync_enabled` key is simply removed from config.

## Implementation Details

### Config Structure

**Pattern to follow**: Existing `workos.webhooks` and `workos.features` config patterns in `config/workos.php`

**Overview**: New `workos.events` key with routing, polling, and cache settings. Categories use string values: `'webhooks'`, `'events_api'`, or `'both'`.

```php
// config/workos.php — new 'events' key
'events' => [
    /*
    |----------------------------------------------------------------------
    | Event Sync Routing
    |----------------------------------------------------------------------
    |
    | Configure how each event category is synced. Options:
    |   'webhooks'   — events arrive via webhook POST (default)
    |   'events_api' — events are polled via the Events API worker
    |   'both'       — events are processed from both sources
    |
    | Per-event-type overrides take precedence over category defaults.
    |
    */
    'routing' => [
        'categories' => [
            'user'                    => env('WORKOS_SYNC_USER', 'webhooks'),
            'organization'            => env('WORKOS_SYNC_ORGANIZATION', 'webhooks'),
            'organization_membership' => env('WORKOS_SYNC_MEMBERSHIP', 'webhooks'),
            'dsync'                   => env('WORKOS_SYNC_DSYNC', 'events_api'),
            'session'                 => env('WORKOS_SYNC_SESSION', 'webhooks'),
            'authentication'          => env('WORKOS_SYNC_AUTH', 'webhooks'),
        ],

        // Per-event-type overrides (takes precedence over category)
        // Example: 'user.deleted' => 'events_api'
        'overrides' => [],
    ],

    /*
    |----------------------------------------------------------------------
    | Events API Worker Configuration
    |----------------------------------------------------------------------
    |
    | Settings for the workos:events-listen polling worker.
    |
    */
    'poll_interval' => env('WORKOS_EVENTS_POLL_INTERVAL', 5),
    'lookback_days' => env('WORKOS_EVENTS_LOOKBACK_DAYS', 7),
    'limit' => env('WORKOS_EVENTS_LIMIT', 100),
    'cache_store' => env('WORKOS_EVENTS_CACHE_STORE'),
    'cache_key' => 'workos.events.cursor',
],
```

**Key decisions**:

- Categories map to event type prefixes in `EVENT_MAP` (e.g., `user` matches `user.created`, `user.updated`, `user.deleted`)
- `dsync` defaults to `events_api` per WorkOS recommendation
- All others default to `webhooks` for backwards compatibility
- `cache_store` defaults to `null` (uses Laravel's default cache store)
- `'both'` is supported so consumers can run webhooks and events API simultaneously during migration

**Implementation steps**:

1. Add the `events` key to `config/workos.php` with all sub-keys
2. Remove `webhooks.sync_enabled` from the config
3. Verify existing `webhooks.enabled` and `webhooks.prefix` remain untouched

### EventRouting Service

**Pattern to follow**: No direct analog, but follows the singleton service pattern used by `SessionManager` in `src/Auth/SessionManager.php`

**Overview**: Resolves event type strings to their configured sync method by checking overrides first, then category defaults. Provides filtered lists of event types for each sync method.

```php
<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Support;

use WorkOS\AuthKit\Http\Controllers\WebhookController;

class EventRouting
{
    /** @var array<string, string> */
    private const array CATEGORY_MAP = [
        'user.'                    => 'user',
        'organization_membership.' => 'organization_membership',
        'organization.'            => 'organization',
        'dsync.'                   => 'dsync',
        'session.'                 => 'session',
        'authentication.'          => 'authentication',
    ];

    /**
     * @param  array<string, string>  $categories
     * @param  array<string, string>  $overrides
     */
    public function __construct(
        private readonly array $categories,
        private readonly array $overrides,
    ) {}

    public function methodFor(string $eventType): string
    {
        if (isset($this->overrides[$eventType])) {
            return $this->overrides[$eventType];
        }

        foreach (self::CATEGORY_MAP as $prefix => $category) {
            if (str_starts_with($eventType, $prefix)) {
                return $this->categories[$category] ?? 'webhooks';
            }
        }

        return 'webhooks';
    }

    public function shouldProcessVia(string $eventType, string $method): bool
    {
        $configured = $this->methodFor($eventType);

        return $configured === $method || $configured === 'both';
    }

    /**
     * @return array<string>
     */
    public function eventTypesFor(string $method): array
    {
        return array_keys(
            array_filter(
                WebhookController::EVENT_MAP,
                fn (string $class, string $type) => $this->shouldProcessVia($type, $method),
                ARRAY_FILTER_USE_BOTH,
            )
        );
    }
}
```

**Key decisions**:

- `CATEGORY_MAP` uses prefixes with dots to match event types — `organization_membership.` is checked before `organization.` to avoid false prefix matches
- `methodFor()` returns the string method name (`'webhooks'`, `'events_api'`, `'both'`)
- `shouldProcessVia()` returns true for exact match OR `'both'`
- `eventTypesFor()` returns all event type strings from `EVENT_MAP` that should be processed by a given method — used by the polling worker to build its `events` filter parameter

**Implementation steps**:

1. Create `src/Support/EventRouting.php` with the class above
2. Ensure `CATEGORY_MAP` ordering handles `organization_membership` before `organization` prefix

**Feedback loop**:

- **Playground**: Create `tests/Unit/EventRoutingTest.php` with a describe block before writing the class
- **Experiment**: Test with default config (all webhooks except dsync), with overrides (`user.deleted => events_api`), with `both`, and with an unknown event type (should default to webhooks)
- **Check command**: `composer test -- --filter=EventRoutingTest`

### Service Provider Refactor

**Pattern to follow**: Existing `configureEventListeners()` and `configureWebhooks()` in `src/WorkOSServiceProvider.php`

**Overview**: Register `EventRouting` as a singleton. Refactor `configureEventListeners()` to only bind listeners for events that are routed through an active sync method (i.e., if all user events are routed to `events_api`, don't skip listener registration — the listeners fire on the same dispatch events regardless of source). The key insight: listeners should always be registered because both webhooks and Events API dispatch the same typed events. The routing config controls *which source dispatches* the event, not whether the listener exists.

```php
// In register():
$this->app->singleton(EventRouting::class, function () {
    return new EventRouting(
        categories: config('workos.events.routing.categories', []),
        overrides: config('workos.events.routing.overrides', []),
    );
});

// configureEventListeners() — simplified: always register listeners
// The routing config controls which *source* fires the events
protected function configureEventListeners(): void
{
    Event::listen(WorkOSUserCreated::class, [SyncUserFromWebhook::class, 'handle']);
    Event::listen(WorkOSUserUpdated::class, [SyncUserFromWebhook::class, 'handle']);
    Event::listen(WorkOSOrganizationCreated::class, [SyncOrganizationFromWebhook::class, 'handle']);
    Event::listen(WorkOSOrganizationUpdated::class, [SyncOrganizationFromWebhook::class, 'handle']);
    Event::listen(WorkOSMembershipCreated::class, [SyncMembershipFromWebhook::class, 'handleCreated']);
    Event::listen(WorkOSMembershipUpdated::class, [SyncMembershipFromWebhook::class, 'handleUpdated']);
    Event::listen(WorkOSMembershipDeleted::class, [SyncMembershipFromWebhook::class, 'handleDeleted']);
}
```

**Key decisions**:

- Listeners are ALWAYS registered — they don't care whether the event came from a webhook or the Events API. Both dispatchers fire the same event classes.
- The old `sync_enabled` guard is removed. If consumers don't want sync, they configure all categories to a method they don't run (or simply don't register listeners in their own EventServiceProvider).
- `WebhookController` gains an `EventRouting` check: it only dispatches typed events for event types routed through `webhooks` (or `both`). This prevents duplicate processing when events API is the configured source.
- `EventRouting` is registered in `register()` (not `boot()`) so it's available to other services.

**Implementation steps**:

1. Add `EventRouting` singleton registration to `register()`
2. Remove `sync_enabled` guard from `configureEventListeners()`
3. Update `WebhookController::handle()` to check `EventRouting::shouldProcessVia($eventType, 'webhooks')` before dispatching typed events (still always dispatch `WebhookReceived` for catch-all listeners)
4. Inject `EventRouting` into `WebhookController` constructor

**Feedback loop**:

- **Playground**: Create `tests/Feature/EventListenerRegistrationTest.php` before modifying the service provider
- **Experiment**: Test that with `user => events_api` config, webhook POST for `user.created` does NOT dispatch `WorkOSUserCreated` but DOES dispatch `WebhookReceived`. Test that with `user => webhooks`, it dispatches both. Test with `user => both`, it dispatches both.
- **Check command**: `composer test -- --filter=EventListenerRegistration`

## Testing Requirements

### Unit Tests

| Test File | Coverage |
|---|---|
| `tests/Unit/EventRoutingTest.php` | Category resolution, override precedence, `eventTypesFor()`, `shouldProcessVia()`, unknown event fallback |

**Key test cases**:

- Default config routes `user.created` to `webhooks`, `dsync.user.created` to `events_api`
- Override `user.deleted => events_api` takes precedence over `user => webhooks`
- `both` returns true for both `shouldProcessVia('webhooks')` and `shouldProcessVia('events_api')`
- Unknown event type (not in CATEGORY_MAP) defaults to `webhooks`
- `eventTypesFor('events_api')` returns only dsync types with default config
- `eventTypesFor('webhooks')` returns all non-dsync types with default config
- `organization_membership.*` events match `organization_membership` category, not `organization`

### Feature Tests

| Test File | Coverage |
|---|---|
| `tests/Feature/EventListenerRegistrationTest.php` | Listener registration respects routing config |

**Key scenarios**:

- Webhook POST for event routed to `events_api` dispatches `WebhookReceived` but NOT the typed event
- Webhook POST for event routed to `webhooks` dispatches both `WebhookReceived` and typed event
- Webhook POST for event routed to `both` dispatches both
- All existing webhook tests continue to pass (no regression)

## Error Handling

| Error Scenario | Handling Strategy |
|---|---|
| Missing `workos.events` config key | `EventRouting` constructor receives empty arrays, defaults all events to `webhooks` — backwards compatible |
| Invalid routing value (not `webhooks`/`events_api`/`both`) | No validation — treated as an opaque string. `shouldProcessVia()` does exact match, so invalid values simply won't match either method |

## Failure Modes

| Component | Failure Mode | Trigger | Impact | Mitigation |
|---|---|---|---|---|
| EventRouting | Prefix collision | Event type matches wrong category (e.g., `organization.created` matching `organization_membership` if map order wrong) | Events routed to wrong method | `CATEGORY_MAP` ordered with longer prefixes first (`organization_membership.` before `organization.`) |
| WebhookController | Routing check blocks valid webhook | `EventRouting` incorrectly reports event shouldn't process via webhooks | Typed event not dispatched, sync doesn't happen | `WebhookReceived` (generic) always dispatches regardless of routing — consumers can always catch-all |
| Config | Breaking change on upgrade | Consumers who set `WORKOS_WEBHOOK_SYNC_ENABLED=false` find it no longer works | Listeners register when they previously didn't | Document in upgrade guide; env var removal is explicit in changelog |

## Validation Commands

```bash
# Static analysis
composer analyse

# Code style
composer format

# Tests
composer test

# Scoped test runs during development
composer test -- --filter=EventRoutingTest
composer test -- --filter=EventListenerRegistration
composer test -- --filter=WebhookTest
```

## Rollout Considerations

- **Breaking change**: `workos.webhooks.sync_enabled` is removed. Consumers who published config must update. Document in UPGRADE.md.
- **Default behavior**: With default config, all events except dsync route through webhooks — matches current behavior for existing users who don't use dsync.
- **Migration path**: Consumers can set categories to `'both'` during transition, then switch to `'events_api'` once the worker is stable.

---

_This spec is ready for implementation. Follow the patterns and validate at each step._
