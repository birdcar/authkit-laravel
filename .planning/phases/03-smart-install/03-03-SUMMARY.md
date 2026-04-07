---
phase: 03-smart-install
plan: "03"
subsystem: install
tags: [hardening, idempotency, file-mutation, env-management]
dependency_graph:
  requires: [03-02]
  provides: [hardened-file-mutation, per-key-env-guard]
  affects: [AuthSystemInstaller, EnvManager]
tech_stack:
  added: []
  patterns: [preg_replace-verify-before-write, per-key-duplicate-guard, fallback-manual-instructions]
key_files:
  created:
    - tests/Unit/AuthSystemInstallerTest.php
  modified:
    - src/Install/AuthSystemInstaller.php
    - src/Install/EnvManager.php
    - tests/Unit/EnvManagerTest.php
decisions:
  - "Use $result !== $contents check (not just $result !== null) to distinguish regex no-op from regex failure"
  - "updateUserModel else branch prints manual instructions only when traits were needed but regex failed"
  - "Per-key guard uses str_contains($envContent, '{$key}=') with equals sign to avoid partial matches"
metrics:
  duration: "10 minutes"
  completed: "2026-04-07"
  tasks_completed: 2
  files_changed: 4
---

# Phase 3 Plan 03: Hardened File Mutation and Per-Key Env Guard Summary

Hardened `AuthSystemInstaller` post-write verification and added per-key duplicate guard to `EnvManager.applyChanges()`. Every automated file edit now either succeeds with verification or falls through to printed manual instructions — never silently skipping.

## What Was Built

**AuthSystemInstaller hardening (`src/Install/AuthSystemInstaller.php`):**
- `updateAuthConfig()` now tracks a `$modified` flag; `File::put()` is only called when at least one regex actually changed the content (`$result !== $contents`)
- When guards or providers regex produces no change, prints specific `warn()` + `line()` manual instructions with exact PHP code to add
- When guards key is entirely absent from `auth.php`, prints fallback manual instructions
- `updateUserModel()` now prints `warn()` with exact use statement instructions when `$modified` remains false after attempting trait injection

**EnvManager per-key guard (`src/Install/EnvManager.php`):**
- `applyChanges()` checks `str_contains($envContent, "{$key}=")` before appending each key
- Uses `=` in the pattern per threat model T-03-06 to avoid partial key matches (e.g., `WORKOS_API_KEY_OLD` matching `WORKOS_API_KEY`)
- Keys physically present in `.env` are skipped even if `DetectionResult` says they are missing

## Tests

- `tests/Unit/AuthSystemInstallerTest.php` — 6 tests covering: manual guard instructions, no-File::put on regex failure, successful standard format update, idempotency skip, manual trait instructions, successful trait injection
- `tests/Unit/EnvManagerTest.php` — 1 new test added: `does not duplicate env vars already present in .env file on re-run`

## Verification

- `composer test -- --filter="AuthSystemInstallerTest|EnvManagerTest"`: 14 passed
- `composer test -- --filter="InstallCommandTest"`: 36 passed (no regression)
- `composer analyse`: No errors (PHPStan level 8)
- `composer format:test`: pass (Pint)
- Full suite: 287 passed, 48 pre-existing failures (unrelated to this plan — documented below)

## Deviations from Plan

None — plan executed exactly as written.

## Known Stubs

None.

## Threat Flags

None — all mitigations from threat model applied:
- T-03-05: `$result !== $contents` check before writing in `updateAuthConfig()`
- T-03-06: `{$key}=` pattern (with equals sign) in per-key guard

## Deferred Issues

The following 48 pre-existing test failures exist in the codebase and are out of scope for this plan:
- `Tests\Unit\AuditLoggerTest` — pre-existing failures
- `Tests\Unit\WorkOSFakeTest` — pre-existing failures
- `Tests\Feature\AuditIntegrationTest` — pre-existing failures
- `Tests\Feature\AuthFlowTest` — pre-existing failures
- `Tests\Feature\MiddlewareTest` — pre-existing failures
- `Tests\Feature\OrganizationSwitchTest` — pre-existing failures

Confirmed pre-existing: same 48 failures on `git stash` (clean codebase before this plan's changes).

## Self-Check: PASSED

All key files exist. Both commits verified in git log.
