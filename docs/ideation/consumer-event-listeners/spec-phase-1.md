# Implementation Spec: Consumer Event Listeners - Phase 1

**Contract**: ./contract.md
**Estimated Effort**: M

## Technical Approach

Phase 1 delivers the core infrastructure: a `HandlesWorkOSEvents` trait that consumers use in their listeners, and a per-event listener config system that controls which listeners the package registers.

The trait follows the same pattern as the package's existing model traits (`HasWorkOSId`, `HasOrganization`) — provide convenience methods that read from config and resolve through Laravel's service container. The trait will NOT replace Laravel's event discovery; it augments it.

The config system modifies `WorkOSServiceProvider::configureEventListeners()` to check `config('workos.sync.listeners')` before registering each built-in listener. If a consumer provides a replacement class for an event, that class is registered instead. If set to `null`, no listener is registered. If omitted, the default applies.

## Feedback Strategy

**Inner-loop command**: `composer test`

**Playground**: Test suite — the trait and config system are internal PHP, no UI involved.

**Why this approach**: All changes are to service provider logic and a new trait class. The test suite is the fastest feedback mechanism.

## File Changes

### New Files

| File Path | Purpose |
|---|---|
| `src/Listeners/Concerns/HandlesWorkOSEvents.php` | Consumer trait with model resolution, audit, logging, and transaction helpers |
| `tests/Unit/HandlesWorkOSEventsTest.php` | Unit tests for the trait's helper methods |
| `tests/Feature/ListenerConfigTest.php` | Feature tests for per-event listener config |

### Modified Files

| File Path | Changes |
|---|---|
| `config/workos.php` | Add `sync.listeners` config section with per-event defaults |
| `src/WorkOSServiceProvider.php` | Modify `configureEventListeners()` to read listener config |
| `workbench/config/workos.php` | Sync with updated package config |

## Implementation Details

### HandlesWorkOSEvents Trait

**Pattern to follow**: `src/Models/Concerns/HasOrganization.php` (reads model class from config, resolves via Eloquent)

**Overview**: A trait consumers `use` in their listener classes. Provides model resolution, audit logging, structured logging, and transaction support.

```php
namespace WorkOS\AuthKit\Listeners\Concerns;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use WorkOS\AuthKit\Events\Sync\Concerns\HasEventData;
use WorkOS\AuthKit\Facades\WorkOS;

trait HandlesWorkOSEvents
{
    /**
     * Resolve the user model from an event's WorkOS user ID.
     *
     * @param  object{get: callable}  $event  Any event using HasEventData
     */
    protected function resolveUser(object $event): ?Authenticatable
    {
        $userId = $event->get('user_id') ?? $event->get('id');
        if (! $userId) {
            return null;
        }

        $model = config('workos.user_model');

        return $model::where('workos_id', $userId)->first();
    }

    /**
     * Resolve the organization model from an event's WorkOS org ID.
     */
    protected function resolveOrganization(object $event): ?Model
    {
        $orgId = $event->get('organization_id') ?? $event->get('id');
        if (! $orgId) {
            return null;
        }

        $model = config('workos.organization_model');

        return $model::where('workos_id', $orgId)->first();
    }

    /**
     * Log an audit event to WorkOS Audit Logs.
     *
     * @param  array<string, mixed>  $metadata
     */
    protected function audit(string $action, array $metadata = []): void
    {
        WorkOS::audit($action, metadata: $metadata);
    }

    /**
     * Log a structured message with event context.
     *
     * @param  array<string, mixed>  $context
     */
    protected function logEvent(string $message, object $event, array $context = []): void
    {
        Log::info($message, array_merge([
            'workos_event' => $event instanceof \WorkOS\AuthKit\Events\WorkOSEventReceived
                ? $event->event
                : class_basename($event),
        ], $context));
    }

    /**
     * Execute listener logic within a database transaction.
     */
    protected function withinTransaction(callable $callback): mixed
    {
        return DB::transaction($callback);
    }
}
```

**Key decisions**:

- `resolveUser()` tries `user_id` first (membership events), then `id` (user events) — covers both event shapes without the consumer needing to know the field name
- `resolveOrganization()` same pattern with `organization_id` then `id`
- The event parameter is typed as `object` rather than a specific interface — this keeps the trait usable with any event (typed events, `WorkOSEventReceived`, even custom events)
- `audit()` is a thin wrapper; consumers who need more control use `WorkOS::audit()` directly
- `withinTransaction()` is intentionally simple — Laravel's `DB::transaction()` handles retries and rollback

**Implementation steps**:

1. Create `src/Listeners/Concerns/HandlesWorkOSEvents.php` with the trait
2. Write unit tests verifying each method (mock the models and facades)
3. Update the package's own `SyncUserFromWorkOS`, `SyncOrganizationFromWorkOS`, `SyncMembershipFromWorkOS` to use the new trait — proving it works for real listeners

**Feedback loop**:

- **Playground**: Create `tests/Unit/HandlesWorkOSEventsTest.php` with model mocks
- **Experiment**: Test resolution with valid WorkOS IDs, missing IDs, null fields
- **Check command**: `vendor/bin/pest tests/Unit/HandlesWorkOSEventsTest.php`

### Per-Event Listener Config

**Pattern to follow**: `src/WorkOSServiceProvider.php` lines 316-324 (current `configureEventListeners()`)

