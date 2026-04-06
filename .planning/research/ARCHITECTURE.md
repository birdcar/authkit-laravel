# Architecture Patterns

**Domain:** Laravel authentication package with install command, testing utilities, CI pipeline, and workbench example app
**Researched:** 2026-04-06

## Recommended Architecture

The four new milestone components attach to the existing package at well-defined seams. None require changes to core auth logic. Each has a distinct boundary and communicates through a narrow interface.

```
birdcar/authkit-laravel
├── src/
│   ├── Install/                  ← Smart Install (already exists, needs enhancement)
│   │   ├── WizardFlow.php        ← Orchestrator (fully implemented)
│   │   ├── EnvironmentDetector   ← (lives in src/Support/, fully implemented)
│   │   ├── Plans/                ← Migration plans per auth system (fully implemented)
│   │   └── [installers]          ← RouteInstaller, AuthSystemInstaller, WebhookInstaller (done)
│   ├── Testing/                  ← Testing utilities (skeleton exists, needs completion)
│   │   ├── WorkOSFake.php        ← Full fake implementation (fully implemented)
│   │   └── Concerns/
│   │       └── InteractsWithWorkOS.php  ← Test trait (fully implemented)
│   └── Http/Middleware/
│       └── ShareWorkOSData.php   ← Inertia middleware (stub registered but needs impl)
├── workbench/                    ← Example app (partially built, needs completion)
│   ├── app/                      ← Laravel 12 Todo app with Livewire + Flux Pro
│   ├── tests/                    ← Feature tests using WorkOS::actingAs() pattern
│   └── composer.json             ← Path-repo symlink to parent package
└── .github/workflows/
    ├── ci.yml                    ← Tests + PHPStan + Pint (fully implemented)
    └── release.yml               ← Auto-release via birdcar/actions (fully implemented)
```

### Component Boundaries

| Component | Responsibility | Communicates With | Status |
|-----------|---------------|-------------------|--------|
| `WizardFlow` | Orchestrates install steps given detection result | `EnvironmentDetector` → `DetectionResult` → each Installer | Done |
| `EnvironmentDetector` | Reads composer.json + .env + config files, returns `DetectionResult` | Filesystem only; called by `InstallCommand` | Done |
| Migration Plans (`Plans/`) | Generates actionable migration guidance documents per auth system | `MigrationPlanGenerator` reads `DetectionResult`, writes to `storage/` | Done |
| `WorkOSFake` | In-memory test double for the `workos` container binding | Swapped via `app()->instance('workos', $fake)` in `WorkOS::fake()` | Done |
| `InteractsWithWorkOS` | Test trait surfacing `actingAsWorkOS()` / `fakeWorkOS()` in test cases | Delegates to `WorkOS::fake()` / `WorkOS::actingAs()` | Done |
| `ShareWorkOSData` | Inertia middleware that shares auth state with frontend | `SessionManager` → Inertia shared data bag | Registered, needs impl |
| Workbench example app | Full Laravel 12 Todo app demonstrating all package features | Composer path-repo symlink to parent; uses `actingAs($user, 'workos')` in tests | Partial |
| CI pipeline (ci.yml) | Matrix tests (PHP 8.3/8.4 × Laravel 11/12) + static analysis + code style | Composer scripts: `test`, `analyse`, `format:test` | Done |
| Release pipeline (release.yml) | Automated semver tagging + CHANGELOG on push to main | `birdcar/actions/auto-release@main` | Done |

### Data Flow

**Smart Install flow:**
```
php artisan workos:install
  → InstallCommand::handle()
  → EnvironmentDetector::detect()
      reads: composer.json (hasBreeze/Jetstream/Fortify/laravel-workos)
      reads: config/services.php (hasServicesWorkosConfig)
      reads: .env (existing WORKOS_* vars)
      returns: DetectionResult (immutable value object)
  → WizardFlow::run($command, $detection)
      step 1: if hasLaravelWorkos → askLaravelWorkosStrategy() (replace/augment/keep)
      step 2: if hasExistingAuth → MigrationPlanGenerator::generate() → storage/workos-migration-plan.md
      step 3: askComponentSelection() → ['routes', 'auth-system', 'webhooks']
      step 4: EnvManager::planChanges() → confirm .env modifications
      step 5: if auth-system selected → confirmMigrations()
      step 6: executeInstallation()
          → LaravelWorkosMigrator::migrate() if replace strategy
          → RouteInstaller / AuthSystemInstaller / WebhookInstaller per selection
          → EnvManager::applyChanges()
          → artisan migrate if confirmed
```

