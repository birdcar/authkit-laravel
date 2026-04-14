# Implementation Spec: Platform Parity - Phase 6 (Directory Sync Typed Events)

**Contract**: ./contract.md
**Estimated Effort**: M

## Technical Approach

`dsync.*` events already arrive through the webhook pipeline and fire `WorkOSEventReceived`. The `EventRouting::CATEGORY_MAP` already has a `'dsync.' => 'dsync'` entry and `config/workos.php` already routes dsync events to `'events_api'` by default. What's missing is everything downstream: typed event classes, `EVENT_MAP` entries, default listeners, and model config keys.

The pattern is identical to the existing `user.*` / `organization.*` / `organization_membership.*` typed events. Each dsync event class is a plain PHP class that uses the `HasEventData` trait and adds typed accessor methods for the payload fields documented in the WorkOS events API. The existing `WebhookController` dispatch logic (`new $eventClass($eventData)`) handles any class registered in `EVENT_MAP` without modification.

The dsync events route via the Events API by default (config `'dsync' => 'events_api'`), which means the `EventsListenCommand` worker already polls and dispatches them — the typed events will be dispatched there once `EVENT_MAP` is updated.

Directory users and directory groups are distinct from auth users and organizations. They need their own configurable model keys (`workos.dsync.user_model`, `workos.dsync.group_model`) because a directory user is an IDP-managed record, not necessarily the same Eloquent model as the application's auth user.

## Feedback Strategy

**Inner-loop command**: `vendor/bin/pest tests/Unit/Sync/`

**Playground**: Test suite — all classes are pure value objects, no external calls needed.

**Why this approach**: Event classes and listeners are pure PHP with no I/O. PHPStan and Pest give fast feedback without needing a running server.

## File Changes

### New Files

| File Path | Purpose |
|---|---|
| `src/Events/Sync/WorkOSDsyncActivated.php` | Typed event for `dsync.activated` |
| `src/Events/Sync/WorkOSDsyncDeleted.php` | Typed event for `dsync.deleted` |
| `src/Events/Sync/WorkOSDsyncUserCreated.php` | Typed event for `dsync.user.created` |
| `src/Events/Sync/WorkOSDsyncUserUpdated.php` | Typed event for `dsync.user.updated` |
| `src/Events/Sync/WorkOSDsyncUserDeleted.php` | Typed event for `dsync.user.deleted` |
| `src/Events/Sync/WorkOSDsyncGroupCreated.php` | Typed event for `dsync.group.created` |
| `src/Events/Sync/WorkOSDsyncGroupUpdated.php` | Typed event for `dsync.group.updated` |
| `src/Events/Sync/WorkOSDsyncGroupDeleted.php` | Typed event for `dsync.group.deleted` |
| `src/Events/Sync/WorkOSDsyncGroupUserAdded.php` | Typed event for `dsync.group.user_added` |
| `src/Events/Sync/WorkOSDsyncGroupUserRemoved.php` | Typed event for `dsync.group.user_removed` |
| `src/Listeners/SyncDirectoryUserFromWorkOS.php` | Default listener for directory user create/update/delete |
| `src/Listeners/SyncDirectoryGroupFromWorkOS.php` | Default listener for directory group create/update/delete |
| `tests/Unit/Sync/WorkOSDsyncEventsTest.php` | Unit tests for all 10 event classes |
| `tests/Unit/Sync/SyncDirectoryUserFromWorkOSTest.php` | Unit tests for the directory user listener |
| `tests/Unit/Sync/SyncDirectoryGroupFromWorkOSTest.php` | Unit tests for the directory group listener |

### Modified Files

| File Path | Changes |
|---|---|
| `src/Http/Controllers/WebhookController.php` | Add all 10 dsync types to `EVENT_MAP` |
| `config/workos.php` | Add `workos.dsync.user_model` and `workos.dsync.group_model` config keys |
| `src/WorkOSServiceProvider.php` | Register default dsync listeners in the event map |

## Implementation Details

### Event Classes

