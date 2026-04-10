# Implementation Spec: Smart Install - Phase 2

**PRD**: ./prd-phase-2.md
**Estimated Effort**: L (Large)

## Technical Approach

Phase 2 builds the interactive wizard that guides users through component selection and handles the laravel/workos coexistence question. The wizard uses Laravel's built-in console components (choice, confirm, table) for a consistent experience.

The wizard flow is driven by the DetectionResult from Phase 1. We'll create a `WizardFlow` class that encapsulates the decision tree and state machine for the wizard. Each "step" is a method that may or may not run depending on detection results.

Component installation is handled by dedicated installer classes (`RouteInstaller`, `AuthSystemInstaller`, `WebhookInstaller`) that follow a common interface. This allows the wizard to compose installations based on user choices.

For laravel/workos handling, we'll create a `LaravelWorkosmigrater` that can migrate config from services.php to workos.php and optionally remove the package.

## File Changes

### New Files

| File Path | Purpose |
|-----------|---------|
| `src/Install/WizardFlow.php` | Orchestrates the interactive wizard flow |
| `src/Install/ComponentInstaller.php` | Interface for component installers |
| `src/Install/RouteInstaller.php` | Installs auth routes only |
| `src/Install/AuthSystemInstaller.php` | Installs full auth system (guards, providers, model guidance) |
| `src/Install/WebhookInstaller.php` | Installs webhook routes and handlers |
| `src/Install/LaravelWorkosMigrator.php` | Migrates from laravel/workos to authkit-laravel |
| `src/Install/EnvManager.php` | Manages .env file modifications |
| `tests/Unit/WizardFlowTest.php` | Unit tests for wizard logic |
| `tests/Unit/EnvManagerTest.php` | Unit tests for env management |

### Modified Files

| File Path | Changes |
|-----------|---------|
| `src/Commands/InstallCommand.php` | Inject WizardFlow, call it in default mode |
| `src/AuthKitServiceProvider.php` | Register new services |
| `tests/Feature/InstallCommandTest.php` | Add wizard flow tests with mocked prompts |

### Deleted Files

None.

## Implementation Details

### WizardFlow Class

**Overview**: Orchestrates the entire wizard experience, using detection results to determine which questions to ask and in what order.

```php
<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Install;

use Illuminate\Console\Command;
use WorkOS\AuthKit\Support\DetectionResult;

class WizardFlow
{
    /** @var array<string> */
    private array $selectedComponents = [];

    private ?string $laravelWorkosStrategy = null;

    public function __construct(
        private RouteInstaller $routeInstaller,
        private AuthSystemInstaller $authSystemInstaller,
        private WebhookInstaller $webhookInstaller,
        private LaravelWorkosMigrator $migrator,
        private EnvManager $envManager,
    ) {}

    public function run(Command $command, DetectionResult $detection): int
    {
        // Step 1: Handle laravel/workos if detected
        if ($detection->hasLaravelWorkos) {
            $this->laravelWorkosStrategy = $this->askLaravelWorkosStrategy($command);
        }

        // Step 2: Ask which components to install
        $this->selectedComponents = $this->askComponentSelection($command, $detection);

        // Step 3: Show env var plan and confirm
        if (!$this->confirmEnvChanges($command, $detection)) {
            $command->warn('Installation cancelled.');
            return Command::FAILURE;
        }

        // Step 4: Show migration plan and confirm
        if (!$this->confirmMigrations($command)) {
            $command->warn('Skipping migrations. Run `php artisan migrate` manually.');
        }

        // Step 5: Execute installation
        return $this->executeInstallation($command, $detection);
    }

    private function askLaravelWorkosStrategy(Command $command): string
    {
        return $command->choice(
            'laravel/workos detected. How should we proceed?',
            [
                'replace' => 'Replace entirely (migrate config, remove package)',
                'augment' => 'Augment/extend (add authkit features on top)',
                'keep' => 'Keep both (install alongside, no migration)',
            ],
            'replace'
        );
    }

    private function askComponentSelection(Command $command, DetectionResult $detection): array
    {
        $command->info('Select which components to install:');
        $command->newLine();

        $components = [];

        if ($command->confirm('Install auth routes? (login, callback, logout)', true)) {
            $components[] = 'routes';
        }

        if ($command->confirm('Install full auth system? (guards, providers, User model guidance)', true)) {
            $components[] = 'auth-system';
        }

        if ($command->confirm('Install webhooks? (user sync, event handlers)', true)) {
            $components[] = 'webhooks';
        }

        return $components;
    }

    private function confirmEnvChanges(Command $command, DetectionResult $detection): bool
    {
        $changes = $this->envManager->planChanges($detection);

        if (empty($changes['add']) && empty($changes['modify'])) {
            $command->info('No .env changes needed.');
            return true;
        }

        $command->info('The following .env changes will be made:');

        if (!empty($changes['add'])) {
            $command->line('  <fg=green>Add:</> ' . implode(', ', array_keys($changes['add'])));
        }

        if (!empty($changes['modify'])) {
            $command->line('  <fg=yellow>Modify:</> ' . implode(', ', array_keys($changes['modify'])));
        }

        return $command->confirm('Proceed with these changes?', true);
    }

    private function confirmMigrations(Command $command): bool
    {
        // Show pending migrations
        $command->info('The following migrations will run:');
        $command->call('migrate:status');

        return $command->confirm('Run migrations now?', true);
    }

    private function executeInstallation(Command $command, DetectionResult $detection): int
    {
        // Execute laravel/workos strategy if applicable
        if ($this->laravelWorkosStrategy === 'replace') {
            $this->migrator->migrate($command);
        }

        // Install selected components
        foreach ($this->selectedComponents as $component) {
            match ($component) {
                'routes' => $this->routeInstaller->install($command),
                'auth-system' => $this->authSystemInstaller->install($command),
                'webhooks' => $this->webhookInstaller->install($command),
            };
        }

        // Apply env changes
        $this->envManager->applyChanges($detection);

        // Run migrations if confirmed
        if ($this->migrationsConfirmed) {
            $command->call('migrate');
        }

        $command->info('WorkOS AuthKit installed successfully!');
        return Command::SUCCESS;
    }
}
```

