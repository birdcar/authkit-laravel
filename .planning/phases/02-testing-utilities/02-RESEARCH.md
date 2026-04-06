# Phase 2: Testing Utilities - Research

**Researched:** 2026-04-06
**Domain:** PHPUnit/Pest trait lifecycle conventions, Laravel fake patterns, package testing utilities
**Confidence:** HIGH

## Summary

The testing utilities are largely complete. `WorkOSFake`, `InteractsWithWorkOS`, and `WorkOS::fake()` already exist with 26 unit tests and a full assertion surface. This phase is about three targeted changes: (1) rename `tearDownWorkOS()` to `tearDownInteractsWithWorkOS()` so the Laravel test infrastructure auto-invokes it, (2) add one test verifying DI injection resolves to the fake, and (3) create a workbench example test file demonstrating both the trait and direct `WorkOS::fake()` patterns.

The critical naming convention is verified: Laravel's `setUpTraits()` calls `tearDown` + `class_basename($trait)` for every trait used in a test. For `InteractsWithWorkOS`, `class_basename` returns `InteractsWithWorkOS`, so the auto-invoked method must be named exactly `tearDownInteractsWithWorkOS()`. This is the sole structural change to the trait.

**Primary recommendation:** Rename the method, add the DI test, create the workbench example file. No new abstractions needed — the existing `WorkOSFake` surface is feature-complete for v1.

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions
- **D-01:** The existing `app()->instance('workos', $fake)` swap is correct and complete — the `alias('workos', WorkOS::class)` in the service provider ensures facade, helper, and DI-injected instances all resolve to the fake.
- **D-02:** Add a test that explicitly verifies DI-injected `WorkOS` resolves to the fake (currently only `app('workos')` is tested).
- **D-03:** Rename `tearDownWorkOS()` to `tearDownInteractsWithWorkOS()` in the `InteractsWithWorkOS` trait so PHPUnit/Pest auto-calls it after each test — matches the Laravel trait convention (e.g. `RefreshDatabase` → `tearDownRefreshDatabase()`).
- **D-04:** Remove the old `tearDownWorkOS()` method entirely — no alias.
- **D-05:** Create `workbench/tests/Feature/WorkOSFakeExampleTest.php` with standalone examples demonstrating `WorkOS::fake()`, `actingAs()`, role/permission builders, org context, and audit assertions.
- **D-06:** Convert at least one existing workbench test (e.g. from TodoTest or AuthTest) to use `WorkOS::fake()`/`actingAs()` as a reference for migrating existing tests.

### Claude's Discretion
- Which specific existing workbench test to convert
- Exact test case naming and grouping in the example file
- Whether to use `InteractsWithWorkOS` trait or direct `WorkOS::fake()` calls in examples (show both patterns)

### Deferred Ideas (OUT OF SCOPE)
None — discussion stayed within phase scope

</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| TEST-01 | WorkOS::fake() replaces container binding via swap() so both facade and DI usage are faked | D-01 confirms existing swap is correct; D-02 adds explicit DI verification test |
| TEST-02 | WorkOS::actingAs() sets up authenticated user with roles, permissions, and org context | Already fully implemented in WorkOSFake; D-05 example tests demonstrate this |
| TEST-03 | assertAudited() and assertNotAudited() verify audit log behavior in tests | Already fully implemented in WorkOSFake; D-05 example tests demonstrate this |
| TEST-04 | InteractsWithWorkOS trait auto-tears down fake in test lifecycle | D-03/D-04 are the sole change: rename tearDownWorkOS → tearDownInteractsWithWorkOS |
| TEST-05 | Workbench example tests demonstrate WorkOS::fake() and actingAs() usage patterns | D-05 creates WorkOSFakeExampleTest.php; D-06 converts one existing test |

</phase_requirements>

## Standard Stack

