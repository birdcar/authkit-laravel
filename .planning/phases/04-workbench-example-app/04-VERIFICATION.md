---
phase: 04-workbench-example-app
verified: 2026-04-07T23:15:00Z
status: human_needed
score: 4/5 success criteria verified
overrides_applied: 0
re_verification:
  previous_status: gaps_found
  previous_score: 3/5
  gaps_closed:
    - "All feature tests now use WorkOS::fake() or WorkOS::actingAs() — zero direct actingAs($user, 'workos') in TodoTest and OrganizationTest"
    - "workbench/routes/web.php has workos.role:admin on DELETE /todos/{todo} and workos.permission:todos.read on GET /todos"
    - "WorkOSFake now has getLogoutUrl() and destroySession() methods (regression fix)"
  gaps_remaining: []
  regressions: []
human_verification:
  - test: "Audit log view shows user actions"
    expected: "After performing Todo CRUD operations while authenticated with a WorkOS organization, the Audit Logs Admin Portal intent link on the organization settings page opens the WorkOS Admin Portal showing the todo.created, todo.completed, todo.uncompleted, and todo.deleted events"
    why_human: "Requires real WorkOS credentials, a real organization, and a browser to click the portal link and confirm events appear. Cannot verify programmatically that audit() API calls result in visible events in the WorkOS-hosted audit log UI."
---

# Phase 4: Workbench Example App Verification Report

**Phase Goal:** Developers evaluating the package can run a complete, working Laravel 12 app that demonstrates every package feature with a realistic test suite
**Verified:** 2026-04-07T23:15:00Z
**Status:** human_needed
**Re-verification:** Yes — after gap closure (plans 04-01 and 04-02)

## Re-verification Summary

Both gaps from the initial verification are now closed:

- **Gap 1 (tests):** All 7 TodoTest.php tests and 4 OrganizationTest.php tests use `WorkOS::fake()->actingAs($user)`. Both AuthTest.php auth tests use `WorkOS::actingAs($user)`. Zero `actingAs($user, 'workos')` calls remain in any test file. Every test has `afterEach(fn () => WorkOS::restore())`.
- **Gap 2 (RBAC):** `workbench/routes/web.php` now has `workos.permission:todos.read` on `GET /todos` and `workos.role:admin` on `DELETE /todos/{todo}`. Two new RBAC tests exercise both the passing and blocking paths.
- **Regression fix:** `WorkOSFake` gained `getLogoutUrl()` and `destroySession()` methods (commit `fd3eba4`).
- **Test suite:** 22 tests, 43 assertions, 0 failures (`cd workbench && php artisan test`).
- **PHPStan:** Level 8, 0 errors (`composer analyse`).

## Goal Achievement

### Observable Truths (ROADMAP Success Criteria)

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | Workbench app boots and serves a Todo app where todos are scoped per org | VERIFIED | TodoList/TodoItem Livewire components exist with substantive DB queries; todos scoped by organization_id; routes registered; migrations present |
| 2 | All Admin Portal intents (SSO, Directory Sync, Audit Logs, Log Streams, Domain Verification) are accessible from the UI | VERIFIED | AdminPortalLinks.php has 6 intents (exceeds 5 required); rendered in org settings page via livewire:admin-portal-links; links generated via WorkOS::portal()->generateLink() |
| 3 | User actions in the Todo app appear in the audit log view | HUMAN NEEDED | WorkOS::audit() calls exist in TodoList (todo.created) and TodoItem (todo.completed, todo.uncompleted, todo.deleted). The "audit log view" is the Audit Logs Admin Portal intent per D-03 decision. Cannot verify without real credentials. |
| 4 | The Pest feature test suite runs successfully using WorkOS::fake() without real API credentials | VERIFIED | 22 tests pass (43 assertions, 0 failures). TodoTest.php: 7 tests, all WorkOS::fake(). OrganizationTest.php: 4 tests, all WorkOS::fake(). AuthTest.php: 2 auth tests, both WorkOS::actingAs(). ExampleTest.php stub deleted. |
| 5 | Running git ls-files workbench/auth.json returns no output (credentials are not tracked) | VERIFIED | Root .gitignore line 22: `workbench/auth.json`; git check-ignore confirms; workbench/.gitignore also has /auth.json (defense-in-depth) |