**Key decisions**:
- Wizard state is held in instance properties, not persisted
- Each step method can be skipped based on detection/previous answers
- Component installers are injected for testability

**Implementation steps**:
1. Create WizardFlow class with injected dependencies
2. Implement askLaravelWorkosStrategy() with choice prompt
3. Implement askComponentSelection() with confirm prompts
4. Implement confirmEnvChanges() showing planned changes
5. Implement confirmMigrations() showing pending migrations
6. Implement executeInstallation() calling component installers
7. Wire up in InstallCommand::handleFullInstall()

### ComponentInstaller Interface

**Overview**: Common interface for all component installers.

```php
<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Install;

use Illuminate\Console\Command;

interface ComponentInstaller
{
    public function install(Command $command): void;

    public function describe(): string;
}
```

### RouteInstaller

**Overview**: Installs only the auth routes without touching other config.

```php
<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Install;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class RouteInstaller implements ComponentInstaller
{
    public function install(Command $command): void
    {
        // Routes are loaded from package automatically via config
        // This just ensures routes.enabled = true in config
        $command->components->info('Auth routes enabled: /auth/login, /auth/callback, /auth/logout');
    }

    public function describe(): string
    {
        return 'Login, callback, and logout routes';
    }
}
```

### AuthSystemInstaller

**Overview**: Installs the complete auth system including guards, providers, and displays User model guidance.

```php
<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Install;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class AuthSystemInstaller implements ComponentInstaller
{
    public function install(Command $command): void
    {
        $this->publishConfig($command);
        $this->updateAuthConfig($command);
        $this->displayModelGuidance($command);
    }

    private function publishConfig(Command $command): void
    {
        $command->callSilently('vendor:publish', [
            '--tag' => 'workos-config',
            '--force' => $command->option('force'),
        ]);
        $command->components->info('Published config/workos.php');
    }

    private function updateAuthConfig(Command $command): void
    {
        // Existing auth.php update logic from current InstallCommand
    }

    private function displayModelGuidance(Command $command): void
    {
        $command->newLine();
        $command->line('<fg=yellow>Add these traits to your User model:</>');
        $command->line('  use WorkOS\AuthKit\Models\Concerns\HasWorkOSId;');
        $command->line('  use WorkOS\AuthKit\Models\Concerns\HasWorkOSPermissions;');
    }

    public function describe(): string
    {
        return 'Guards, providers, config, and User model traits';
    }
}
```

### WebhookInstaller

**Overview**: Ensures webhook routes are enabled and displays setup guidance.

```php
<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Install;

use Illuminate\Console\Command;

class WebhookInstaller implements ComponentInstaller
{
    public function install(Command $command): void
    {
        $command->components->info('Webhook route enabled: /webhooks/workos');
        $command->newLine();
        $command->line('<fg=yellow>Configure webhook in WorkOS Dashboard:</>');
        $command->line('  URL: ' . config('app.url') . '/webhooks/workos');
        $command->line('  Events: user.created, user.updated, user.deleted');
    }

    public function describe(): string
    {
        return 'Webhook endpoint for user sync';
    }
}
```

### LaravelWorkosMigrator

**Overview**: Migrates configuration from laravel/workos (services.php) to authkit-laravel (workos.php).

```php
<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Install;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class LaravelWorkosMigrator
{
    public function migrate(Command $command): void
    {
        $this->migrateConfig($command);
        $this->suggestPackageRemoval($command);
    }

    private function migrateConfig(Command $command): void
    {
        $servicesPath = config_path('services.php');

        if (!File::exists($servicesPath)) {
            return;
        }

        $services = include $servicesPath;

        if (!isset($services['workos'])) {
            $command->components->info('No WorkOS config found in services.php');
            return;
        }

        $workosConfig = $services['workos'];

        $command->components->info('Migrating WorkOS config from services.php to workos.php');

        // Values are already in .env, just need to ensure workos.php uses them
        // The config file we publish already reads from env()
    }

    private function suggestPackageRemoval(Command $command): void
    {
        $command->newLine();
        $command->warn('To complete migration, remove laravel/workos:');
        $command->line('  <fg=cyan>composer remove laravel/workos</>');
    }
}
```