**Pattern to follow**: `src/Events/Sync/WorkOSUserCreated.php` — use `HasEventData` trait, add typed accessor methods for the payload fields.

#### Directory events (`dsync.activated`, `dsync.deleted`)

```php
// WorkOSDsyncActivated
public function directoryId(): string   // $this->data['id']
public function name(): string           // $this->data['name']
public function organizationId(): string // $this->data['organization_id']
public function type(): string           // $this->data['type']  (e.g. 'gsuite', 'okta')
public function state(): string          // $this->data['state'] (e.g. 'linked')

// WorkOSDsyncDeleted
public function directoryId(): string   // $this->data['id']
public function organizationId(): string // $this->data['organization_id']
public function type(): string
public function state(): string
```

#### Directory user events (`dsync.user.created`, `dsync.user.updated`, `dsync.user.deleted`)

```php
// WorkOSDsyncUserCreated, WorkOSDsyncUserUpdated
public function directoryUserId(): string // $this->data['id']
public function directoryId(): string     // $this->data['directory_id']
public function organizationId(): string  // $this->data['organization_id']
public function idpId(): string           // $this->data['idp_id']
public function email(): string           // $this->data['email']
public function firstName(): ?string      // $this->data['first_name'] ?? null
public function lastName(): ?string       // $this->data['last_name'] ?? null
public function state(): string           // $this->data['state'] ('active'|'inactive')
/** @return array<string, mixed> */
public function customAttributes(): array // $this->data['custom_attributes'] ?? []

// WorkOSDsyncUserUpdated also has:
/** @return array<string, mixed>|null */
public function previousAttributes(): ?array // $this->data['previous_attributes'] ?? null

// WorkOSDsyncUserDeleted (id, directory_id, organization_id, idp_id, email, state only)
public function directoryUserId(): string
public function directoryId(): string
public function organizationId(): string
public function email(): string
public function state(): string
```

#### Directory group events (`dsync.group.created`, `dsync.group.updated`, `dsync.group.deleted`)

```php
// WorkOSDsyncGroupCreated, WorkOSDsyncGroupUpdated
public function directoryGroupId(): string // $this->data['id']
public function directoryId(): string      // $this->data['directory_id']
public function organizationId(): string   // $this->data['organization_id']
public function idpId(): string            // $this->data['idp_id']
public function name(): string             // $this->data['name']

// WorkOSDsyncGroupUpdated also has:
/** @return array<string, mixed>|null */
public function previousAttributes(): ?array // $this->data['previous_attributes'] ?? null

// WorkOSDsyncGroupDeleted (id, directory_id, organization_id, name only)
public function directoryGroupId(): string
public function directoryId(): string
public function organizationId(): string
public function name(): string
```

#### Group membership events (`dsync.group.user_added`, `dsync.group.user_removed`)

These events carry nested `user` and `group` sub-objects:

```php
// WorkOSDsyncGroupUserAdded, WorkOSDsyncGroupUserRemoved
public function directoryId(): string         // $this->data['directory_id']
/** @return array<string, mixed> */
public function user(): array                 // $this->data['user']
/** @return array<string, mixed> */
public function group(): array                // $this->data['group']

// Convenience accessors
public function directoryUserId(): string     // $this->data['user']['id']
public function directoryGroupId(): string    // $this->data['group']['id']
public function userEmail(): string           // $this->data['user']['email']
public function groupName(): string           // $this->data['group']['name']
```

### WebhookController EVENT_MAP

Add these 10 entries to the `EVENT_MAP` constant:

```php
'dsync.activated'         => WorkOSDsyncActivated::class,
'dsync.deleted'           => WorkOSDsyncDeleted::class,
'dsync.user.created'      => WorkOSDsyncUserCreated::class,
'dsync.user.updated'      => WorkOSDsyncUserUpdated::class,
'dsync.user.deleted'      => WorkOSDsyncUserDeleted::class,
'dsync.group.created'     => WorkOSDsyncGroupCreated::class,
'dsync.group.updated'     => WorkOSDsyncGroupUpdated::class,
'dsync.group.deleted'     => WorkOSDsyncGroupDeleted::class,
'dsync.group.user_added'  => WorkOSDsyncGroupUserAdded::class,
'dsync.group.user_removed'=> WorkOSDsyncGroupUserRemoved::class,
```

