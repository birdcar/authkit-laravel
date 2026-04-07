---
phase: 04-workbench-example-app
plan: "02"
subsystem: workbench
tags: [testing, rbac, workos-fake, feature-tests, middleware]

# Dependency graph
requires:
  - 04-01 (workbench compliance gaps — PHP ^8.3 constraint)
provides:
  - WORK-01: WorkOS::fake() used in all workbench feature tests
  - WORK-02: WorkOS::fake() pattern demonstrated end-to-end
  - WORK-03: RBAC middleware on workbench routes
  - WORK-04: Test suite passes without real WorkOS credentials
  - WORK-05: Pest feature tests cover all key flows with WorkOS::fake()
affects: [workbench/tests, workbench/routes]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "WorkOS::fake()->actingAs($user, roles: [...], permissions: [...]) for test auth setup"
    - "afterEach(fn () => WorkOS::restore()) for fake teardown per test"
    - "workos.role:admin and workos.permission:todos.read as route-level RBAC middleware"

key-files:
  created: []
  modified:
    - workbench/routes/web.php
    - workbench/tests/Feature/TodoTest.php
    - workbench/tests/Feature/OrganizationTest.php
    - workbench/tests/Feature/AuthTest.php
  deleted:
    - workbench/tests/Feature/ExampleTest.php

key-decisions:
  - "Use WorkOS::fake()->actingAs() directly (not InteractsWithWorkOS trait) in feature tests — trait pattern is demonstrated in WorkOSFakeExampleTest.php already"
  - "Delete ExampleTest.php PHPUnit class-based stub — does not match Pest-style convention used by all other workbench tests"
  - "assertForbidden() for non-admin route test — MissingRoleException extends HttpException(403) so Laravel renders 403 automatically"

patterns-established:
  - "Route-level RBAC: workos.role:admin on DELETE route, workos.permission:todos.read on GET route"
  - "Per-test fake lifecycle: WorkOS::fake() at test start, WorkOS::restore() in afterEach"

requirements-completed:
  - WORK-01
  - WORK-02
  - WORK-03
  - WORK-04
  - WORK-05

# Metrics
duration: 15min
completed: 2026-04-07
---

# Phase 04 Plan 02: WorkOS::fake() Test Patterns and RBAC Middleware Summary

**All workbench feature tests converted to WorkOS::fake() pattern; RBAC middleware demonstrated on todo routes with passing test coverage**

## Performance

- **Duration:** ~15 min
- **Completed:** 2026-04-07
- **Tasks:** 2
- **Files modified:** 4 (1 deleted)

## Accomplishments

- Added `workos.permission:todos.read` to `GET /todos` route (D-09, WORK-03)
- Added `DELETE /todos/{todo}` route with `workos.role:admin` middleware (D-09, WORK-03)
- Deleted `workbench/tests/Feature/ExampleTest.php` PHPUnit stub
- Converted all 5 original `TodoTest.php` tests from `actingAs($user, 'workos')` to `WorkOS::fake()->actingAs($user)`
- Added 2 RBAC route tests: admin succeeds (200), non-admin blocked (403)
- Converted all 4 `OrganizationTest.php` tests to `WorkOS::fake()->actingAs($user)`
- Converted `AuthTest.php` logout test from `actingAs($user, 'workos')` to `WorkOS::actingAs($user)`
- All tests include `afterEach(fn () => WorkOS::restore())` for fake cleanup

## Task Commits

1. **Task 1: Add RBAC middleware to routes and remove ExampleTest stub** — `a60c0f1`
2. **Task 2: Convert feature tests to WorkOS::fake() and add RBAC tests** — `b040176`

## Files Created/Modified

- `workbench/routes/web.php` — Added `workos.permission:todos.read` on GET /todos, added DELETE /todos/{todo} with `workos.role:admin`
- `workbench/tests/Feature/TodoTest.php` — 7 tests (5 original + 2 RBAC), all using WorkOS::fake()
- `workbench/tests/Feature/OrganizationTest.php` — 4 tests, all using WorkOS::fake()
- `workbench/tests/Feature/AuthTest.php` — logout test converted to WorkOS::actingAs()
- `workbench/tests/Feature/ExampleTest.php` — DELETED (PHPUnit stub, not Pest-style)

## Decisions Made

- Used direct `WorkOS::fake()->actingAs()` pattern (not `InteractsWithWorkOS` trait) in feature tests — that trait pattern is already demonstrated in `WorkOSFakeExampleTest.php`
- `MissingRoleException extends HttpException(403)` — `assertForbidden()` works without any additional exception handler mapping

## Deviations from Plan

None — plan executed exactly as written.

## Known Stubs

None — all test logic is wired to real models, real database operations, and real fake sessions.

## Self-Check: PASSED

- `workbench/routes/web.php` — EXISTS, contains `workos.role:admin` and `workos.permission:todos.read`
- `workbench/tests/Feature/TodoTest.php` — EXISTS, 7 tests with WorkOS::fake()
- `workbench/tests/Feature/OrganizationTest.php` — EXISTS, 4 tests with WorkOS::fake()
- `workbench/tests/Feature/AuthTest.php` — EXISTS, logout test uses WorkOS::actingAs()
- `workbench/tests/Feature/ExampleTest.php` — DOES NOT EXIST (deleted)
- Commits `a60c0f1` and `b040176` — VERIFIED in git log

---
*Phase: 04-workbench-example-app*
*Completed: 2026-04-07*
