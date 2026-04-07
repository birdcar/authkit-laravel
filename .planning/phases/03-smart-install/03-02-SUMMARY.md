---
phase: 03-smart-install
plan: 02
subsystem: install
tags: [laravel, artisan, install-command, wizard, env-management, migration-plan]

# Dependency graph
requires:
  - phase: 03-01
    provides: WizardFlow, EnvManager, MigrationPlanGenerator, LaravelWorkosMigrator with basic structure
provides:
  - --force flag bypasses all wizard prompts in WizardFlow, LaravelWorkosMigrator, AuthSystemInstaller
  - --mini writes empty placeholder env vars via EnvManager::applyChanges()
  - --mini generates migration plan file to storage/ when existing auth detected
  - LaravelWorkosMigratorTest with 6 isolated unit tests
  - InstallCommandTest with 3 new mini-mode coverage tests
affects: [03-03, testing, workbench]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Force bypass: early return on $command->option('force') in each prompt method"
    - "TDD: RED tests written first, then implementation to GREEN"
    - "Mini-mode delegation: InstallCommand holds EnvManager and MigrationPlanGenerator for non-wizard path"

key-files:
  created:
    - tests/Unit/LaravelWorkosMigratorTest.php
  modified:
    - src/Install/WizardFlow.php
    - src/Install/LaravelWorkosMigrator.php
    - src/Install/AuthSystemInstaller.php
    - src/Commands/InstallCommand.php
    - tests/Unit/WizardFlowTest.php
    - tests/Feature/InstallCommandTest.php

key-decisions:
  - "MigrationPlanGenerator::generate() already writes the file to storage/ internally; InstallCommand just reports the returned path rather than re-writing"
  - "EnvManager and MigrationPlanGenerator injected directly into InstallCommand constructor (not only WizardFlow) so --mini path can use them without wizard"
  - "Renamed 'mini install skips migration plan generation' test to clarify it only applies to fresh installs, not Breeze/Jetstream/Fortify"

patterns-established:
  - "Force bypass pattern: if (\$command->option('force')) { return <default>; } at top of each prompt method"
  - "Mini path injects services via constructor, calls applyChanges() unconditionally, calls generate() only when hasExistingAuth()"

requirements-completed: [INST-02, INST-03, INST-04, INST-05, INST-06]

# Metrics
duration: 35min
completed: 2026-04-06
---

# Phase 3 Plan 02: Smart Install -- Force and Mini Hardening Summary

**--force bypasses all install prompts and auto-selects replace strategy; --mini writes placeholder env vars and migration plan files via injected EnvManager and MigrationPlanGenerator**

## Performance

- **Duration:** ~35 min
- **Started:** 2026-04-06T22:15:00Z
- **Completed:** 2026-04-06T22:50:00Z
- **Tasks:** 2
- **Files modified:** 6 (+ 1 created)

## Accomplishments

- WizardFlow, LaravelWorkosMigrator, and AuthSystemInstaller all have `--force` early-return bypass in every prompt method
- InstallCommand `--mini` path now calls `envManager->applyChanges()` to write placeholder env vars without prompting
- InstallCommand `--mini` generates and reports migration plan path when Breeze/Jetstream/Fortify detected
- LaravelWorkosMigratorTest covers services.php cleanup (success + regex-fail), package removal (success + failure), and force bypass
- 52 tests passing (up from 49), PHPStan level 8 clean, Pint passes

## Task Commits

1. **Task 1: --force bypass + LaravelWorkosMigratorTest** - `add644e` (feat)
2. **Task 2: --mini env placeholders and migration plan** - `77d63c4` (feat)

## Files Created/Modified

- `src/Install/WizardFlow.php` - Added force early-returns to askLaravelWorkosStrategy, askComponentSelection, confirmEnvChanges, confirmMigrations
- `src/Install/LaravelWorkosMigrator.php` - Added `$command->option('force')` check in handlePackageRemoval and handleServicesConfigCleanup
- `src/Install/AuthSystemInstaller.php` - Force bypass already present; confirmed in place
- `src/Commands/InstallCommand.php` - Added EnvManager + MigrationPlanGenerator constructor params; updated handleMiniInstall
- `tests/Unit/WizardFlowTest.php` - Added force mock to existing tests; added 4 force-mode test cases
- `tests/Unit/LaravelWorkosMigratorTest.php` - Created with 6 unit tests for migrator behavior
- `tests/Feature/InstallCommandTest.php` - Updated mini-skip test; added 3 new mini-mode tests

## Decisions Made

- `MigrationPlanGenerator::generate()` already writes to `storage_path('workos-migration-plan.md')` — InstallCommand uses the returned path for the info message rather than re-writing a framework-specific file. This avoids a `File::get()` call on a mock-returned path during tests.
- `EnvManager` and `MigrationPlanGenerator` are injected into `InstallCommand` directly (not only via WizardFlow) so the `--mini` path can call them without going through the wizard.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Removed redundant File::get/put in handleMiniInstall**
- **Found during:** Task 2 (implementing mini migration plan writing)
- **Issue:** Plan spec called `File::put($storagePath, $migrationPlan)` where `$migrationPlan` was described as markdown content, but `generate()` returns a file path (not content) and already writes the file internally. Attempting `File::get($returnedPath)` failed in tests because mocked generate() returns a path string without creating the actual file.
- **Fix:** Used the returned path directly for the info message — no re-writing needed since generate() already persisted the file.
- **Files modified:** src/Commands/InstallCommand.php
- **Verification:** `mini install with existing auth writes migration plan to storage` test passes
- **Committed in:** 77d63c4 (Task 2 commit)

---

**Total deviations:** 1 auto-fixed (Rule 1 - bug)
**Impact on plan:** Minimal — plan spec had an incorrect assumption about generate()'s return type. Fix aligns behavior with the existing MigrationPlanGenerator contract.

## Issues Encountered

- The pre-existing test `mini install skips migration plan generation` used `withBreeze()` detection but asserted generate was NOT called. Task 2 behavior inverts this (mini SHOULD call generate for Breeze). Renamed test to `mini install skips migration plan generation when no existing auth` and changed detection to `freshInstall()` to preserve the valid assertion.

## Known Stubs

None - all new behaviors are wired to real implementations.

## Next Phase Readiness

- --force and --mini modes are fully hardened with test coverage
- LaravelWorkosMigrator has isolated unit tests satisfying INST-05
- Ready for 03-03 (InstallCommand integration tests or remaining smart install work)

---
*Phase: 03-smart-install*
*Completed: 2026-04-06*