**Fake / actingAs flow:**
```
WorkOS::fake()
  → new WorkOSFake()
  → app()->instance('workos', $fake)   ← replaces singleton in container
  → returns $fake for chaining

WorkOS::actingAs($user, $roles, $permissions, $orgId)
  → WorkOS::fake()->actingAs(...)
  → $fake->buildSession()              ← creates WorkOSSession with fake tokens
  → $user->setWorkOSSession($session)  ← if trait present (optional)
  → auth('workos')->login($user)       ← authenticates via Laravel guard
  → returns $fake for assertion chaining

$fake->assertAudited('user.login')
  → searches $auditedEvents array
  → PHPUnit::assertNotEmpty(...)

WorkOS::restore()                      ← called in tearDown via InteractsWithWorkOS
  → app()->forgetInstance('workos')
  → self::$fake = null
```

**Workbench test flow:**
```
workbench/tests/Feature/AuthTest.php
  extends Tests\TestCase
    extends Illuminate\Foundation\Testing\TestCase (NOT orchestra/testbench)
  $this->actingAs($user, 'workos')     ← standard Laravel actingAs with guard name
  → WorkOSGuard::setUser($user)
  → guard resolves user on subsequent request
  Livewire::actingAs($user, 'workos')  ← Livewire's version, same guard
```

**CI data flow:**
```
PR opened → ci.yml triggered
  parallel jobs:
    tests (matrix: php[8.3,8.4] × laravel[11.*,12.*]):
      composer require "illuminate/support:$laravel" --no-update
      composer update
      composer test  →  vendor/bin/pest
    static-analysis (php 8.3 only):
      composer analyse  →  phpstan analyse src --level=8
    code-style (php 8.3 only):
      composer format:test  →  pint --test

push to main → release.yml triggered
  birdcar/actions/auto-release → reads PR labels → bumps semver → tags → updates CHANGELOG.md
```

## Patterns to Follow

### Pattern 1: Facade Swap for Testing (WorkOS::fake())

The `WorkOS` service class holds a static `$fake` property and two static methods (`fake()`, `actingAs()`). When `fake()` is called, it replaces the `workos` container singleton with the fake instance. All subsequent calls through the `WorkOS` facade or `workos()` helper hit the fake.

This is the same pattern used by Laravel's built-in `Mail::fake()`, `Queue::fake()`, `Http::fake()`. The key insight is using `app()->instance('workos', $fake)` — this bypasses the singleton factory and returns the pre-built fake directly.

The `restore()` method calls `app()->forgetInstance('workos')` so the next resolution re-runs the factory and gets the real implementation. This must be called in `tearDown` — the `InteractsWithWorkOS` trait's `tearDownWorkOS()` handles this.

```php
// In WorkOS.php (already exists)
public static function fake(): WorkOSFake
{
    self::$fake = new WorkOSFake;
    app()->instance('workos', self::$fake);
    return self::$fake;
}
```

### Pattern 2: Workbench as a Standalone Laravel App

The `workbench/` directory is a complete Laravel 12 application with its own `composer.json`. The parent package is referenced as a path repository with `"symlink": true`, so edits to `src/` are reflected immediately without `composer update`. The workbench app uses `"workos/authkit-laravel": "@dev"` as a regular dependency.

This pattern means:
- The workbench runs as a real app (`composer serve` or `cd workbench && php artisan serve`)
- Its tests (`composer test:example`) use `Illuminate\Foundation\Testing\TestCase`, not `orchestra/testbench`
- The workbench provides real-world validation that the package works end-to-end
- Feature tests in workbench demonstrate how consumers should use the package's testing utilities

### Pattern 3: Install Command with Detection-then-Action Separation

The install command separates environment detection (read-only, side-effect-free) from installation (write operations). `EnvironmentDetector::detect()` runs first and produces a `DetectionResult` value object. All subsequent decisions are made from that snapshot — no re-reading of files mid-installation.

The `WizardFlow` receives both the Artisan `$command` (for I/O) and the `DetectionResult` (for context). Individual installers (`RouteInstaller`, `AuthSystemInstaller`, etc.) receive only the `$command` — they are unaware of the detection context. This separation makes each installer independently testable.

### Pattern 4: Matrix CI for Package Compatibility

Laravel packages must declare support for a range of framework versions. The CI matrix tests every supported combination (`php: ['8.3', '8.4']` × `laravel: ['11.*', '12.*']`). The trick is `composer require "illuminate/support:${{ matrix.laravel }}" --no-update` followed by `composer update` — this forces Composer to resolve the correct framework version without editing `composer.json`.

Static analysis and code style checks run on a single PHP version (8.3) since they don't depend on framework version — this avoids redundant work.

## Anti-Patterns to Avoid

### Anti-Pattern 1: Workbench Tests Using orchestra/testbench