### Core
| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| Pest PHP | 3.8.6 (package), 4.x (workbench) | Test runner | Already in use; `tests/Pest.php` configures it |
| PHPUnit | ^12.0 | Test runner foundation | Pest runs on top of PHPUnit; trait lifecycle hooks are PHPUnit conventions |
| Orchestra Testbench | ^9.0\|^10.0 | Package test infrastructure | `tests/TestCase` extends `Orchestra\Testbench\TestCase` |

[VERIFIED: vendor/pestphp/pest — v3.8.6 installed in package root]
[VERIFIED: codebase — tests/Pest.php, tests/TestCase.php]

**Installation:** No new packages needed — all test infrastructure is already present.

## Architecture Patterns

### Auto-TearDown via Laravel's `setUpTraits()`

**What:** Laravel's `InteractsWithTestCaseLifecycle::setUpTraits()` iterates over all traits used by the test class. For each trait it finds `tearDown` + `class_basename($trait)` as a method on `$this`, it registers it via `$this->beforeApplicationDestroyed(fn () => $this->{$method}())`. This fires before the application container is flushed, ensuring WorkOS::restore() runs before the container is torn down.

**Exact naming rule:** `tearDown` + `class_basename($traitFqcn)`. For `WorkOS\AuthKit\Testing\Concerns\InteractsWithWorkOS`, `class_basename` returns `InteractsWithWorkOS`. Required method name: `tearDownInteractsWithWorkOS`. [VERIFIED: vendor/laravel/framework/src/Illuminate/Foundation/Testing/Concerns/InteractsWithTestCaseLifecycle.php:241-248]

**When to use:** Any trait that needs automatic cleanup after each test, used with Laravel's TestCase.

**Reference example from Laravel itself (`InteractsWithRedis` in `vendor/laravel/framework/src/Illuminate/Foundation/Testing/Concerns/InteractsWithRedis.php`):**
```php
// Source: vendor/laravel/framework — InteractsWithRedis.php:32, 106
public function setUpRedis() { ... }
public function tearDownRedis() { ... }
```

**Pattern for our trait:**
```php
// Source: src/Testing/Concerns/InteractsWithWorkOS.php (after rename)
protected function tearDownInteractsWithWorkOS(): void
{
    WorkOS::restore();
}
```

**Important:** `tearDownWorkOS()` currently does NOT auto-invoke because `class_basename('InteractsWithWorkOS') = 'InteractsWithWorkOS'`, not `'WorkOS'`. The rename is the only correctness fix needed.

### DI Resolution via `app()->alias()`

The service provider registers:
```php
$this->app->singleton('workos', fn ($app) => new WorkOS(...));
$this->app->alias('workos', WorkOS::class);
```

When `WorkOS::fake()` calls `app()->instance('workos', $fake)`, the alias binding means `app(WorkOS::class)` and type-hinted constructor injection of `WorkOS` also resolve to the fake. [VERIFIED: src/WorkOSServiceProvider.php:57-63, src/WorkOS.php:181-187]

**DI verification test pattern (D-02):**
```php
it('DI-injected WorkOS resolves to the fake', function () {
    $fake = WorkOS::fake();

    $resolved = app(WorkOS::class);

    expect($resolved)->toBe($fake);
});
```

### Workbench Example Test Structure

The workbench uses Pest ^4.x with `workbench/tests/Pest.php` extending `Tests\TestCase` (Orchestra Testbench-backed) with `RefreshDatabase` for Feature tests.

**Recommended `WorkOSFakeExampleTest.php` structure — show both patterns:**

```php
// Pattern 1: Direct WorkOS::fake() (stateless, explicit teardown via afterEach)
it('demonstrates direct fake() pattern', function () {
    $fake = WorkOS::fake();
    $user = User::factory()->create();

    $fake->actingAs($user, roles: ['admin'], organizationId: 'org_123');

    $this->get('/dashboard')->assertOk();
    $fake->assertHasRole('admin');
    $fake->assertInOrganization('org_123');
});

// Pattern 2: InteractsWithWorkOS trait (auto-teardown)
// uses(InteractsWithWorkOS::class) at the top of the file or describe block
it('demonstrates trait pattern', function () {
    $user = User::factory()->create();
    $this->actingAsWorkOS($user, roles: ['editor']);

    $this->get('/dashboard')->assertOk();
    WorkOS::getFake()->assertHasRole('editor'); // or return value of actingAsWorkOS
});
```