**Score:** 4/5 success criteria verified (1 human-needed)

### Requirement Coverage

| Requirement | Description | Status | Evidence |
|-------------|-------------|--------|----------|
| WORK-01 | Todo app with create, complete, and delete functionality | VERIFIED | TodoList.addTodo(), TodoItem.toggle(), TodoItem.delete() all wired; TodoTest covers all three operations using WorkOS::fake() with assertAudited() |
| WORK-02 | Organization switching with separate todo lists per org | VERIFIED | OrganizationSwitcher component exists; TodoList.todos() scopes by organization_id; TodoTest 'todos are scoped to organization' test confirmed passing |
| WORK-03 | All Admin Portal intents (SSO, Directory Sync, Audit Logs, Log Streams, Domain Verification) | VERIFIED | AdminPortalLinks.php defines 6 intents: sso, dsync, audit_logs, log_streams, domain_verification, certificate_renewal — all rendered in the org settings view |
| WORK-04 | Audit log shows user actions | HUMAN NEEDED | WorkOS::audit() calls fire on todo.created, todo.completed, todo.uncompleted, todo.deleted. The audit log "view" is the Admin Portal Audit Logs link per D-03. Sending verified; display requires human with real credentials. |
| WORK-05 | Basic Pest feature tests for key flows | VERIFIED | 7 TodoTest + 4 OrganizationTest + 4 AuthTest = 15 feature tests all using WorkOS::fake()/actingAs(). 22 tests total including WorkOSFakeExampleTest. All pass. |
| WORK-06 | auth.json excluded from git (credential protection) | VERIFIED | Root .gitignore line 22: `workbench/auth.json`; committed in e7d10df |
| WORK-07 | workbench/composer.json PHP constraint aligned to ^8.3 | VERIFIED | workbench/composer.json line 23: `"php": "^8.3"` |

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `.gitignore` | Root-level auth.json exclusion | VERIFIED | Contains `workbench/auth.json` at line 22 |
| `workbench/composer.json` | PHP version constraint ^8.3 | VERIFIED | `"php": "^8.3"` at line 23 |
| `workbench/tests/Feature/TodoTest.php` | 7 tests using WorkOS::fake() | VERIFIED | 7 WorkOS::fake() calls; 0 direct actingAs with guard; RBAC tests added (admin succeeds, non-admin blocked) |
| `workbench/tests/Feature/OrganizationTest.php` | 4 tests using WorkOS::fake() | VERIFIED | 4 WorkOS::fake() calls; 0 direct actingAs with guard |
| `workbench/tests/Feature/AuthTest.php` | Auth tests using WorkOS::actingAs() | VERIFIED | Both auth tests use WorkOS::actingAs($user) with afterEach restore |
| `workbench/routes/web.php` | RBAC middleware on todo routes | VERIFIED | workos.permission:todos.read on GET /todos; workos.role:admin on DELETE /todos/{todo} |
| `src/Testing/WorkOSFake.php` | getLogoutUrl() and destroySession() methods | VERIFIED | getLogoutUrl() at line 140, destroySession() at line 145; regression fixed in fd3eba4 |
| `workbench/tests/Feature/ExampleTest.php` | Deleted (PHPUnit stub) | VERIFIED | File does not exist |

### Key Link Verification

| From | To | Via | Status | Details |
|------|----|-----|--------|---------|
| `workbench/routes/web.php` | `CheckRole` middleware | `workos.role:admin` | WIRED | DELETE /todos/{todo} carries workos.role:admin (line 32) |
| `workbench/routes/web.php` | `CheckPermission` middleware | `workos.permission:todos.read` | WIRED | GET /todos carries workos.permission:todos.read (line 21) |
| `TodoTest.php` | `WorkOS::fake()` | `use WorkOS\AuthKit\WorkOS` + `WorkOS::fake()` | WIRED | 7 direct calls; import present at line 11 |
| `OrganizationTest.php` | `WorkOS::fake()` | `use WorkOS\AuthKit\WorkOS` + `WorkOS::fake()` | WIRED | 4 direct calls; import present at line 8 |
| `AuthTest.php` | `WorkOS::actingAs()` | `use WorkOS\AuthKit\WorkOS` + `WorkOS::actingAs()` | WIRED | 2 calls; import at line 6 |
| `TodoList.php` | `workos.audit()` | `WorkOS::audit('todo.created', ...)` | WIRED | Called in addTodo() method |
| `TodoItem.php` | `workos.audit()` | `WorkOS::audit()` | WIRED | Called in toggle() and delete() |