**Overview**: Modify the service provider to read a `sync.listeners` config array that maps event classes to listener classes. The current hardcoded `Event::listen()` calls become data-driven.

```php
// config/workos.php — new section
'sync' => [
    'listeners' => [
        // Override any event's listener. Set to a class string to replace,
        // null to disable, or omit to keep the package default.
        //
        // Example:
        // WorkOSUserCreated::class => App\Listeners\MyUserSync::class,
        // WorkOSUserDeleted::class => null,
    ],
],
```

```php
// WorkOSServiceProvider::configureEventListeners() — revised
private function configureEventListeners(): void
{
    $defaults = [
        WorkOSUserCreated::class => [SyncUserFromWorkOS::class, 'handle'],
        WorkOSUserUpdated::class => [SyncUserFromWorkOS::class, 'handle'],
        WorkOSOrganizationCreated::class => [SyncOrganizationFromWorkOS::class, 'handle'],
        WorkOSOrganizationUpdated::class => [SyncOrganizationFromWorkOS::class, 'handle'],
        WorkOSMembershipCreated::class => [SyncMembershipFromWorkOS::class, 'handleCreated'],
        WorkOSMembershipUpdated::class => [SyncMembershipFromWorkOS::class, 'handleUpdated'],
        WorkOSMembershipDeleted::class => [SyncMembershipFromWorkOS::class, 'handleDeleted'],
    ];

    /** @var array<class-string, class-string|null> */
    $overrides = config('workos.sync.listeners', []);

    foreach ($defaults as $event => $defaultListener) {
        if (array_key_exists($event, $overrides)) {
            $override = $overrides[$event];
            if ($override !== null) {
                Event::listen($event, $override);
            }
            // null = explicitly disabled, skip
            continue;
        }

        Event::listen($event, $defaultListener);
    }
}
```

**Key decisions**:

- `array_key_exists` not `isset` — distinguishes between "not configured" (use default) and "set to null" (disabled)
- Consumer-provided listeners are registered as just the class string (not `[class, 'method']`) — Laravel will call `handle()` by convention, which is what auto-discovery expects
- The defaults map is defined inline rather than as a class constant — keeps the logic self-contained and avoids exposing internal wiring

**Implementation steps**:

1. Add `sync.listeners` key to `config/workos.php` with commented examples
2. Refactor `configureEventListeners()` to use the defaults + overrides pattern
3. Write feature tests covering: default behavior, replacing a listener, disabling a listener, mixed config

**Feedback loop**:

- **Playground**: `tests/Feature/ListenerConfigTest.php`
- **Experiment**: Configure `sync.listeners` with various combinations, dispatch events, assert correct listeners fire
- **Check command**: `vendor/bin/pest tests/Feature/ListenerConfigTest.php`

## Testing Requirements

### Unit Tests

| Test File | Coverage |
|---|---|
| `tests/Unit/HandlesWorkOSEventsTest.php` | Trait model resolution, audit wrapping, logging, transactions |

**Key test cases**:

- `resolveUser()` with a valid WorkOS user ID returns the user model
- `resolveUser()` with `user_id` field (membership event shape) resolves correctly
- `resolveUser()` returns null when user not found
- `resolveUser()` returns null when event has no ID field
- `resolveOrganization()` with valid org ID returns the org model
- `audit()` delegates to `WorkOS::audit()`
- `logEvent()` logs with event class name context
- `withinTransaction()` commits on success, rolls back on exception

### Feature Tests

| Test File | Coverage |
|---|---|
| `tests/Feature/ListenerConfigTest.php` | Per-event listener config |

**Key test cases**:

- Default config: all built-in listeners fire
- Replace one listener: only the replacement fires for that event
- Disable one listener: event dispatches but no listener fires
- Mixed config: some replaced, some disabled, some default
- Unknown event in config: silently ignored

## Error Handling

| Error Scenario | Handling Strategy |
|---|---|
| `resolveUser()` with unconfigured model class | Returns null — config('workos.user_model') is always set via package defaults |
| Consumer listener throws exception | Laravel's event system handles this — propagates to error handler, does not affect other listeners |
| Invalid class in `sync.listeners` config | Laravel throws when resolving — standard behavior, no special handling needed |

## Failure Modes

| Component | Failure Mode | Trigger | Impact | Mitigation |
|---|---|---|---|---|
| `resolveUser()` | Model not found | User exists in WorkOS but not synced locally yet | Returns null, consumer must handle | Document that resolution requires prior sync |
| `resolveUser()` | Wrong ID field | Event data uses neither `user_id` nor `id` | Returns null | Consumer uses `$event->get('custom_field')` directly |
| Listener config | Class doesn't exist | Typo in config class string | Fatal error on event dispatch | Standard PHP — class not found error is clear |
| Listener config | Class lacks `handle()` | Consumer class has wrong method name | Silent no-op (Laravel finds no callable) | Artisan make command generates correct structure |

## Validation Commands

```bash
# Static analysis
composer analyse

# All tests
composer test

# Just the new tests
vendor/bin/pest tests/Unit/HandlesWorkOSEventsTest.php tests/Feature/ListenerConfigTest.php
```

## Rollout Considerations

- **Backwards compatible**: Default config matches current behavior — no listener config = all defaults fire
- **No migration needed**: Config-only change
- **Package version**: Minor bump (new feature, no breaking change)