**Note:** `actingAsWorkOS()` returns `WorkOSFake` — callers can chain assertions directly. [VERIFIED: src/Testing/Concerns/InteractsWithWorkOS.php:17-23]

### Recommended Workbench Test to Convert (D-06)

`workbench/tests/Feature/AuthTest.php` is the best candidate because:
- The `authenticated user can access dashboard` test uses `$this->actingAs($user, 'workos')` — the low-level Laravel guard approach
- Converting it shows the migration pattern from `actingAs($user, 'workos')` to `WorkOS::actingAs($user)` most clearly
- AuthTest is self-contained (no Livewire, no complex setup)

`workbench/tests/Feature/TodoTest.php` is a secondary option but uses Livewire, making it a longer conversion that may distract from the testing utility demonstration.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Auto-teardown registration | Custom tearDown() method + manual registration | `tearDownInteractsWithWorkOS()` naming convention | Laravel auto-discovers it via `setUpTraits()` |
| Fake swap | Custom container binding logic | `app()->instance('workos', $fake)` (already done) | `alias()` covers DI, facade, and `app()` access in one call |
| Assertion failures | Custom failure messages via Assert::fail() | PHPUnit Assert methods (already used in WorkOSFake) | Consistent failure output, correct assertion count tracking |

## Common Pitfalls

### Pitfall 1: Wrong tearDown Method Name
**What goes wrong:** If the method is named `tearDownWorkOS()` instead of `tearDownInteractsWithWorkOS()`, Laravel never auto-invokes it. Fake state bleeds between tests silently — assertions pass when they shouldn't.
**Why it happens:** Developers guess at the naming convention. `WorkOS` is the string that seems logical, but Laravel uses `class_basename($traitFqcn)` which returns the short trait class name.
**How to avoid:** Use exactly `tearDownInteractsWithWorkOS()`. Verified via `InteractsWithTestCaseLifecycle::setUpTraits()` line 245.
**Warning signs:** Tests that use `InteractsWithWorkOS` pass individually but fail when run in suite order.

### Pitfall 2: Pest-only Tests Don't Use Laravel's TestCase
**What goes wrong:** Pure Pest tests (no `uses(TestCase::class)`) don't run `setUpTraits()`, so `tearDownInteractsWithWorkOS()` never fires even with correct naming.
**Why it happens:** `workbench/tests/Pest.php` applies `uses(TestCase::class)->in('Feature')` — Feature tests get auto-teardown, but any test not in that directory needs explicit `uses()`.
**How to avoid:** Workbench Feature tests are covered by existing `Pest.php`. The existing `tests/Unit/WorkOSFakeTest.php` (package tests) manually calls `WorkOS::restore()` in `afterEach` — this is correct for unit tests that don't extend TestCase.
**Warning signs:** Fake not cleaning up in a specific test file; checking if `uses(TestCase::class)` is present.

### Pitfall 3: actingAsWorkOS() vs WorkOS::actingAs() Both Activate the Fake
**What goes wrong:** A test calls `$this->actingAsWorkOS()` without first calling `WorkOS::fake()`, but the trait method calls `WorkOS::actingAs()` which internally calls `fake()` — this is correct behavior but confusing if the test also does something with `WorkOS::isFaked()` expecting it to be false.
**Why it happens:** `InteractsWithWorkOS::actingAsWorkOS()` delegates to `WorkOS::actingAs()`, which calls `static::fake()` first.
**How to avoid:** Document in examples that both `fakeWorkOS()` and `actingAsWorkOS()` activate the fake. The teardown handles both paths.

