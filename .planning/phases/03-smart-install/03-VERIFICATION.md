---
phase: 03-smart-install
verified: 2026-04-07T00:00:00Z
status: human_needed
score: 4/5 roadmap success criteria verified
gaps: []
human_verification:
  - test: "Run `php artisan workos:install` in a real Laravel app that has Breeze installed (composer.json contains laravel/breeze)"
    expected: "The wizard detects Breeze, prints a migration plan summary to console, writes a migration plan file to storage/, and proceeds without overwriting Breeze files"
    why_human: "EnvironmentDetector reads composer.json at $basePath, which in tests is the testbench root — not a real consumer app. We cannot verify that the full round-trip (real Breeze presence + file-based detection + non-destructive install) works in a realistic environment without running it manually."
  - test: "Run `php artisan workos:install --mini` on a fresh Laravel app with no WORKOS_ vars in .env"
    expected: "config/workos.php published, WORKOS_API_KEY=, WORKOS_CLIENT_ID=, WORKOS_REDIRECT_URI= written to .env as empty placeholders, no prompts displayed"
    why_human: "The test mocks EnvManager::applyChanges() — it asserts the method is called once but does not exercise the real .env file-write path in an end-to-end scenario. The behavior is correct in unit tests but the full consumer experience needs manual spot-check."
---

# Phase 3: Smart Install Verification Report

**Phase Goal:** Developers can run `workos:install` against any Laravel app — including those with Breeze, Jetstream, Fortify, or laravel/workos — and get a correct, non-destructive installation
**Verified:** 2026-04-07
**Status:** human_needed
**Re-verification:** No — initial verification

## Goal Achievement

### Observable Truths (Roadmap Success Criteria)

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | Running `workos:install` on an app with an existing auth system produces a migration guide instead of silently overwriting it | ✓ VERIFIED | `WizardFlow::handleMigrationPlan()` calls `planGenerator->displaySummary()` and `generate()` when `$detection->hasExistingAuth()`. `InstallCommandTest` tests for Breeze, Jetstream, Fortify all pass asserting `displaySummary` called once. |
| 2 | Running with `--force` overwrites all existing auth configuration without any prompts | ✓ VERIFIED | `WizardFlow` has early-return `if ($command->option('force'))` in `askLaravelWorkosStrategy()`, `askComponentSelection()`, `confirmEnvChanges()`, `confirmMigrations()`. `LaravelWorkosMigrator` and `AuthSystemInstaller` also bypass confirms under `--force`. 36 InstallCommandTest pass including explicit force tests. |
| 3 | Running with `--mini` publishes only the config file and prints setup instructions — no file manipulation | ? UNCERTAIN | SC wording says "no file manipulation" but phase decisions D-05/D-06 explicitly require writing env placeholders to `.env`. Implementation follows D-05 (calls `applyChanges()`). The ROADMAP SC wording is stale vs. the phase decision. The behavior (write empty placeholders, no prompting) is implemented and tested. Flagged for human verification since SC is ambiguous. |
| 4 | Running `workos:install` twice produces identical output with no duplicate env vars or conflicting configs | ✓ VERIFIED | `EnvManager.applyChanges()` has per-key `str_contains($envContent, "{$key}=")` guard. `AuthSystemInstaller.updateAuthConfig()` checks `str_contains($contents, "'workos'")` before modifying. `EnvManagerTest` "does not duplicate env vars already present in .env file on re-run" passes. `AuthSystemInstallerTest` "skips when workos guard already present" passes. |
| 5 | Every file modification step either succeeds and is verified, or falls through to printed manual instructions | ✓ VERIFIED | `AuthSystemInstaller.updateAuthConfig()` uses `$result !== $contents` check and prints manual instructions with exact PHP code when regex produces no change. `updateUserModel()` prints warn + manual use statements when `$modified` remains false. `LaravelWorkosMigrator.handleServicesConfigCleanup()` warns "Could not automatically remove WorkOS config. Please remove manually." on regex failure. All verified by 14-test AuthSystemInstallerTest + LaravelWorkosMigratorTest suite. |

**Score:** 4/5 truths fully verified (1 uncertain due to SC wording vs. implementation decision conflict)

### INST-01 Requirement Note

