---
phase: 02-testing-utilities
plan: 01
subsystem: testing
tags: [pest, phpstan, workos-fake, trait, laravel-testcase-lifecycle]

# Dependency graph
requires: []
provides:
  - InteractsWithWorkOS trait with correct Laravel auto-teardown hook
  - DI injection test proving app(WorkOS::class) resolves to fake
affects: [03-smart-install, 04-workbench, workbench-tests]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Laravel trait auto-teardown: tearDown + class_basename($traitFqcn) naming convention"

key-files:
  created: []
  modified:
    - src/Testing/Concerns/InteractsWithWorkOS.php
    - tests/Unit/WorkOSFakeTest.php
    - phpstan.neon

key-decisions:
  - "tearDownInteractsWithWorkOS is the correct name — Laravel calls tearDown + class_basename($trait), not class_basename of the class the trait is used in"

patterns-established:
  - "Trait teardown methods must be named tearDown + class_basename($traitFqcn) for Laravel TestCase to auto-invoke them"

requirements-completed: [TEST-01, TEST-04]

# Metrics
duration: 3min
completed: 2026-04-06
---

# Phase 2 Plan 01: Fix InteractsWithWorkOS Auto-Teardown and Add DI Injection Test

**Fixed silent fake state bleed by renaming tearDownWorkOS to tearDownInteractsWithWorkOS so Laravel auto-invokes it, plus verified DI injection via app(WorkOS::class) resolves to the fake**

## Performance

- **Duration:** 3 min
- **Started:** 2026-04-06T20:39:24Z
- **Completed:** 2026-04-06T20:42:24Z
- **Tasks:** 2
- **Files modified:** 3

## Accomplishments
- Added explicit test proving `app(WorkOS::class)` (DI injection path) resolves to the same `WorkOSFake` instance as `WorkOS::fake()`
- Renamed `tearDownWorkOS()` to `tearDownInteractsWithWorkOS()` so Laravel's `setUpTraits()` auto-invokes it after each test
- All 296 tests pass, PHPStan level 8 clean

## Task Commits

Each task was committed atomically:

1. **Task 1: Add DI injection test to WorkOSFakeTest.php** - `a7bc61e` (test)
2. **Task 2: Rename tearDownWorkOS to tearDownInteractsWithWorkOS** - `c4e309b` (fix)

## Files Created/Modified
- `src/Testing/Concerns/InteractsWithWorkOS.php` - Renamed teardown method to correct Laravel convention
- `tests/Unit/WorkOSFakeTest.php` - Added DI injection test for WorkOS::fake()
- `phpstan.neon` - Removed non-existent src/Traits exclude path (pre-existing config drift)

## Decisions Made
- None - followed plan as specified. The teardown naming convention (`tearDown` + `class_basename($traitFqcn)`) is documented in the plan and confirmed via Laravel source.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Removed non-existent src/Traits from phpstan.neon excludePaths**
- **Found during:** Task 1 (PHPStan verification)
- **Issue:** phpstan.neon excluded `src/Traits` which doesn't exist, causing PHPStan to error with "path is neither a directory, nor a file path, nor a fnmatch pattern"
- **Fix:** Removed the `src/Traits` entry from excludePaths (already removed in main branch, worktree was behind)
- **Files modified:** phpstan.neon
- **Verification:** `./vendor/bin/phpstan analyse src --level=8 --memory-limit=512M` exits 0 with "No errors"
- **Committed in:** a7bc61e (Task 1 commit)

---

**Total deviations:** 1 auto-fixed (1 pre-existing bug)
**Impact on plan:** Fix was necessary for PHPStan to run. No scope creep.

## Issues Encountered
- Worktree had no vendor directory — installed composer dependencies in worktree to run tests correctly
- Test count discrepancy: plan expected 26 existing + 1 new = 27, but worktree branch (e7569ca) had 25 existing tests. Result is 26 tests passing, which is correct for this branch.

## User Setup Required
None - no external service configuration required.

## Next Phase Readiness
- `InteractsWithWorkOS` trait now correctly auto-tears down fake state after each test
- `app(WorkOS::class)` DI injection path verified to resolve to fake
- Ready for Phase 02 Plan 02 (test assertions: assertAudited, assertNotAudited)

---
*Phase: 02-testing-utilities*
*Completed: 2026-04-06*