### Pitfall 4: WorkbenchPest Version vs Package Pest Version
**What goes wrong:** Package root uses Pest ^3.x; workbench uses Pest ^4.x. API surface is the same for the patterns used here, but be aware tests in `workbench/tests/` run under Pest 4.
**How to avoid:** The example test file goes in `workbench/tests/Feature/` — it runs under Pest 4. The `WorkOSFakeTest.php` is in `tests/Unit/` and runs under Pest 3. No API differences affect the fake/actingAs patterns used.

## Code Examples

### Complete `tearDownInteractsWithWorkOS` rename
```php
// Source: src/Testing/Concerns/InteractsWithWorkOS.php (target state)
trait InteractsWithWorkOS
{
    protected function actingAsWorkOS(
        Authenticatable $user,
        array $roles = [],
        array $permissions = [],
        ?string $organizationId = null,
    ): WorkOSFake {
        return WorkOS::actingAs($user, $roles, $permissions, $organizationId);
    }

    protected function fakeWorkOS(): WorkOSFake
    {
        return WorkOS::fake();
    }

    protected function tearDownInteractsWithWorkOS(): void
    {
        WorkOS::restore();
    }
}
```

### DI resolution verification test (D-02)
```php
// Source: tests/Unit/WorkOSFakeTest.php (new test to add)
it('WorkOS::fake() causes DI-injected WorkOS to resolve to the fake', function () {
    $fake = WorkOS::fake();

    $resolved = app(WorkOS::class);

    expect($resolved)->toBe($fake);
});
```

