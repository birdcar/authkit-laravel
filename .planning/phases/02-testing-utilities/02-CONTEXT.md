# Phase 2: Testing Utilities - Context

**Gathered:** 2026-04-06 (discuss mode)
**Status:** Ready for planning

<domain>
## Phase Boundary

Package consumers can write isolated, repeatable tests for WorkOS-authenticated routes using familiar Laravel fake patterns. WorkOS::fake() replaces both facade and DI instances, actingAs() sets up authenticated context, and fake state does not bleed between tests.

</domain>

<decisions>
## Implementation Decisions

### Container Swap
- **D-01:** The existing `app()->instance('workos', $fake)` swap is correct and complete — the `alias('workos', WorkOS::class)` in the service provider ensures facade, helper, and DI-injected instances all resolve to the fake.
- **D-02:** Add a test that explicitly verifies DI-injected `WorkOS` resolves to the fake (currently only `app('workos')` is tested).

### TearDown Enforcement
- **D-03:** Rename `tearDownWorkOS()` to `tearDownInteractsWithWorkOS()` in the `InteractsWithWorkOS` trait so PHPUnit/Pest auto-calls it after each test — matches the Laravel trait convention (e.g. `RefreshDatabase` → `tearDownRefreshDatabase()`).
- **D-04:** Remove the old `tearDownWorkOS()` method entirely — no alias.

### Workbench Example Tests
- **D-05:** Create `workbench/tests/Feature/WorkOSFakeExampleTest.php` with standalone examples demonstrating `WorkOS::fake()`, `actingAs()`, role/permission builders, org context, and audit assertions.
- **D-06:** Convert at least one existing workbench test (e.g. from TodoTest or AuthTest) to use `WorkOS::fake()`/`actingAs()` as a reference for migrating existing tests.

### Claude's Discretion
- Which specific existing workbench test to convert
- Exact test case naming and grouping in the example file
- Whether to use `InteractsWithWorkOS` trait or direct `WorkOS::fake()` calls in examples (show both patterns)

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Testing Utilities Source
- `src/Testing/WorkOSFake.php` — The fake implementation (actingAs, assertions, audit capture)
- `src/Testing/Concerns/InteractsWithWorkOS.php` — Trait with convenience methods + tearDown
- `src/WorkOS.php` lines 181-212 — Static fake(), actingAs(), restore() methods

### Test Coverage
- `tests/Unit/WorkOSFakeTest.php` — 26 existing unit tests for WorkOSFake
- `tests/Feature/AuditIntegrationTest.php` — Audit assertion integration tests

### Workbench Tests (to update)
- `workbench/tests/Feature/TodoTest.php` — Candidate for conversion to fake pattern
- `workbench/tests/Feature/AuthTest.php` — Auth flow tests
- `workbench/tests/Pest.php` — Workbench test configuration

### Service Provider Binding
- `src/WorkOSServiceProvider.php` lines 57-63 — singleton + alias binding that makes swap work

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `WorkOSFake`: Complete with 8 assertions, fluent builders, audit event capture — no structural changes needed
- `InteractsWithWorkOS`: Trait provides `actingAsWorkOS()`, `fakeWorkOS()` convenience methods
- `WorkOS::fake()` static method: Already handles container instance replacement

### Established Patterns
- Container swap via `app()->instance()` + `alias()` — same pattern as Laravel's `Mail::fake()`
- `WorkOS::restore()` calls `app()->forgetInstance('workos')` — clean teardown
- PHPStan level 8 type annotations on all fake methods

### Integration Points
- `WorkOSFake::actingAs()` calls `auth('workos')->login($user)` — integrates with Laravel's auth system
- `WorkOSFake::buildSession()` creates `WorkOSSession` with fake tokens — used by middleware and guards
- `setWorkOSSession()` on user model propagates session to HasWorkOSPermissions trait

</code_context>

<specifics>
## Specific Ideas

- Show both patterns in workbench examples: `InteractsWithWorkOS` trait approach and direct `WorkOS::fake()` approach
- DI test should type-hint `WorkOS` in a closure or controller and verify it receives the fake

</specifics>

<deferred>
## Deferred Ideas

None — discussion stayed within phase scope

</deferred>

---

*Phase: 02-testing-utilities*
*Context gathered: 2026-04-06*
