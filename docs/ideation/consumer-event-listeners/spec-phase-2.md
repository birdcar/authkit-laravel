# Implementation Spec: Consumer Event Listeners - Phase 2

**Contract**: ./contract.md
**Estimated Effort**: S

## Technical Approach

Phase 2 adds an `workos:make-listener` artisan command that scaffolds a listener class with correct imports, trait usage, and type hints. The command interactively prompts for which WorkOS events to handle (multi-select) and the class name, then generates the file in `app/Listeners/`.

This follows the pattern of Laravel's built-in `make:` commands and the package's existing `workos:install` command for interactive prompts.

## Feedback Strategy

**Inner-loop command**: `vendor/bin/pest tests/Feature/MakeListenerCommandTest.php`

**Playground**: Test suite — verify the command generates correct file contents.

**Why this approach**: The command is a code generator. Testing file output is straightforward.

## File Changes

### New Files

| File Path | Purpose |
|---|---|
| `src/Commands/MakeListenerCommand.php` | Interactive artisan command for scaffolding listeners |
| `tests/Feature/MakeListenerCommandTest.php` | Tests for the make command |

### Modified Files

| File Path | Changes |
|---|---|
| `src/WorkOSServiceProvider.php` | Register the new command in `configureCommands()` |

## Implementation Details

### MakeListenerCommand

**Pattern to follow**: `src/Commands/InstallCommand.php` (interactive prompts, file generation)

**Overview**: An artisan command that prompts the user to select WorkOS events and generates a listener class.

```php
namespace WorkOS\AuthKit\Commands;

use Illuminate\Console\Command;
use WorkOS\AuthKit\Http\Controllers\WebhookController;

class MakeListenerCommand extends Command
{
    protected $signature = 'workos:make-listener
        {name? : The listener class name}
        {--events=* : Event classes to handle (skip interactive prompt)}';

    protected $description = 'Create a new WorkOS event listener';
}
```

**Interactive flow**:

1. If `--events` not provided, show a multi-select checkbox of all events from `WebhookController::EVENT_MAP` values (deduplicated — 11 unique classes), plus `WorkOSEventReceived` as a catch-all option
2. If `name` not provided, suggest a default based on selected events (e.g., selecting `WorkOSUserCreated` + `WorkOSUserUpdated` suggests `SyncUser`)
3. Generate the file at `app/Listeners/{Name}.php`

**Generated file structure** (single event):

```php
<?php

declare(strict_types=1);

namespace App\Listeners;

use WorkOS\AuthKit\Events\Sync\WorkOSUserCreated;
use WorkOS\AuthKit\Listeners\Concerns\HandlesWorkOSEvents;

class SyncUser
{
    use HandlesWorkOSEvents;

    public function handle(WorkOSUserCreated $event): void
    {
        //
    }
}
```

**Generated file structure** (multiple events):

```php
<?php

declare(strict_types=1);

namespace App\Listeners;

use WorkOS\AuthKit\Events\Sync\WorkOSUserCreated;
use WorkOS\AuthKit\Events\Sync\WorkOSUserUpdated;
use WorkOS\AuthKit\Listeners\Concerns\HandlesWorkOSEvents;

class SyncUser
{
    use HandlesWorkOSEvents;

    public function handle(WorkOSUserCreated|WorkOSUserUpdated $event): void
    {
        //
    }
}
```

**Key decisions**:

- Event list comes from `WebhookController::EVENT_MAP` values — this is the single source of truth for available events, stays in sync automatically
- Union types used for multi-event listeners — this is what Laravel's event discovery expects
- Generated class uses `handle()` method name — required for auto-discovery
- Trait is always included — consumers can remove it if they don't need the helpers
- The `$signature` supports `--events` for non-interactive use (CI, scripts, testing)
- Default name suggestion heuristic: strip `WorkOS` prefix and event verb, e.g., `WorkOSUserCreated + WorkOSUserUpdated` → `SyncUser`

**Implementation steps**:

1. Create `src/Commands/MakeListenerCommand.php`
2. Implement the interactive flow using Laravel's `choice()` / `anticipate()` prompts
3. Implement file generation with proper namespace detection (respects app namespace)
4. Register the command in `WorkOSServiceProvider::configureCommands()`
5. Write tests verifying generated file contents for single-event, multi-event, and catch-all scenarios

**Feedback loop**:

- **Playground**: `tests/Feature/MakeListenerCommandTest.php` with temp directory
- **Experiment**: Generate listeners for 1 event, 2 events, all events, catch-all
- **Check command**: `vendor/bin/pest tests/Feature/MakeListenerCommandTest.php`

## Testing Requirements

### Feature Tests

| Test File | Coverage |
|---|---|
| `tests/Feature/MakeListenerCommandTest.php` | Command output, file generation, interactive prompts |

**Key test cases**:

- Single event selection generates correct import and type hint
- Multiple event selection generates union type hint
- WorkOSEventReceived selection generates catch-all listener
- Custom class name is used when provided
- Default class name suggestion is sensible
- `--events` flag bypasses interactive prompt
- Generated file has `declare(strict_types: 1)`, correct namespace, trait usage
- File is created in `app/Listeners/` directory
- Command fails gracefully if file already exists

## Error Handling

| Error Scenario | Handling Strategy |
|---|---|
| File already exists | Prompt user to confirm overwrite, or abort |
| `app/Listeners/` directory doesn't exist | Create it (standard Laravel behavior for make commands) |
| No events selected | Show error and re-prompt |

## Failure Modes

| Component | Failure Mode | Trigger | Impact | Mitigation |
|---|---|---|---|---|
| Name suggestion | Poor default name | Events from different domains selected (user + org) | Suggest generic name like `HandleWorkOSEvents` | Consumer can override with custom name |
| File generation | Wrong app namespace | Non-standard PSR-4 config | Incorrect namespace in generated file | Read `composer.json` autoload config like Laravel's `make:` commands do |

## Validation Commands

```bash
# Static analysis
composer analyse

# All tests
composer test

# Just the new test
vendor/bin/pest tests/Feature/MakeListenerCommandTest.php
```

## Rollout Considerations

- **No config changes**: Command is a development tool, no runtime impact
- **Backwards compatible**: Additive — new command, no changes to existing behavior
- **Package version**: Included in the same minor bump as Phase 1