### WorkOSFakeExampleTest.php skeleton
```php
// Source: workbench/tests/Feature/WorkOSFakeExampleTest.php (new file)
<?php

declare(strict_types=1);

use App\Models\User;
use WorkOS\AuthKit\Testing\Concerns\InteractsWithWorkOS;
use WorkOS\AuthKit\WorkOS;

// Pattern 1: Direct WorkOS::fake()
it('can authenticate as a user with roles', function () {
    $user = User::factory()->create();
    $fake = WorkOS::fake();

    $fake->actingAs($user, roles: ['admin'], organizationId: 'org_abc');

    $this->get('/dashboard')->assertOk();

    $fake->assertHasRole('admin');
    $fake->assertInOrganization('org_abc');
})->afterEach(fn () => WorkOS::restore());

// Pattern 2: InteractsWithWorkOS trait (teardown auto-registered)
describe('using InteractsWithWorkOS trait', function () {
    uses(InteractsWithWorkOS::class);

    it('can authenticate via trait convenience method', function () {
        $user = User::factory()->create();
        $fake = $this->actingAsWorkOS($user, roles: ['editor']);

        $this->get('/dashboard')->assertOk();
        $fake->assertHasRole('editor');
    });
});

// Pattern 3: Audit assertions
it('asserts audit events were captured', function () {
    $user = User::factory()->create();
    $fake = WorkOS::fake()->actingAs($user);

    $fake->audit('todo.created', targets: [], metadata: ['title' => 'My Task']);

    $fake->assertAudited('todo.created');
    $fake->assertNotAudited('todo.deleted');
});
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| `tearDownWorkOS()` (wrong name) | `tearDownInteractsWithWorkOS()` | This phase | Auto-invocation works correctly |
| Manual `afterEach(fn () => WorkOS::restore())` | Auto-teardown via trait | This phase | Less boilerplate for trait users |

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | `workbench/tests/Feature/AuthTest.php` is the best conversion candidate | Architecture Patterns | Low — Claude's Discretion per CONTEXT.md; planner can choose differently |
| A2 | Pest 4 and Pest 3 have identical API for `fake()`/`actingAs()` usage patterns shown | Common Pitfalls | Low — documented difference is version only, not API |

## Open Questions

1. **Does `uses(InteractsWithWorkOS::class)` within a `describe()` block trigger `setUpTraits()` in Pest 4?**
   - What we know: `setUpTraits()` is called in `setUp()` of the test case; Pest's `uses()` applies traits to the test case class
   - What's unclear: Whether `uses()` inside a `describe()` block creates a scoped sub-class or applies to the parent TestCase
   - Recommendation: Show both a file-level `uses()` pattern and an `afterEach(fn () => WorkOS::restore())` pattern in the example to be safe; the planner can choose based on testing

## Environment Availability

Step 2.6: SKIPPED (no external dependencies — this phase adds/modifies PHP source files and test files only; all tooling already verified present)

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | Pest PHP 3.8.6 (package), Pest 4.x (workbench) |
| Config file | `tests/Pest.php` (package), `workbench/tests/Pest.php` (workbench) |
| Quick run command | `composer test` |
| Full suite command | `composer test && composer analyse` |

### Phase Requirements → Test Map
| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| TEST-01 | WorkOS::fake() and DI injection both resolve to fake | unit | `./vendor/bin/pest tests/Unit/WorkOSFakeTest.php` | ✅ (new test added to existing file) |
| TEST-02 | actingAs() with roles/permissions/org context | unit | `./vendor/bin/pest tests/Unit/WorkOSFakeTest.php` | ✅ already tested |
| TEST-03 | assertAudited/assertNotAudited assertions | unit | `./vendor/bin/pest tests/Feature/AuditIntegrationTest.php` | ✅ already tested |
| TEST-04 | InteractsWithWorkOS auto-tears down fake | unit | `./vendor/bin/pest tests/Unit/WorkOSFakeTest.php` | ❌ Wave 0 — new test needed |
| TEST-05 | Workbench example tests exist and run | feature | `cd workbench && ./vendor/bin/pest tests/Feature/WorkOSFakeExampleTest.php` | ❌ Wave 0 — new file |

### Sampling Rate
- **Per task commit:** `./vendor/bin/pest tests/Unit/WorkOSFakeTest.php`
- **Per wave merge:** `composer test`
- **Phase gate:** `composer test && composer analyse` green before `/gsd-verify-work`

### Wave 0 Gaps
- [ ] `tests/Unit/WorkOSFakeTest.php` — add DI injection test (TEST-01) and trait auto-teardown test (TEST-04)
- [ ] `workbench/tests/Feature/WorkOSFakeExampleTest.php` — new file covering TEST-05

## Security Domain

This phase adds no authentication routes, no user input handling, no cryptography, and no external API calls. It modifies test infrastructure only. Security domain: NOT APPLICABLE.

## Sources

### Primary (HIGH confidence)
- `vendor/laravel/framework/src/Illuminate/Foundation/Testing/Concerns/InteractsWithTestCaseLifecycle.php:241-248` — verified `tearDown` + `class_basename($trait)` naming convention
- `vendor/laravel/framework/src/Illuminate/Foundation/Testing/Concerns/InteractsWithRedis.php:32,106` — verified concrete example of `setUpRedis()`/`tearDownRedis()` convention
- `src/Testing/WorkOSFake.php` — verified complete assertion surface (8 assertions)
- `src/Testing/Concerns/InteractsWithWorkOS.php` — verified current `tearDownWorkOS()` name (incorrect for auto-invocation)
- `src/WorkOS.php:181-211` — verified fake/restore/isFaked static methods
- `src/WorkOSServiceProvider.php:57-63` — verified singleton + alias registration
- `tests/Unit/WorkOSFakeTest.php` — verified 26 existing tests; identified DI test gap
- `workbench/tests/Pest.php` — verified `uses(TestCase::class)->in('Feature')` scope
- `workbench/tests/Feature/AuthTest.php` — verified candidate for D-06 conversion

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — verified from installed packages and existing source files
- Architecture (tearDown naming): HIGH — verified directly from Laravel source in vendor
- Pitfalls: HIGH — derived from reading actual source code, not assumptions

**Research date:** 2026-04-06
**Valid until:** 2026-07-06 (Laravel testing internals are stable; Pest 3/4 API stable)
