---
phase: 02-testing-utilities
plan: 02
subsystem: testing
tags: [pest, workos-fake, testing-utilities, livewire]

# Dependency graph
requires:
  - phase: 02-testing-utilities/02-01
    provides: WorkOSFake and InteractsWithWorkOS implemented (ran in parallel, wave 1)
provides:
  - WorkOSFakeExampleTest.php demonstrating direct fake, trait, and audit patterns
  - AuthTest.php with dashboard test converted to WorkOS::actingAs() as migration reference
affects: [workbench, example-app]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "WorkOS::fake() + afterEach(WorkOS::restore()) for explicit fake lifecycle"
    - "uses(InteractsWithWorkOS::class) inside describe() for zero-boilerplate teardown"
    - "WorkOS::actingAs() replaces actingAs($user, 'workos') guard pattern"

key-files:
  created:
    - workbench/tests/Feature/WorkOSFakeExampleTest.php
  modified:
    - workbench/tests/Feature/AuthTest.php
    - src/Testing/WorkOSFake.php
    - workbench/app/Models/User.php

key-decisions:
  - "WorkOSFake::actingAs() uses Guard::setUser() not StatefulGuard::login() since WorkOSGuard implements Guard"
  - "User::organizations() specifies organization_memberships as explicit pivot table"

patterns-established:
  - "Pattern 1: Direct fake - WorkOS::fake() + afterEach(fn () => WorkOS::restore())"
  - "Pattern 2: Trait - uses(InteractsWithWorkOS::class) inside describe() block"
  - "Pattern 3: Audit - fake->audit() + assertAudited/assertNotAudited"

requirements-completed: [TEST-02, TEST-03, TEST-05]

# Metrics
duration: 25min
completed: 2026-04-06
---

# Phase 2 Plan 02: Workbench Example Tests Summary

**WorkOS fake patterns documented with runnable examples: direct fake lifecycle, InteractsWithWorkOS trait, and audit assertions; AuthTest dashboard test migrated from guard-based to WorkOS::actingAs().**

## Performance

- **Duration:** ~25 min
- **Started:** 2026-04-06T20:18:00Z
- **Completed:** 2026-04-06T20:43:51Z
- **Tasks:** 2 completed
- **Files modified:** 4

## Accomplishments

- Created `WorkOSFakeExampleTest.php` with 6 passing tests demonstrating all three fake patterns (direct, trait, audit)
- Converted `AuthTest.php` dashboard test from `actingAs($user, 'workos')` to `WorkOS::actingAs($user)` with afterEach restore
- Fixed `WorkOSFake::actingAs()` crash: `WorkOSGuard` implements `Guard` not `StatefulGuard`, so switched from `login()` to `setUser()`
- Fixed `User::organizations()` pivot table mismatch: `organization_user` auto-generated name vs actual `organization_memberships` migration table

## Task Commits

1. **Task 1: Create WorkOSFakeExampleTest.php** - `75b1636` (feat)
2. **Task 2: Convert AuthTest dashboard test** - `9e3e2b5` (feat)

## Files Created/Modified

- `workbench/tests/Feature/WorkOSFakeExampleTest.php` - New example test with three fake patterns (direct, trait, audit)
- `workbench/tests/Feature/AuthTest.php` - Dashboard test converted to WorkOS::actingAs(); logout test unchanged
- `src/Testing/WorkOSFake.php` - Fixed actingAs() to call setUser() instead of login()
- `workbench/app/Models/User.php` - Fixed organizations() to use 'organization_memberships' pivot table

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] WorkOSFake::actingAs() called login() on non-StatefulGuard**
- **Found during:** Task 1 verification
- **Issue:** `WorkOSFake.php` line 54 cast the guard to `StatefulGuard` and called `login()`, but `WorkOSGuard` implements `Guard` (which has `setUser()`, not `login()`). Every `actingAs()` call threw `Call to undefined method WorkOSGuard::login()`.
- **Fix:** Changed import from `StatefulGuard` to `Guard`, changed `$guard->login($user)` to `$guard->setUser($user)`
- **Files modified:** `src/Testing/WorkOSFake.php`
- **Commit:** 75b1636

**2. [Rule 1 - Bug] User::organizations() referenced non-existent pivot table**
- **Found during:** Task 1 verification (dashboard route hit DB)
- **Issue:** `User::organizations()` used `belongsToMany(Organization::class)` with no explicit table, causing Laravel to generate `organization_user` as the pivot name. The actual migration created `organization_memberships`. All dashboard requests failed with `no such table: organization_user`.
- **Fix:** Added `'organization_memberships'` as second argument to `belongsToMany()`
- **Files modified:** `workbench/app/Models/User.php`
- **Commit:** 75b1636

## Known Stubs

None.

## Threat Flags

None — only test files and a workbench model fix. No new network endpoints, auth paths, or schema changes introduced.

## Self-Check: PASSED

- `workbench/tests/Feature/WorkOSFakeExampleTest.php` - FOUND
- `workbench/tests/Feature/AuthTest.php` - FOUND (modified)
- `src/Testing/WorkOSFake.php` - FOUND (modified)
- `workbench/app/Models/User.php` - FOUND (modified)
- Commit 75b1636 - FOUND
- Commit 9e3e2b5 - FOUND
- All 20 workbench Feature tests pass