INST-01 requires detection "via Composer\InstalledVersions" — `Composer\InstalledVersions` is NOT used anywhere in `src/`. Detection uses `composer.json` parsing instead. This was a deliberate decision (D-07 in 03-CONTEXT.md: "Detection stays at composer.json + config file level"). The detection functionality (detecting Breeze/Jetstream/Fortify/laravel-workos) IS implemented and working — only the mechanism differs from the requirement spec. REQUIREMENTS.md still marks INST-01 as unchecked ("Pending"). This is a documentation gap, not a functional gap.

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `src/Support/NodeToolingDetector.php` | Node runner detection and WorkOS CLI delegation | ✓ VERIFIED | 94 lines, all 3 methods present (`detect()`, `runInstall()`, `runDoctor()`), uses `Process::run()` with array args |
| `tests/Unit/NodeToolingDetectorTest.php` | Unit tests (min 60 lines) | ✓ VERIFIED | 250 lines, 14 tests, uses `Process::fake()`, all pass |
| `src/Install/WizardFlow.php` | `--force` bypass in prompt methods | ✓ VERIFIED | Contains `$command->option('force')` in `askLaravelWorkosStrategy`, `askComponentSelection`, `confirmEnvChanges`, `confirmMigrations` |
| `src/Install/EnvManager.php` | Per-key duplicate guard | ✓ VERIFIED | Contains `str_contains($envContent, "{$key}=")` per-key check with `continue` on match |
| `src/Commands/InstallCommand.php` | Updated `--mini` path with `applyChanges` and migration plan | ✓ VERIFIED | Constructor has all 5 injected services; `handleMiniInstall` calls `applyChanges()` and `migrationPlanGenerator->generate()` |
| `tests/Unit/LaravelWorkosMigratorTest.php` | Isolated tests (min 40 lines) | ✓ VERIFIED | 196 lines, 6 tests covering services.php cleanup, package removal, force bypass |
| `src/Install/AuthSystemInstaller.php` | Hardened post-write verification | ✓ VERIFIED | Contains `$result !== $contents` checks, "Could not automatically update" fallback instructions |
| `tests/Unit/AuthSystemInstallerTest.php` | Tests for regex failure fallback (min 40 lines) | ✓ VERIFIED | 211 lines, 6 tests |
| `tests/Unit/EnvManagerTest.php` | Per-key duplicate guard tests (min 30 lines) | ✓ VERIFIED | 191 lines, includes "does not duplicate env vars already present in .env file on re-run" test |

### Key Link Verification

| From | To | Via | Status | Details |
|------|----|-----|--------|---------|
| `InstallCommand` | `NodeToolingDetector` | Constructor injection | ✓ WIRED | Line 27: `private NodeToolingDetector $nodeDetector` |
| `NodeToolingDetector` | `Process::run()` | Laravel Process facade | ✓ WIRED | Uses `Process::run(['command', '-v', 'bun'])` etc. with array args |
| `InstallCommand::handle()` | `nodeDetector->detect()` | `$runner` variable gates both runInstall and runDoctor | ✓ WIRED | Lines 40-58: detect → optional runInstall → optional runDoctor after exitCode |
| `InstallCommand::handleMiniInstall` | `EnvManager::applyChanges()` | Direct call | ✓ WIRED | Line 119: `$this->envManager->applyChanges($result)` |
| `WizardFlow` | `Command::option('force')` | Early return in each prompt method | ✓ WIRED | Lines 79, 106, 133, 164 in WizardFlow.php |
| `LaravelWorkosMigrator` | `Command::option('force')` | Guard before confirm() calls | ✓ WIRED | Lines 59 and 85 in LaravelWorkosMigrator.php |
| `AuthSystemInstaller::updateAuthConfig` | `File::put()` | Only called when `$modified === true` | ✓ WIRED | `$modified` flag gates `File::put()` at line 248 |
| `InstallCommand::handleMiniInstall` | `MigrationPlanGenerator::generate()` | Called when `$result->hasExistingAuth()` | ✓ WIRED | Lines 121-128: conditional generate + path-based info message |

### Data-Flow Trace (Level 4)

