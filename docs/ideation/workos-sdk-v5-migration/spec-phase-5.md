# Implementation Spec: WorkOS SDK v5 Migration - Phase 5

**Contract**: ./contract.md
**Estimated Effort**: M

## Technical Approach

Phase 5 is the final verification and cleanup pass. All code changes are complete in Phases 1-4. This phase ensures everything works together:

1. **WorkOSFake completeness** — every method on `WorkOS.php` must have a corresponding stub or implementation in `WorkOSFake`. Phase 1 did baseline stubs; this phase verifies full behavioral correctness.
2. **PHPStan level 8** — resolve any remaining type errors across the entire `src/` directory. Earlier phases may have left `mixed` return types or unresolved v5 class references.
3. **Pint formatting** — ensure all modified files conform to the Laravel preset with strict types.
4. **Full test suite green** — every test must pass. Fix any tests broken by cross-phase interactions.
5. **Workbench app verification** — boot the workbench app, verify the auth flow works end-to-end.

## Feedback Strategy

**Inner-loop command**: `composer analyse && composer test`

**Playground**: Full validation suite — this phase validates the complete migration.

**Why this approach**: This is an integration verification phase. Individual component tests ran in earlier phases; now we need the full suite to catch cross-cutting issues.

## File Changes

### Modified Files

| File Path | Changes |
|---|---|
| `src/Testing/WorkOSFake.php` | Complete behavioral parity with `WorkOS.php` — all service accessors, new v5 methods, updated assertions |
| `src/Testing/Concerns/InteractsWithWorkOS.php` | Update if `WorkOSFake` API changed |
| Any file with PHPStan errors | Fix type annotations, add missing return types, resolve `mixed` to concrete v5 types |
| Any file with Pint violations | Auto-fix formatting |
| `workbench/` files | Update if needed for v5 compatibility |

## Implementation Details

### 1. WorkOSFake Complete Update

**Pattern to follow**: `src/Testing/WorkOSFake.php`

**Overview**: WorkOSFake must mirror every public method on `WorkOS.php`. Phase 1 added basic stubs. Now verify and complete:

**Implementation steps**:

1. Compare `WorkOS.php` public methods with `WorkOSFake.php` — identify any missing methods
2. For service accessors (e.g., `vault()`, `radar()`, `connect()`): return null or a mock — these are passthrough to the SDK, and tests that need them should mock the SDK service directly
3. For convenience methods (`loginUrl()`, `signUpUrl()`, `validateApiKey()`): ensure they return sensible fake values
4. For assertion methods (`assertAuthenticated()`, `assertHasRole()`, etc.): verify they still work with v5's `WorkOSSession` structure
5. Ensure `actingAs()` creates a valid `WorkOSSession` using the v5 factory methods

**Key decisions**:
- WorkOSFake doesn't need to fake the actual SDK client — it replaces the entire `WorkOS` service in the container
- New service accessors on the Fake can throw "not faked" exceptions or return null — real SDK testing should use `Http::fake()` or testbench
- The critical methods to get right are `actingAs()`, `session()`, `validSession()`, and all assertion methods

**Feedback loop**:
- **Playground**: Tests that use `WorkOS::fake()` or `WorkOS::actingAs()`
- **Experiment**: Run every test file that imports `InteractsWithWorkOS` or calls `WorkOS::fake()`
- **Check command**: `composer test -- --filter=Fake`

### 2. PHPStan Level 8 Pass

**Overview**: Run PHPStan and fix all errors. Common issues after a major SDK migration:

**Expected error categories**:

1. **Missing v5 class references** — a v4 class was referenced that doesn't exist in v5
2. **Wrong return types** — method declared `mixed` but should be a specific v5 type
3. **Parameter type mismatches** — v5 method expects different parameter types
4. **Missing method errors** — called a v4 method name that was renamed in v5
5. **Property access on wrong type** — v5 returns a different object than v4

**Implementation steps**:

1. Run `composer analyse` and capture all errors
2. Group errors by category
3. Fix in order: missing classes → wrong method names → wrong types → wrong properties
4. Re-run after each batch of fixes
5. Iterate until zero errors

**Feedback loop**:
- **Playground**: PHPStan
- **Experiment**: Fix one error category at a time, re-run
- **Check command**: `composer analyse`

### 3. Pint Formatting

**Overview**: Auto-fix all formatting issues.

**Implementation steps**:

1. Run `composer format` (which runs `vendor/bin/pint`)
2. Review the diff — Pint may reformat code that was manually adjusted during migration
3. Ensure `declare(strict_types=1);` is present in every modified PHP file

### 4. Full Test Suite

**Overview**: Run `composer test` and fix any remaining failures.

**Expected failure categories**:

1. **Mock setup outdated** — test mocks a v4 class that no longer exists
2. **Assertion on v4 response shape** — test expects v4 array keys that v5 doesn't return
3. **Missing test dependency** — test needs a v5 fixture that doesn't exist
4. **Cross-phase interaction** — Phase 2 change affects Phase 3 code path

**Implementation steps**:

1. Run `composer test` and capture all failures
2. Group by root cause
3. Fix mock setup first (most common)
4. Fix assertion shapes second
5. Fix integration issues last
6. Iterate until green

### 5. Workbench App Verification

**Overview**: Boot the workbench Laravel app and verify the authentication flow works end-to-end.

**Implementation steps**:

1. `cd workbench && composer update` — ensure workbench picks up the v5 SDK
2. `php artisan migrate:fresh --seed`
3. `php artisan serve`
4. Navigate to login → verify redirect to AuthKit
5. Complete login → verify callback handles correctly, session is set
6. Navigate to protected route → verify auth middleware works
7. Logout → verify session is destroyed
8. Test organization switching if applicable

**Failure Modes**:

| Failure | Likely Cause | Fix |
|---|---|---|
| Workbench won't boot | Missing v5 class reference | Fix the import |
| Login redirect fails | `loginUrl()` v5 changes broke URL generation | Check Phase 1 loginUrl implementation |
| Callback crashes | `authenticateWithCode()` response shape changed | Check Phase 2 AuthController |
| Session not persisting | Sealed cookie format changed in v5 | Check Phase 2 SessionManager |
| Protected route 403 | Session validation not working | Check Phase 2 WorkOSSession factory |

## Validation Commands

```bash
# Complete validation suite
composer analyse
composer test
composer format:test

# Manual verification
cd workbench && php artisan serve

# Success criteria checks
grep -rn "Client::request" src/ --include="*.php"  # Should be empty
grep -rn "Http::withHeaders.*Bearer" src/ --include="*.php"  # Should be empty  
grep -rn "new GuzzleHttp\\\\Client" src/ --include="*.php"  # Should be empty

# Verify all v5 services accessible
cd workbench && php artisan tinker --execute="
\$w = app('workos');
echo 'userManagement: ' . get_class(\$w->userManagement()) . PHP_EOL;
echo 'organizations: ' . get_class(\$w->organizations()) . PHP_EOL;
echo 'auditLogs: ' . get_class(\$w->auditLogs()) . PHP_EOL;
echo 'OK' . PHP_EOL;
"
```

## Rollout Considerations

- **Version bump**: After this phase passes, bump the package version in `composer.json` (pre-1.0 breaking change)
- **Changelog**: Document all breaking changes for consumers
- **Minimum PHP**: Our constraint is already `^8.3`, above v5's `^8.2` minimum — no change needed
- **Halite**: v5 requires Halite 5.1 — verify no conflicts with consumer applications

---

_This spec is ready for implementation. Follow the patterns and validate at each step._