### Data-Flow Trace (Level 4)

| Artifact | Data Variable | Source | Produces Real Data | Status |
|----------|---------------|--------|--------------------|--------|
| `TodoList.php` | `$todos` (computed) | `Todo::where('user_id', ...)->where('organization_id', ...)` | Yes — Eloquent query with org scope | FLOWING |
| `AdminPortalLinks.php` | `$links` | `WorkOS::portal()->generateLink()` per intent | Depends on real WorkOS org ID (graceful null) | STATIC when no org set |
| `OrganizationSwitcher.php` | organization list | Relationship query on User model | Yes — DB query | FLOWING |

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| Full test suite passes | `cd workbench && php artisan test` | 22 passed (43 assertions) in 0.42s | PASS |
| PHPStan level 8 clean | `composer analyse` | No errors (65 files analysed) | PASS |
| No direct actingAs(guard) in TodoTest | `grep -c 'actingAs.*workos' TodoTest.php` | 0 | PASS |
| No direct actingAs(guard) in OrganizationTest | `grep -c 'actingAs.*workos' OrganizationTest.php` | 0 | PASS |
| RBAC middleware in routes | `grep 'workos.role\|workos.permission' web.php` | 2 matches | PASS |
| ExampleTest stub deleted | `test -f workbench/tests/Feature/ExampleTest.php` | File absent | PASS |

### Anti-Patterns Found

No blockers or warnings in the re-verified codebase. All previously-flagged anti-patterns have been resolved:

| Previously Flagged | Resolution |
|--------------------|------------|
| `actingAs($user, 'workos')` in TodoTest.php (5 tests) | Converted to `WorkOS::fake()->actingAs($user)` in all 7 tests |
| `actingAs($user, 'workos')` in OrganizationTest.php (4 tests) | Converted to `WorkOS::fake()->actingAs($user)` in all 4 tests |
| `actingAs($user, 'workos')` in AuthTest.php (1 test) | Converted to `WorkOS::actingAs($user)` |
| No RBAC middleware on routes | `workos.role:admin` and `workos.permission:todos.read` added |

### Human Verification Required

#### 1. Audit Log View Shows User Actions

**Test:** Authenticate to the workbench app with real WorkOS credentials, perform Todo CRUD operations (create a todo, complete it, delete it), then navigate to Organization Settings and click the "Audit Logs" Admin Portal link.
**Expected:** The WorkOS Admin Portal opens showing audit events for `todo.created`, `todo.completed`, `todo.uncompleted`, and `todo.deleted` with the correct user and target metadata.
**Why human:** Requires real WorkOS credentials, a real organization in WorkOS, a running workbench server, and browser interaction to verify the portal link works and the events appear. The WorkOS SDK call is wired in code (`WorkOS::audit()` called in TodoList.addTodo(), TodoItem.toggle(), TodoItem.delete()), but success depends on API connectivity and WorkOS org configuration. WORK-04 / SC#3 — cannot be satisfied programmatically.

## Gaps Summary

No gaps remaining. Both gaps from the initial verification have been closed:

1. **Gap 1 (WORK-05 / D-06) — CLOSED:** All feature tests now use `WorkOS::fake()` pattern. 7 TodoTest + 4 OrganizationTest + 2 AuthTest tests all use the fake API. Commits `a60c0f1` and `b040176`.

2. **Gap 2 (D-09) — CLOSED:** `workos.role:admin` on `DELETE /todos/{todo}` and `workos.permission:todos.read` on `GET /todos`. Two RBAC tests exercise both the passing (admin, 200) and blocking (member, 403) paths. Commits `a60c0f1` and `b040176`.

The only remaining open item is human verification of SC#3 (audit log view) — this requires real credentials and cannot be automated.

---

_Verified: 2026-04-07T23:15:00Z_
_Verifier: Claude (gsd-verifier)_
