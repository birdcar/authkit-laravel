# Implementation Spec: Smart Install - Phase 3

**PRD**: ./prd-phase-3.md
**Estimated Effort**: L (Large)

## Technical Approach

Phase 3 completes the smart-install feature with the migration assistant for existing apps and comprehensive test coverage. The migration assistant generates detailed, project-specific guidance for developers moving from Breeze, Jetstream, or Fortify to WorkOS AuthKit.

The core design is a `MigrationPlanGenerator` that takes a DetectionResult and produces a Markdown document with actionable steps. Each auth system (Breeze, Jetstream, Fortify) has its own plan template with system-specific guidance. The generator fills in project-specific details (file paths, features detected) into these templates.

For testing, we'll create test fixtures representing each auth system state and use Laravel's testing utilities to mock filesystem state.

## File Changes

### New Files

| File Path | Purpose |
|-----------|---------|
| `src/Install/MigrationPlanGenerator.php` | Generates migration plan markdown |
| `src/Install/Plans/BreezeMigrationPlan.php` | Breeze-specific migration guidance |
| `src/Install/Plans/JetstreamMigrationPlan.php` | Jetstream-specific migration guidance |
| `src/Install/Plans/FortifyMigrationPlan.php` | Fortify-specific migration guidance |
| `src/Install/Plans/MigrationPlan.php` | Interface for migration plans |
| `tests/Unit/MigrationPlanGeneratorTest.php` | Unit tests for plan generation |
| `tests/Feature/WizardFlowTest.php` | Feature tests for complete wizard |
| `tests/Fixtures/breeze-composer.json` | Fixture: Breeze project composer.json |
| `tests/Fixtures/jetstream-composer.json` | Fixture: Jetstream project composer.json |
| `tests/Fixtures/fortify-composer.json` | Fixture: Fortify project composer.json |
| `tests/Fixtures/workos-starter-composer.json` | Fixture: WorkOS starter kit composer.json |

### Modified Files

| File Path | Changes |
|-----------|---------|
| `src/Install/WizardFlow.php` | Add migration plan generation step |
| `src/Commands/InstallCommand.php` | Display migration plan when existing auth detected |
| `tests/Feature/InstallCommandTest.php` | Add comprehensive scenario tests |

### Deleted Files

None.

## Implementation Details

### MigrationPlan Interface

**Overview**: Common interface for all migration plan generators.

```php
<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Install\Plans;

interface MigrationPlan
{
    public function generate(string $projectPath): string;

    public function getRiskLevel(): string;

    public function getSummary(): string;
}
```

### MigrationPlanGenerator

**Overview**: Factory that selects the appropriate plan based on detection results and generates the markdown.

```php
<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Install;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use WorkOS\AuthKit\Install\Plans\BreezeMigrationPlan;
use WorkOS\AuthKit\Install\Plans\FortifyMigrationPlan;
use WorkOS\AuthKit\Install\Plans\JetstreamMigrationPlan;
use WorkOS\AuthKit\Install\Plans\MigrationPlan;
use WorkOS\AuthKit\Support\DetectionResult;

class MigrationPlanGenerator
{
    public function __construct(
        private string $storagePath,
    ) {}

    public function generate(DetectionResult $detection, string $projectPath): ?string
    {
        $plan = $this->selectPlan($detection);

        if ($plan === null) {
            return null;
        }

        $markdown = $plan->generate($projectPath);
        $outputPath = $this->storagePath . '/workos-migration-plan.md';

        File::put($outputPath, $markdown);

        return $outputPath;
    }

    public function displaySummary(Command $command, DetectionResult $detection): void
    {
        $plan = $this->selectPlan($detection);

        if ($plan === null) {
            return;
        }

        $command->newLine();
        $command->warn('Existing authentication system detected');
        $command->line("  <fg=cyan>System:</> {$plan->getSummary()}");
        $command->line("  <fg=cyan>Risk Level:</> {$plan->getRiskLevel()}");
        $command->newLine();
    }

    private function selectPlan(DetectionResult $detection): ?MigrationPlan
    {
        if ($detection->hasBreeze) {
            return new BreezeMigrationPlan();
        }

        if ($detection->hasJetstream) {
            return new JetstreamMigrationPlan();
        }

        if ($detection->hasFortify) {
            return new FortifyMigrationPlan();
        }

        return null;
    }
}
```

**Key decisions**:
- Plans are separate classes for maintainability
- Generator writes to storage/ directory
- Summary is displayed in console, full plan is in file

**Implementation steps**:
1. Create MigrationPlan interface
2. Create MigrationPlanGenerator with plan selection logic
3. Implement generate() to create and save markdown
4. Implement displaySummary() for console output

### BreezeMigrationPlan

**Overview**: Generates Breeze-specific migration guidance.