### EnvManager

**Overview**: Safely manages .env file modifications, detecting existing vars and avoiding duplicates.

```php
<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Install;

use Illuminate\Support\Facades\File;
use WorkOS\AuthKit\Support\DetectionResult;

class EnvManager
{
    public function __construct(
        private string $envPath,
    ) {}

    /**
     * @return array{add: array<string, string>, modify: array<string, string>}
     */
    public function planChanges(DetectionResult $detection): array
    {
        $required = [
            'WORKOS_CLIENT_ID' => '',
            'WORKOS_API_KEY' => '',
            'WORKOS_REDIRECT_URL' => config('app.url') . '/auth/callback',
        ];

        $add = [];
        $modify = [];

        foreach ($required as $key => $default) {
            if (!$detection->hasEnvVar($key)) {
                // Check for WORKOS_REDIRECT_URI as fallback
                if ($key === 'WORKOS_REDIRECT_URL' && $detection->hasEnvVar('WORKOS_REDIRECT_URI')) {
                    // Already has the old var name, don't add new one
                    continue;
                }
                $add[$key] = $default;
            }
        }

        return ['add' => $add, 'modify' => $modify];
    }

    public function applyChanges(DetectionResult $detection): void
    {
        $changes = $this->planChanges($detection);

        if (empty($changes['add'])) {
            return;
        }

        $envContent = File::exists($this->envPath)
            ? File::get($this->envPath)
            : '';

        foreach ($changes['add'] as $key => $value) {
            $envContent .= "\n{$key}={$value}";
        }

        File::put($this->envPath, $envContent);
    }
}
```

**Key decisions**:
- Use WORKOS_REDIRECT_URL (Laravel convention) for new installs
- Preserve existing WORKOS_REDIRECT_URI if present (backward compatibility)
- Never duplicate env vars

**Implementation steps**:
1. Create EnvManager with path injection
2. Implement planChanges() to diff required vs existing
3. Implement applyChanges() to append missing vars
4. Handle WORKOS_REDIRECT_URI -> WORKOS_REDIRECT_URL fallback

## Testing Requirements

### Unit Tests

| Test File | Coverage |
|-----------|----------|
| `tests/Unit/WizardFlowTest.php` | Wizard decision logic |
| `tests/Unit/EnvManagerTest.php` | Env file management |
| `tests/Unit/LaravelWorkosMigratorTest.php` | Config migration logic |

**Key test cases**:
- WizardFlow skips laravel/workos question when not detected
- WizardFlow asks laravel/workos question when detected
- Component selection returns correct array based on confirms
- EnvManager doesn't add duplicate WORKOS_CLIENT_ID
- EnvManager preserves WORKOS_REDIRECT_URI when present
- LaravelWorkosMigrator reads config from services.php

### Integration Tests

| Test File | Coverage |
|-----------|----------|
| `tests/Feature/InstallCommandTest.php` | Full wizard flow |

**Key scenarios**:
- Wizard flow on fresh Laravel (all questions asked)
- Wizard flow with laravel/workos detected (migration question asked)
- Selecting only Routes component installs only routes
- Selecting Full auth system updates auth.php
- Env vars added to .env file
- Migrations run when confirmed

### Manual Testing

- [ ] Run wizard on fresh Laravel 12 app
- [ ] Run wizard on Laravel 12 app created with WorkOS starter kit
- [ ] Verify "Replace entirely" removes laravel/workos config guidance
- [ ] Verify .env doesn't have duplicate WORKOS_* vars after install
- [ ] Verify migrations run when confirmed in wizard

## Error Handling

| Error Scenario | Handling Strategy |
|----------------|-------------------|
| User presses Ctrl+C during wizard | Gracefully exit, no partial changes |
| .env file is read-only | Catch exception, display manual instructions |
| composer.json malformed | Warn and continue, assume no existing packages |
| Invalid choice input | Laravel handles via choice component (re-prompts) |
| Migration fails | Display error, suggest manual fix |

## Validation Commands

```bash
# Type checking
./vendor/bin/phpstan analyse

# Linting
./vendor/bin/pint --test

# Unit tests
./vendor/bin/pest tests/Unit

# Feature tests
./vendor/bin/pest tests/Feature

# All tests
./vendor/bin/pest
```

## Rollout Considerations

- **Feature flag**: None needed
- **Monitoring**: N/A
- **Alerting**: N/A
- **Rollback plan**: Previous InstallCommand still works, new features are additive

## Open Items

- [ ] Decide how "Augment/extend" mode should work (which features conflict?)
- [ ] Decide whether to actually run `composer remove laravel/workos` or just suggest it

---

*This spec is ready for implementation. Follow the patterns and validate at each step.*