| Artifact | Data Variable | Source | Produces Real Data | Status |
|----------|--------------|--------|--------------------|--------|
| `EnvManager::applyChanges()` | `$envContent` | `File::get($this->envPath)` or empty string | Yes — reads real .env or creates fresh | ✓ FLOWING |
| `EnvironmentDetector::detect()` | `DetectionResult` | `composer.json` parsing + `File::exists()` | Yes — reads real project files | ✓ FLOWING |
| `MigrationPlanGenerator::generate()` | `$markdown` | Per-framework Plan classes (`BreezeMigrationPlan` etc.) | Yes — generates markdown and writes to storage path | ✓ FLOWING |

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| NodeToolingDetector detects bun/npx/pnpm in order | `composer test -- --filter="NodeToolingDetectorTest"` | 14 passed | ✓ PASS |
| `--force` bypasses all prompts | `composer test -- --filter="InstallCommandTest"` | 36 passed | ✓ PASS |
| `--mini` writes env placeholders and migration plan | `composer test -- --filter="InstallCommandTest"` | 36 passed | ✓ PASS |
| EnvManager per-key duplicate guard | `composer test -- --filter="EnvManagerTest"` | 8 passed | ✓ PASS |
| AuthSystemInstaller fallback instructions | `composer test -- --filter="AuthSystemInstallerTest"` | 6 passed | ✓ PASS |
| LaravelWorkosMigrator services.php cleanup | `composer test -- --filter="LaravelWorkosMigratorTest"` | 6 passed | ✓ PASS |
| Full install suite (70 tests total) | `composer test -- --filter="NodeToolingDetector\|LaravelWorkosMigrator\|AuthSystemInstaller\|EnvManager\|InstallCommand"` | 70 passed (251 assertions) | ✓ PASS |

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
|-------------|------------|-------------|--------|----------|
| INST-01 | 03-01-PLAN | Detect existing auth setups via Composer\InstalledVersions | ? PARTIAL | Detection works (composer.json parsing) but uses different mechanism than spec. D-07 explicitly chose this. REQUIREMENTS.md still marks as Pending. |
| INST-02 | 03-01/03-02 | Wizard mode interactively asks which components to install | ✓ SATISFIED | WizardFlow with 6-step interactive flow, component selection confirms. 23+ wizard tests pass. |
| INST-03 | 03-02 | `--force` flag overwrites all existing auth configuration without prompting | ✓ SATISFIED | Force bypass in WizardFlow, LaravelWorkosMigrator, AuthSystemInstaller. Explicit force tests pass. |
| INST-04 | 03-02 | `--mini` flag publishes only config and displays setup instructions | ✓ SATISFIED | handleMiniInstall publishes config, writes env placeholders, displays instructions. 7+ mini tests pass. |
| INST-05 | 03-02 | Config migration from services.php when laravel/workos detected | ✓ SATISFIED | LaravelWorkosMigrator handles services.php cleanup and package removal. 6 isolated unit tests. |
| INST-06 | 03-02 | Migration assistant generates actionable guidance for existing auth systems | ✓ SATISFIED | MigrationPlanGenerator + BreezeMigrationPlan/JetstreamMigrationPlan/FortifyMigrationPlan. Console summary + storage file write. |
| INST-07 | 03-03 | Post-write verification for all file modifications (no silent failures) | ✓ SATISFIED | `$result !== $contents` guards in updateAuthConfig + updateUserModel. Manual instructions fallback. AuthSystemInstallerTest confirms. |
| INST-08 | 03-03 | Zero duplicate env vars or conflicting configs after install | ✓ SATISFIED | Per-key `str_contains` guard in EnvManager, idempotency checks in AuthSystemInstaller. Re-run tests confirm. |

### Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
|------|------|---------|----------|--------|
| `src/Install/LaravelWorkosMigrator.php` | 67 | `Process::run('composer remove laravel/workos')` — string arg, not array | ℹ️ Info | Minor: string form works but bypasses array-argument shell-injection protection. T-03-01 threat mitigation applies to NodeToolingDetector but this pre-existing pattern uses string form. Not a blocker since there is no user input in the command. |

No stubs, no empty returns, no TODO/FIXME found in phase-3 files.

### Human Verification Required

#### 1. End-to-End `--mini` Install on Real App

**Test:** Create a fresh Laravel 12 app, add a real `.env` file with no WORKOS_ vars, run `php artisan workos:install --mini`
**Expected:** `config/workos.php` published, `.env` gains `WORKOS_API_KEY=`, `WORKOS_CLIENT_ID=`, `WORKOS_REDIRECT_URI=` as empty placeholders, manual instructions printed, no prompts shown
**Why human:** Integration tests mock `EnvManager::applyChanges()` — the actual `.env` write path is not exercised end-to-end in the test suite. The SC-3 wording conflict ("no file manipulation" vs. D-05 "write placeholders") also needs human judgment on whether the behavior is acceptable.

#### 2. Conflict Detection Against a Real Breeze App

**Test:** Install Breeze into a fresh Laravel app (`composer require laravel/breeze`), then run `php artisan workos:install`
**Expected:** Wizard detects "Laravel Breeze" in the environment detection banner, displays migration plan summary, writes `workos-migration-plan.md` to `storage/`, proceeds without deleting or overwriting any Breeze files
**Why human:** `EnvironmentDetector::hasComposerDependency()` reads `composer.json` at `$basePath` which resolves to testbench's project root in the test suite — not a real consumer app. The detection path works in isolation but the consumer integration scenario cannot be verified programmatically.

### Gaps Summary

No blocking gaps found. All 70 install-related tests pass. Every truth from the phase plan is implemented and wired. The phase goal is substantively achieved.

Two items require human verification before the phase can be fully signed off:
1. The SC-3 wording ambiguity ("no file manipulation") needs a human to confirm whether writing empty env placeholders in `--mini` mode is the intended behavior (it is, per D-05, but the roadmap SC is stale).
2. End-to-end consumer-app smoke test for conflict detection in a real Breeze/Jetstream environment.

One documentation gap: INST-01 in REQUIREMENTS.md remains marked "Pending" even though detection is fully implemented (via composer.json rather than InstalledVersions). The traceability table should be updated to "Complete" with a note about the implementation approach change.

---

_Verified: 2026-04-07_
_Verifier: Claude (gsd-verifier)_
