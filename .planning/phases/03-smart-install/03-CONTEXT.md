# Phase 3: Smart Install - Context

**Gathered:** 2026-04-06
**Status:** Ready for planning

<domain>
## Phase Boundary

Developers can run `workos:install` against any Laravel app — including those with Breeze, Jetstream, Fortify, or laravel/workos — and get a correct, non-destructive installation. Three modes: wizard (default), `--force` (overwrite Laravel config), `--mini` (config + env placeholders + instructions).

</domain>

<decisions>
## Implementation Decisions

### WorkOS CLI Integration
- **D-01:** Detect Node tooling (npm/bun/pnpm) at install start. If available, delegate env/credential setup to `npx|bunx workos@latest install` before handling Laravel-specific config.
- **D-02:** If no Node tooling detected, fall back to self-contained env setup (current EnvManager behavior).
- **D-03:** For verification/diagnostics, use `npx|bunx workos@latest doctor` when Node tooling is available instead of building our own doctor command.
- **D-04:** `--force` only force-overwrites Laravel config files (auth.php, User model, etc.). WorkOS CLI handles its own env setup regardless of `--force`.

### --mini Behavior
- **D-05:** `--mini` publishes `config/workos.php`, detects existing WORKOS_ env vars, writes placeholders for missing ones (no prompting), and prints remaining manual steps.
- **D-06:** `--mini` does NOT prompt for API key/client ID values — just writes empty placeholders for missing vars.

### Conflict Detection
- **D-07:** Detection stays at composer.json + config file level (current EnvironmentDetector scope). No route/middleware/model scanning — too fragile for an install command.
- **D-08:** When existing auth detected (Breeze/Jetstream/Fortify), warn and continue. No blocking without `--force`. Show migration plan and let wizard proceed.

### Idempotency & Verification
- **D-09:** Post-write verification (INST-07): if an automated file edit fails (regex didn't match), fall back to printing exact manual instructions. Never silently skip.
- **D-10:** Re-run safety (INST-08): detect existing entries (guard in auth.php, traits in User model, env vars) and skip them with an info message. No duplicates.

### Migration Guidance
- **D-11:** When existing auth detected, print migration summary in console AND write detailed plan to storage/ for reference.
- **D-12:** Migration plans are actionable numbered steps: specific files to change, what to remove, what to keep. Concrete enough to follow without external docs.

### Claude's Discretion
- Node runtime detection implementation (which command to check: `which node`, `which npx`, etc.)
- Exact console output formatting for migration summaries
- Whether to add verification assertions to component installers or centralize in WizardFlow
- How to handle WorkOS CLI failures gracefully (e.g., user cancels, network error)

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Install Command & Flow
- `src/Commands/InstallCommand.php` — Entry point with `--force` and `--mini` flags
- `src/Install/WizardFlow.php` — Wizard orchestrator (6-step flow)
- `src/Install/ComponentInstaller.php` — Interface for installable components

### Environment Detection
- `src/Support/EnvironmentDetector.php` — Composer + config file detection
- `src/Support/DetectionResult.php` — Detection output model (referenced in InstallCommand + WizardFlow)

### Component Installers
- `src/Install/AuthSystemInstaller.php` — Guard, config, migrations, User model traits
- `src/Install/RouteInstaller.php` — Auth route enablement
- `src/Install/WebhookInstaller.php` — Webhook route setup
- `src/Install/EnvManager.php` — .env file reading and writing

### Migration & laravel/workos Handling
- `src/Install/LaravelWorkosMigrator.php` — laravel/workos package replacement flow
- `src/Install/MigrationPlanGenerator.php` — Generates per-package migration plans
- `src/Install/Plans/BreezeMigrationPlan.php` — Breeze-specific migration steps
- `src/Install/Plans/JetstreamMigrationPlan.php` — Jetstream-specific migration steps
- `src/Install/Plans/FortifyMigrationPlan.php` — Fortify-specific migration steps

### Existing Tests
- `tests/Feature/InstallCommandTest.php` — Install command feature tests
- `tests/Unit/WizardFlowTest.php` — Wizard flow unit tests
- `tests/Unit/EnvironmentDetectorTest.php` — Environment detection tests
- `tests/Helpers/DetectionResultFactory.php` — Test factory for DetectionResult

### External Reference
- WorkOS CLI: `https://github.com/workos/cli` — `workos install` and `workos doctor` commands that our install should delegate to when Node tooling is available

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `EnvironmentDetector`: Complete with Breeze/Jetstream/Fortify/laravel-workos detection via composer.json parsing + config file checks
- `EnvManager`: Handles .env reading and writing with duplicate detection — reusable for --mini and fallback (no Node) scenarios
- `MigrationPlanGenerator` + per-package Plans: Already generate actionable markdown for Breeze/Jetstream/Fortify migrations
- `LaravelWorkosMigrator`: Handles laravel/workos → authkit-laravel migration (config, package removal, services.php cleanup)
- `AuthSystemInstaller`: Already handles guard config, User model trait injection, migrations, Organization model
- `WizardFlow`: 6-step orchestrator with component selection, env confirmation, migration confirmation
- `DetectionResultFactory` (test helper): Pre-built factory for creating test detection scenarios

### Established Patterns
- `ComponentInstaller` interface: `install(Command $command): void` + `describe(): string`
- Console output uses `$command->components->info()`, `$command->line()` with ANSI color tags
- File modifications use regex `preg_replace` with fallback to manual instructions (partially — needs hardening)
- `Process::run()` for external commands (see LaravelWorkosMigrator for `composer remove`)

### Integration Points
- `InstallCommand` → `EnvironmentDetector` → `DetectionResult` → `WizardFlow`
- `WizardFlow` → component installers (RouteInstaller, AuthSystemInstaller, WebhookInstaller)
- `WizardFlow` → `EnvManager` for .env changes
- `WizardFlow` → `LaravelWorkosMigrator` when laravel/workos detected with "replace" strategy
- New: Need to add Node runtime detection and WorkOS CLI invocation upstream of WizardFlow

</code_context>

<specifics>
## Specific Ideas

- Use `npx|bunx workos@latest install` (not a globally installed binary) — works without pre-installation
- Detect available package runner: check for `bun` first (user preference per CLAUDE.md), then `npx`, then `pnpm dlx`
- WorkOS CLI doctor should be suggested post-install as a verification step when Node tooling is available

</specifics>

<deferred>
## Deferred Ideas

- `workos:doctor` artisan command (DX-V2-01) — v2 scope, reference WorkOS CLI doctor for now
- `workos:upgrade` artisan command (DX-V2-02) — v2 scope
- Laravel Herd/Valet HTTPS setup guidance (DX-V2-03) — v2 scope

</deferred>

---

*Phase: 03-smart-install*
*Context gathered: 2026-04-06*