```php
<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Install\Plans;

use Illuminate\Support\Facades\File;

class BreezeMigrationPlan implements MigrationPlan
{
    public function generate(string $projectPath): string
    {
        $stack = $this->detectBreezeStack($projectPath);

        return <<<MARKDOWN
# Migration Plan: Laravel Breeze to WorkOS AuthKit

**Generated**: {$this->timestamp()}
**Detected Stack**: {$stack}
**Risk Level**: {$this->getRiskLevel()}

## Overview

You're migrating from Laravel Breeze ({$stack} stack) to WorkOS AuthKit. This guide provides step-by-step instructions for a safe migration.

WorkOS AuthKit replaces Breeze's local authentication with WorkOS-hosted authentication, which provides:
- Social logins (Google, Microsoft, GitHub, Apple)
- Magic link authentication
- Enterprise SSO
- Passkeys

## Pre-Migration Checklist

- [ ] Backup your database
- [ ] Create a WorkOS account at https://workos.com
- [ ] Test this migration in a staging environment first
- [ ] Review your User model for custom authentication logic

## Step 1: Files to Remove

These Breeze files are no longer needed:

### Controllers
{$this->listBreezeControllers($projectPath)}

### Views
{$this->listBreezeViews($projectPath)}

### Routes
- Remove or comment out auth routes in `routes/web.php` (Breeze routes)
- WorkOS AuthKit provides its own routes at `/auth/*`

## Step 2: Database Changes

### Add workos_id column
```bash
php artisan make:migration add_workos_id_to_users_table
```

Migration content:
```php
public function up(): void
{
    Schema::table('users', function (Blueprint \$table) {
        \$table->string('workos_id')->nullable()->unique()->after('id');
    });
}
```

### Make password nullable
```bash
php artisan make:migration make_password_nullable_on_users_table
```

Migration content:
```php
public function up(): void
{
    Schema::table('users', function (Blueprint \$table) {
        \$table->string('password')->nullable()->change();
    });
}
```

## Step 3: Update User Model

Add the WorkOS traits to your User model:

```php
use WorkOS\AuthKit\Models\Concerns\HasWorkOSId;
use WorkOS\AuthKit\Models\Concerns\HasWorkOSPermissions;

class User extends Authenticatable
{
    use HasWorkOSId;
    use HasWorkOSPermissions;

    protected \$fillable = [
        'name',
        'email',
        'password',
        'workos_id', // Add this
    ];
}
```

## Step 4: Data Migration (Existing Users)

For existing users, you have two options:

### Option A: Users re-authenticate (Recommended)
Existing users will automatically be linked to their WorkOS identity when they first log in via WorkOS, matched by email address.

### Option B: Pre-link users via API
Use the WorkOS API to create users and store their workos_id before migration. See WorkOS User Management documentation.

## Step 5: Environment Variables

Ensure these are in your `.env`:
```
WORKOS_CLIENT_ID=client_...
WORKOS_API_KEY=sk_...
WORKOS_REDIRECT_URL={$this->appUrl()}/auth/callback
AUTH_GUARD=workos
```

## Step 6: Remove Breeze Package (Optional)

```bash
composer remove laravel/breeze
```

## Post-Migration Testing

- [ ] Visit /auth/login and verify redirect to WorkOS
- [ ] Complete authentication and verify user creation
- [ ] Test logout functionality
- [ ] Test existing users can log in and are linked correctly

## Rollback Plan

If migration fails:
1. Restore database backup
2. Restore removed Breeze files from git
3. Remove WorkOS AuthKit: `composer remove workos/authkit-laravel`
4. Reinstall Breeze: `composer require laravel/breeze --dev && php artisan breeze:install`

---
*Generated by WorkOS AuthKit installer*
MARKDOWN;
    }

    public function getRiskLevel(): string
    {
        return 'Medium - Existing user authentication will change';
    }

    public function getSummary(): string
    {
        return 'Laravel Breeze';
    }

    private function detectBreezeStack(string $projectPath): string
    {
        // Check for React/Vue/Livewire indicators
        if (File::exists($projectPath . '/resources/js/Pages')) {
            if (File::exists($projectPath . '/resources/js/app.tsx')) {
                return 'React + Inertia';
            }
            return 'Vue + Inertia';
        }

        if (File::exists($projectPath . '/resources/views/livewire')) {
            return 'Livewire';
        }

        return 'Blade';
    }

    private function listBreezeControllers(string $projectPath): string
    {
        $controllers = [
            'app/Http/Controllers/Auth/AuthenticatedSessionController.php',
            'app/Http/Controllers/Auth/ConfirmablePasswordController.php',
            'app/Http/Controllers/Auth/EmailVerificationNotificationController.php',
            'app/Http/Controllers/Auth/EmailVerificationPromptController.php',
            'app/Http/Controllers/Auth/NewPasswordController.php',
            'app/Http/Controllers/Auth/PasswordController.php',
            'app/Http/Controllers/Auth/PasswordResetLinkController.php',
            'app/Http/Controllers/Auth/RegisteredUserController.php',
            'app/Http/Controllers/Auth/VerifyEmailController.php',
        ];

        $existing = array_filter($controllers, fn($c) => File::exists($projectPath . '/' . $c));

        if (empty($existing)) {
            return '- No Breeze controllers found';
        }

        return implode("\n", array_map(fn($c) => "- `{$c}`", $existing));
    }

    private function listBreezeViews(string $projectPath): string
    {
        $viewPath = $projectPath . '/resources/views/auth';

        if (!File::isDirectory($viewPath)) {
            return '- No Breeze views found';
        }

        $files = File::files($viewPath);
        return implode("\n", array_map(fn($f) => "- `resources/views/auth/{$f->getFilename()}`", $files));
    }

    private function timestamp(): string
    {
        return now()->toDateTimeString();
    }

    private function appUrl(): string
    {
        return config('app.url', 'http://localhost');
    }
}
```

**Key decisions**:
- Detect Breeze stack (Blade/Livewire/React/Vue) for targeted guidance
- List actual files found in project, not generic list
- Include rollback instructions
- Provide database migration code snippets

**Implementation steps**:
1. Implement stack detection
2. Implement file listing methods
3. Generate full markdown document
4. Test with actual Breeze project

### JetstreamMigrationPlan

**Overview**: Generates Jetstream-specific migration guidance, including handling of teams and API tokens.

```php
<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Install\Plans;

class JetstreamMigrationPlan implements MigrationPlan
{
    public function generate(string $projectPath): string
    {
        $hasTeams = $this->detectTeamsFeature($projectPath);
        $hasApiTokens = $this->detectApiTokensFeature($projectPath);

        return <<<MARKDOWN
# Migration Plan: Laravel Jetstream to WorkOS AuthKit

**Generated**: {$this->timestamp()}
**Teams Feature**: {$this->bool($hasTeams)}
**API Tokens Feature**: {$this->bool($hasApiTokens)}
**Risk Level**: {$this->getRiskLevel()}

## Overview

You're migrating from Laravel Jetstream to WorkOS AuthKit. Jetstream is a more comprehensive starter kit with features beyond basic authentication.

### Feature Mapping

| Jetstream Feature | WorkOS Equivalent | Notes |
|-------------------|-------------------|-------|
| Login/Register | WorkOS AuthKit | Full replacement |
| Password Reset | WorkOS Magic Link | Handled by WorkOS |
| Email Verification | Not needed | WorkOS verifies emails |
| Two-Factor Auth | WorkOS MFA | Configure in WorkOS Dashboard |
| Profile Management | Custom | Build your own profile page |
| API Tokens | Sanctum (keep) | See guidance below |
| Teams | Custom | See guidance below |

{$this->teamsGuidance($hasTeams)}

{$this->apiTokensGuidance($hasApiTokens)}

## Step-by-Step Migration

### Step 1: Database Changes

Same as Breeze migration - add workos_id, make password nullable.

### Step 2: Update User Model

```php
use WorkOS\AuthKit\Models\Concerns\HasWorkOSId;
use WorkOS\AuthKit\Models\Concerns\HasWorkOSPermissions;

class User extends Authenticatable
{
    use HasWorkOSId;
    use HasWorkOSPermissions;
    // Keep other Jetstream traits if needed (HasApiTokens, HasTeams)
}
```

### Step 3: Route Changes

Keep profile routes if you want custom profile management.
Remove auth routes - WorkOS AuthKit provides `/auth/*`.

### Step 4: View Changes

Keep profile views if customized.
Remove auth views - WorkOS hosts the auth UI.

## Risk Considerations

- **High complexity** if using Teams feature
- **Medium complexity** if using API tokens
- **Low complexity** if basic Jetstream without Teams/API tokens

---
*Generated by WorkOS AuthKit installer*
MARKDOWN;
    }

    public function getRiskLevel(): string
    {
        return 'High - Jetstream has many integrated features';
    }

    public function getSummary(): string
    {
        return 'Laravel Jetstream';
    }

    // Helper methods...
}
```

### FortifyMigrationPlan

**Overview**: Generates Fortify-specific migration guidance.

```php
<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Install\Plans;

class FortifyMigrationPlan implements MigrationPlan
{
    public function generate(string $projectPath): string
    {
        return <<<MARKDOWN
# Migration Plan: Laravel Fortify to WorkOS AuthKit

**Generated**: {$this->timestamp()}
**Risk Level**: {$this->getRiskLevel()}

## Overview

Laravel Fortify is a headless authentication backend. You likely have custom frontend views.

### Feature Mapping

| Fortify Feature | WorkOS Equivalent |
|-----------------|-------------------|
| Login | WorkOS AuthKit |
| Registration | WorkOS User Management |
| Password Reset | WorkOS Magic Link |
| Email Verification | WorkOS |
| Two-Factor Auth | WorkOS MFA |
| Password Confirmation | WorkOS Re-authentication |

## Migration Steps

### Step 1: Disable Fortify Features

In `config/fortify.php`, disable features that WorkOS will handle:

```php
'features' => [
    // Comment out or remove:
    // Features::registration(),
    // Features::resetPasswords(),
    // Features::emailVerification(),
    // Features::twoFactorAuthentication(),
],
```

### Step 2: Keep or Remove Fortify

**Option A: Remove entirely**
```bash
composer remove laravel/fortify
```

**Option B: Keep for non-auth features**
Keep Fortify if you use it for password confirmation in sensitive areas.

### Step 3: Update Routes

Remove Fortify auth routes, keep any custom routes.

---
*Generated by WorkOS AuthKit installer*
MARKDOWN;
    }

    public function getRiskLevel(): string
    {
        return 'Medium - Fortify is headless, less to remove';
    }

    public function getSummary(): string
    {
        return 'Laravel Fortify';
    }
}
```

### WizardFlow Update

**Overview**: Add migration plan generation step to the wizard.

```php
// In WizardFlow::run()

public function run(Command $command, DetectionResult $detection): int
{
    // ... existing steps ...

    // After detection, before component selection
    if ($detection->hasExistingAuth()) {
        $this->planGenerator->displaySummary($command, $detection);

        $planPath = $this->planGenerator->generate($detection, base_path());

        if ($planPath) {
            $command->info("Migration plan saved to: {$planPath}");

            if ($command->confirm('Open migration plan in editor?', false)) {
                $this->openInEditor($planPath);
            }
        }

        if (!$command->confirm('Continue with installation?', true)) {
            return Command::SUCCESS;
        }
    }

    // ... rest of wizard flow ...
}
```

## Testing Requirements

### Unit Tests

| Test File | Coverage |
|-----------|----------|
| `tests/Unit/MigrationPlanGeneratorTest.php` | Plan generation logic |
| `tests/Unit/BreezeMigrationPlanTest.php` | Breeze-specific output |
| `tests/Unit/JetstreamMigrationPlanTest.php` | Jetstream feature detection |
| `tests/Unit/EnvironmentDetectorTest.php` | All detection scenarios |

**Key test cases**:
- Generator returns null when no existing auth
- Generator selects BreezeMigrationPlan when Breeze detected
- Breeze plan detects React stack correctly
- Breeze plan lists actual files from project
- Jetstream plan detects teams feature
- Migration plan contains valid markdown
- File is saved to storage directory

### Integration Tests

| Test File | Coverage |
|-----------|----------|
| `tests/Feature/InstallCommandTest.php` | All CLI scenarios |
| `tests/Feature/WizardFlowTest.php` | Wizard with fixtures |

**Key scenarios**:
- Fresh Laravel: no migration plan generated
- Breeze project: Breeze plan generated and saved
- Jetstream project: Jetstream plan generated with team guidance
- Fortify project: Fortify plan generated
- laravel/workos project: config migration guidance shown
- `--mini` skips migration plan generation
- `--force` skips migration plan prompts

### Manual Testing

- [ ] Run install on actual Breeze project, verify plan accuracy
- [ ] Run install on actual Jetstream project with teams, verify guidance
- [ ] Run install on Jetstream project without teams, verify different guidance
- [ ] Verify migration plan opens in editor when confirmed
- [ ] Verify full wizard flow end-to-end on fresh Laravel 12

## Error Handling

| Error Scenario | Handling Strategy |
|----------------|-------------------|
| storage/ not writable | Warn, display plan in console instead |
| Editor command fails | Catch exception, display file path |
| Multiple auth systems detected | Use highest-risk plan (Jetstream > Breeze > Fortify) |
| Unknown auth system | Skip migration plan, proceed with install |

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

# All tests with coverage
./vendor/bin/pest --coverage --min=80

# Integration test with real project
# (manual - run against actual Laravel projects)
```

## Rollout Considerations

- **Feature flag**: None needed
- **Monitoring**: N/A
- **Alerting**: N/A
- **Rollback plan**: Previous phases work independently

## Open Items

- [ ] Should migration plan include estimated time for each step?
- [ ] Should we detect custom auth implementations (non-package)?
- [ ] Add link to WorkOS support for complex migrations?

---

*This spec is ready for implementation. Follow the patterns and validate at each step.*