All imports go in alphabetical order with the other `WorkOS\AuthKit\Events\Sync\*` imports.

### Config — workos.php

Add a `dsync` top-level key (alongside `user_model` / `organization_model`):

```php
/*
|--------------------------------------------------------------------------
| Directory Sync Models
|--------------------------------------------------------------------------
|
| The fully qualified class names for models that receive directory sync
| data. Directory users and groups are IDP-managed records, distinct from
| your application's auth user model.
|
*/

'dsync' => [
    'user_model'  => env('WORKOS_DSYNC_USER_MODEL', null),
    'group_model' => env('WORKOS_DSYNC_GROUP_MODEL', null),
],
```

Null defaults are intentional: the listeners check for model existence and skip gracefully if not configured, matching the pattern of `SyncUserFromWorkOS` checking for `findByWorkOSId`.

### Default Listeners

**Pattern to follow**: `src/Listeners/SyncUserFromWorkOS.php` and `src/Listeners/SyncMembershipFromWorkOS.php`.

```php
// SyncDirectoryUserFromWorkOS
// Handles: WorkOSDsyncUserCreated, WorkOSDsyncUserUpdated, WorkOSDsyncUserDeleted

class SyncDirectoryUserFromWorkOS
{
    public function handleCreatedOrUpdated(WorkOSDsyncUserUpdated|WorkOSDsyncUserCreated $event): void
    {
        $model = config('workos.dsync.user_model');
        if ($model === null || ! method_exists($model, 'findByWorkOSId')) {
            return;
        }

        $user = $model::findByWorkOSId($event->directoryUserId());
        if ($user === null) {
            return;
        }

        $user->update([
            'email'      => $event->email(),
            'name'       => trim(($event->firstName() ?? '').' '.($event->lastName() ?? '')) ?: null,
            'workos_state' => $event->state(),
        ]);
    }

    public function handleDeleted(WorkOSDsyncUserDeleted $event): void
    {
        $model = config('workos.dsync.user_model');
        if ($model === null || ! method_exists($model, 'findByWorkOSId')) {
            return;
        }

        $user = $model::findByWorkOSId($event->directoryUserId());
        $user?->delete();
    }
}
```

```php
// SyncDirectoryGroupFromWorkOS
// Handles: WorkOSDsyncGroupCreated, WorkOSDsyncGroupUpdated, WorkOSDsyncGroupDeleted

class SyncDirectoryGroupFromWorkOS
{
    public function handleCreatedOrUpdated(WorkOSDsyncGroupUpdated|WorkOSDsyncGroupCreated $event): void
    {
        $model = config('workos.dsync.group_model');
        if ($model === null || ! method_exists($model, 'where')) {
            return;
        }

        $group = $model::where('workos_id', $event->directoryGroupId())->first();
        if ($group === null) {
            return;
        }

        $group->update(['name' => $event->name()]);
    }

    public function handleDeleted(WorkOSDsyncGroupDeleted $event): void
    {
        $model = config('workos.dsync.group_model');
        if ($model === null || ! method_exists($model, 'where')) {
            return;
        }

        $model::where('workos_id', $event->directoryGroupId())->first()?->delete();
    }
}
```

**Key decision**: `handleCreatedOrUpdated` instead of separate `handleCreated`/`handleUpdated` — both payloads have the same fields and the update logic is identical. This matches how `SyncUserFromWorkOS::handle()` accepts both created and updated events in one union type.

### WorkOSServiceProvider — Listener Registration

Register default listeners in the existing sync listener map logic. The dsync listeners use method-style binding (`[$listener, 'handleCreatedOrUpdated']`) matching `SyncMembershipFromWorkOS`:

```php
// In the sync listener registration block:
WorkOSDsyncUserCreated::class  => SyncDirectoryUserFromWorkOS::class.'@handleCreatedOrUpdated',
WorkOSDsyncUserUpdated::class  => SyncDirectoryUserFromWorkOS::class.'@handleCreatedOrUpdated',
WorkOSDsyncUserDeleted::class  => SyncDirectoryUserFromWorkOS::class.'@handleDeleted',
WorkOSDsyncGroupCreated::class => SyncDirectoryGroupFromWorkOS::class.'@handleCreatedOrUpdated',
WorkOSDsyncGroupUpdated::class => SyncDirectoryGroupFromWorkOS::class.'@handleCreatedOrUpdated',
WorkOSDsyncGroupDeleted::class => SyncDirectoryGroupFromWorkOS::class.'@handleDeleted',
```

No default listeners for `WorkOSDsyncActivated`, `WorkOSDsyncDeleted`, `WorkOSDsyncGroupUserAdded`, or `WorkOSDsyncGroupUserRemoved` — these are application-specific. Users can register their own listeners via `workos.sync.listeners` config or standard Laravel event registration.

### workos:make-listener Update

No code changes needed. `MakeListenerCommand::availableEvents()` iterates `WebhookController::EVENT_MAP` — adding the 10 dsync entries there automatically makes them appear in the interactive selection and `--events` flag.

## Testing Requirements

### Unit Tests — `tests/Unit/Sync/WorkOSDsyncEventsTest.php`

**Key test cases**:

For each of the 10 event classes:
- Constructs with a `data` array and returns typed values from accessors
- `get()` (from `HasEventData`) returns arbitrary payload values
- Nullable accessors return `null` when key is absent (first_name, last_name, custom_attributes, previous_attributes)

Example structure (Pest):

```php
it('WorkOSDsyncUserCreated exposes typed accessors', function () {
    $event = new WorkOSDsyncUserCreated([
        'id'              => 'dir_user_01',
        'directory_id'    => 'dir_01',
        'organization_id' => 'org_01',
        'idp_id'          => 'idp_user_01',
        'email'           => 'alice@example.com',
        'first_name'      => 'Alice',
        'last_name'       => 'Smith',
        'state'           => 'active',
        'custom_attributes' => ['department' => 'Engineering'],
    ]);

    expect($event->directoryUserId())->toBe('dir_user_01')
        ->and($event->email())->toBe('alice@example.com')
        ->and($event->state())->toBe('active')
        ->and($event->customAttributes())->toBe(['department' => 'Engineering']);
});

it('WorkOSDsyncGroupUserAdded exposes nested user and group', function () {
    $event = new WorkOSDsyncGroupUserAdded([
        'directory_id' => 'dir_01',
        'user'  => ['id' => 'dir_user_01', 'email' => 'alice@example.com'],
        'group' => ['id' => 'dir_group_01', 'name' => 'Engineering'],
    ]);

    expect($event->directoryUserId())->toBe('dir_user_01')
        ->and($event->directoryGroupId())->toBe('dir_group_01')
        ->and($event->userEmail())->toBe('alice@example.com')
        ->and($event->groupName())->toBe('Engineering');
});
```

### Unit Tests — Listener Tests

**SyncDirectoryUserFromWorkOSTest key cases**:
- Returns early when `workos.dsync.user_model` is null
- Returns early when model lacks `findByWorkOSId`
- Returns early when `findByWorkOSId` returns null
- Calls `update()` with email, name, and workos_state on created event
- Calls `update()` with email, name, and workos_state on updated event
- Calls `delete()` on the user for deleted event
- Name is null when both first_name and last_name are empty

**SyncDirectoryGroupFromWorkOSTest key cases**:
- Returns early when `workos.dsync.group_model` is null
- Returns early when model lacks `where`
- Returns early when query returns null
- Calls `update(['name' => ...])` on created and updated events
- Calls `delete()` on the group for deleted event

## Validation Commands

```bash
composer analyse
composer test
```
