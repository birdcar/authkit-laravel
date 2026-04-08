---
phase: 05-ci-cd-and-documentation
reviewed: 2026-04-07T00:00:00Z
depth: standard
files_reviewed: 1
files_reviewed_list:
  - .github/README.md
findings:
  critical: 0
  warning: 2
  info: 2
  total: 4
status: issues_found
---

# Phase 05: Code Review Report

**Reviewed:** 2026-04-07
**Depth:** standard
**Files Reviewed:** 1
**Status:** issues_found

## Summary

Reviewed `.github/README.md` against the actual implementations in `src/Testing/WorkOSFake.php`, `src/Testing/Concerns/InteractsWithWorkOS.php`, and `workbench/tests/Feature/WorkOSFakeExampleTest.php`.

The `WorkOS::fake()` API documentation is accurate for the core fake lifecycle, builder pattern, and assertion methods. However, two documentation bugs were found: one where the auto-teardown claim for `uses()` inside `describe()` blocks is incorrect per the project's own research, and one where the audit assertions example misleads readers about how `WorkOS::audit()` routes through the fake. Two lower-priority issues cover a missing `void` return note on `destroySession()` and absent facade `@method` annotations for the static testing helpers.

## Warnings

### WR-01: InteractsWithWorkOS auto-teardown claim is incorrect for `describe()` scope

**File:** `.github/README.md:446-468`

**Issue:** The README example shows `uses(InteractsWithWorkOS::class)` inside a `describe()` block and concludes with `// No afterEach needed — the trait tears down automatically`. This claim is false for Pest 4 when `uses()` is scoped inside `describe()`. Laravel's `setUpTraits()` only fires auto-teardown when `uses()` is applied at file level. The project's own research (`.planning/phases/02-testing-utilities/02-RESEARCH.md:289-290`) explicitly resolved this: "Inside `describe()` blocks, `uses()` scoping is unreliable for auto-tearDown. Decision: Use file-level `uses(InteractsWithWorkOS::class)` for auto-tearDown. Show `afterEach(fn () => WorkOS::restore())` as the pattern for `describe()` blocks."

The actual example test file (`workbench/tests/Feature/WorkOSFakeExampleTest.php:76`) confirms this: even though it uses the trait inside `describe()`, it still includes `->afterEach(fn () => WorkOS::restore())` on the `describe` block.

Without teardown, the fake state from one test bleeds into subsequent tests that may not expect a fake to be active, causing intermittent failures.

**Fix:** Either change the example to file-level `uses()` (where auto-teardown works) or keep the `describe()` scope but remove the misleading comment and add an explicit `afterEach`:

Option A — file-level uses (auto-teardown works):
```php
uses(InteractsWithWorkOS::class);

describe('todo management', function () {
    it('allows authenticated user to view todos', function () {
        $user = User::factory()->create();
        $fake = $this->actingAsWorkOS($user, roles: ['member'], permissions: ['todos.read']);

        $this->get('/dashboard')->assertOk();
        $fake->assertHasRole('member');
    });

    it('activates fake without authentication', function () {
        $fake = $this->fakeWorkOS();

        $fake->assertGuest();
    });
});
// No afterEach needed — trait tears down automatically at file level
```

Option B — describe-level uses (explicit afterEach required):
```php
describe('todo management', function () {
    uses(InteractsWithWorkOS::class);

    it('allows authenticated user to view todos', function () { ... });
    it('activates fake without authentication', function () { ... });
})->afterEach(fn () => WorkOS::restore());
// afterEach required — uses() inside describe() does not trigger setUpTraits()
```

---

### WR-02: Audit assertions example misleads about how `WorkOS::audit()` routes to the fake

**File:** `.github/README.md:473-488`

**Issue:** Lines 480-481 show:

```php
// Your application code calls WorkOS::audit() internally
$fake->audit('todo.created', metadata: ['title' => 'My Task']);
```

The comment says "Your application code calls WorkOS::audit() internally" but immediately below it calls `$fake->audit()` directly on the fake instance. This conflates two different things: application code calling `WorkOS::audit()` (which routes through the container to the fake), and test code calling `$fake->audit()` directly.

Readers may conclude that tests should call `$fake->audit()` directly to simulate app behavior. In practice, to test that application code properly calls `WorkOS::audit()`, the test should exercise the production code path. Calling `$fake->audit()` directly only tests the fake's capture mechanism, not the integration.

**Fix:** Separate the two use cases clearly:

```php
test('application code audit calls are captured', function () {
    $user = User::factory()->create();
    $fake = WorkOS::fake()->actingAs($user);

    // Trigger the actual application code that calls WorkOS::audit() internally
    $this->post('/todos', ['title' => 'My Task'])->assertCreated();

    $fake->assertAudited('todo.created');
    $fake->assertNotAudited('todo.deleted');
    $fake->assertAuditedCount(1);
})->afterEach(fn () => WorkOS::restore());
```

If the intent is to show direct fake invocation (e.g., unit-testing a class that depends on audit), remove the misleading comment:

```php
// Directly invoke fake->audit() to test your assertion logic:
$fake->audit('todo.created', metadata: ['title' => 'My Task']);
$fake->assertAudited('todo.created');
```

---

## Info

### IN-01: `destroySession()` return type not noted — cannot be chained

**File:** `.github/README.md:506`

**Issue:** `destroySession()` appears in the "Setup Methods" table. The actual method in `src/Testing/WorkOSFake.php:145` returns `void`. Unlike all other builder methods in the table (`actingAs`, `withRoles`, `withPermissions`, `inOrganization`, `impersonating`), `destroySession()` cannot be chained. No note distinguishes it from the chainable methods, which may surprise readers attempting `WorkOS::fake()->actingAs($user)->destroySession()->assertGuest()`.

**Fix:** Add a note to the table row or add a `void` return annotation:

| `$fake->destroySession()` | Clear authenticated state (returns void — not chainable) |

---

### IN-02: Static testing methods missing from Facade `@method` annotations

**File:** `src/Facades/WorkOS.php`

**Issue:** `WorkOS::fake()`, `WorkOS::actingAs()`, `WorkOS::isFaked()`, and `WorkOS::restore()` are static methods defined directly on `WorkOS\AuthKit\WorkOS` (lines 181-211 of `src/WorkOS.php`). The Facade docblock in `src/Facades/WorkOS.php` has no `@method static` entries for these four methods. IDE autocompletion will not offer them when users type `WorkOS::` in their test files, making the testing API effectively invisible to tooling.

The README documents `WorkOS::isFaked()` (line 499) as part of the public API, so this omission is inconsistent with the documented surface.

**Fix:** Add to `src/Facades/WorkOS.php`:
```php
 * @method static \WorkOS\AuthKit\Testing\WorkOSFake fake()
 * @method static \WorkOS\AuthKit\Testing\WorkOSFake actingAs(\Illuminate\Contracts\Auth\Authenticatable $user, array $roles = [], array $permissions = [], ?string $organizationId = null)
 * @method static bool isFaked()
 * @method static void restore()
```

---

_Reviewed: 2026-04-07_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