The workbench is a real Laravel application. Its tests should extend `Illuminate\Foundation\Testing\TestCase`, not `Orchestra\Testbench\TestCase`. Testbench is for unit/feature tests inside the `tests/` directory (which run against an in-memory Laravel app). Mixing them causes two different app instances and confusing test failures.

**Instead:** Keep the boundary clear — `tests/` uses testbench, `workbench/tests/` uses the real app's TestCase.

### Anti-Pattern 2: `WorkOS::fake()` Without `restore()` in tearDown

If `fake()` is called in a test but the singleton is not restored, subsequent tests in the same process get the fake instead of the real service. This causes cascading failures that are hard to diagnose.

**Instead:** Always pair `fake()` with `restore()` in `tearDown`. The `InteractsWithWorkOS::tearDownWorkOS()` method handles this — test classes using the trait must call it, or use `afterEach(fn () => WorkOS::restore())` in Pest.

### Anti-Pattern 3: Migration Plans That Execute Automatically

The `MigrationPlanGenerator` writes a Markdown file to `storage/`. It never modifies application code automatically. Automatically touching `app/Http/Kernel.php`, `config/auth.php`, or `bootstrap/app.php` during install creates irreversible diffs that are hard to review and can break the host app.

**Instead:** The migration plan is advisory — it tells the developer what to do, not does it for them. The install command only modifies `.env`, publishes config, and optionally runs migrations (with explicit confirmation).

### Anti-Pattern 4: Fake Implementing the Full SDK Interface

`WorkOSFake` does not extend `WorkOS` or implement a shared interface. It only implements the methods that consumers actually call in their tests. Extending the full service class means dragging in SDK dependencies, config validation, and session management into test environments where none of that should run.

**Instead:** `WorkOSFake` is a standalone class that mimics the public API surface consumers need. The swap mechanism (`app()->instance`) handles the type discrepancy — the container doesn't enforce type-checking on explicit instances.

## Component Build Order

Dependencies constrain the order new work can proceed:

1. **`ShareWorkOSData` middleware** — No dependencies on other unbuilt components. Should be first since it's already registered as `workos.inertia` alias in `configureMiddleware()` but unimplemented. Unimplemented middleware alias causes silent failures if consumed.

2. **Workbench example app completion** — Depends on `ShareWorkOSData` for the Inertia demo. The core Todo app with Livewire works now (models, controllers, basic feature tests exist). Admin Portal intents and more complete feature demonstrations come after ShareWorkOSData exists.

3. **Workbench test suite** — Depends on workbench app being feature-complete. The `actingAs($user, 'workos')` pattern already works. What's missing is tests demonstrating `WorkOS::fake()` / `WorkOS::actingAs()` usage in the workbench context.

4. **CI pipeline enhancement** — Already fully implemented (`ci.yml`, `release.yml`). The only gap is that `composer test:example` (running workbench tests) is not part of CI — it requires Flux Pro credentials which can't be in public CI. This is an accepted constraint.

5. **Smart Install enhancement** — The detection and wizard flow are complete. The three-mode requirement (`--force`, wizard default, `--mini`) needs to be added to `InstallCommand` — the `WizardFlow` already supports the wizard mode, but `--force` and `--mini` flags don't exist yet on the command itself.

## Scalability Considerations

This is a library package, not a deployed service. Scalability here means compatibility surface:

| Concern | Current | Gap |
|---------|---------|-----|
| PHP version support | 8.3, 8.4 in CI | PHP 9.0 when released — matrix update only |
| Laravel version support | 11.*, 12.* | Laravel 13 — add to matrix, fix any deprecations |
| WorkOS SDK version | ^4.29 pinned | SDK breaking changes require coordinated updates |
| Workbench Flux Pro | Requires auth.json with Flux credentials | Cannot run workbench tests in public CI |

## Sources

- Codebase: `/Users/birdcar/Code/birdcar/authkit-laravel/src/` (read directly, HIGH confidence)
- CI workflows: `.github/workflows/ci.yml`, `.github/workflows/release.yml` (read directly, HIGH confidence)
- Workbench: `workbench/` directory structure, `workbench/tests/`, `workbench/composer.json` (read directly, HIGH confidence)
- Laravel facade fake() pattern: https://saeedvaziry.com/posts/laravel-custom-helpers-facades-and-testing-fakes/ (MEDIUM confidence, aligns with codebase)
- GitHub Actions Laravel matrix pattern: https://freek.dev/1546-using-github-actions-to-run-the-tests-of-laravel-projects-and-packages (MEDIUM confidence, aligns with ci.yml)
- orchestra/testbench + workbench integration: https://packages.tools/testbench (MEDIUM confidence — testbench.yaml not present in this repo; workbench runs as standalone app instead)
