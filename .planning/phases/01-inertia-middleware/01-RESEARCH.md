# Phase 1: Inertia Middleware - Research

**Researched:** 2026-04-06
**Domain:** Laravel middleware, soft dependencies, Inertia.js shared props
**Confidence:** HIGH

## Summary

Phase 1 is a verification phase, not greenfield development. `ShareWorkOSData` middleware is fully implemented at `src/Http/Middleware/ShareWorkOSData.php`, registered as `workos.inertia` in `WorkOSServiceProvider::configureMiddleware()`, and covered by 7 passing tests in `tests/Feature/InertiaMiddlewareTest.php`.

All three quality gates pass against the current codebase: `composer test` (295 tests, 682 assertions), `composer analyse` (PHPStan level 8, 0 errors), and `composer format:test` (Pint, pass). The implementation correctly uses a `class_exists(Inertia::class)` guard so no hard Inertia dependency exists in `composer.json`.

The planner's job is to produce a single wave that documents what was verified, confirms requirements are met, and marks the phase complete — no code changes are expected.

**Primary recommendation:** Treat this phase as a sign-off audit. Verify implementation completeness against INRT-01/INRT-02, confirm quality gates pass, mark requirements done.

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions
- **D-01:** ShareWorkOSData middleware is already fully implemented at `src/Http/Middleware/ShareWorkOSData.php` with 7 passing tests. Phase 1 is verification and polish, not greenfield development.
- **D-02:** The `workos.inertia` alias is registered in `WorkOSServiceProvider::configureMiddleware()` pointing to `ShareWorkOSData::class`.
- **D-03:** `class_exists(Inertia::class)` runtime guard is sufficient — no `suggest` entry in `composer.json` or conditional service provider registration needed.
- **D-04:** The `auth` prop shape is: `check`, `user` (id, workos_id, name, email), `roles`, `permissions`, `organization`, `impersonating`, `impersonator`. This is the complete surface for Phase 1.
- **D-05:** Sensitive fields (`accessToken`, `refreshToken`, `expiresAt`, `sessionId`) are intentionally excluded from shared props.
- **D-06:** Inertia::share() closures are evaluated lazily — only when rendering Inertia responses. The current closure-based approach is correct.

### Claude's Discretion
- Whether to add `organizationName` to props (currently only `organizationId`) — defer to Phase 4 workbench needs
- PHPStan annotation style for dynamic Authenticatable property access

### Deferred Ideas (OUT OF SCOPE)
- Adding `organizationName` to shared props — evaluate during Phase 4
- Session expiry info in props for client-side refresh — excluded per D-05
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| INRT-01 | ShareWorkOSData middleware shares auth state (user, org, roles, permissions, impersonation) to Inertia props | VERIFIED: Implementation shares `check`, `user` (id, workos_id, name, email), `roles`, `permissions`, `organization`, `impersonating`, `impersonator`. All 7 tests pass. |
| INRT-02 | ShareWorkOSData guards with class_exists check — no hard Inertia dependency | VERIFIED: `class_exists(Inertia::class)` guard at line 21 of ShareWorkOSData.php. No `inertiajs/inertia-laravel` entry in composer.json require or suggest. Test "middleware passes through when Inertia is not installed" confirms non-Inertia apps are unaffected. |
</phase_requirements>

## Standard Stack

### Core
| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| illuminate/contracts | ^11.0\|^12.0 | Laravel middleware contracts | Already in composer.json require |
| inertiajs/inertia-laravel | (not required) | Inertia integration — optional consumer dependency | Soft dependency via class_exists guard |

### Supporting
None beyond what's already in the package.

**Version verification:** No new packages to install. All existing dependencies verified via `composer.json`. [VERIFIED: composer.json]

## Architecture Patterns

### Middleware Registration
Aliases registered in `WorkOSServiceProvider::configureMiddleware()` using `$router->aliasMiddleware()`. Pattern is consistent across all 8 middleware aliases in the provider. [VERIFIED: src/WorkOSServiceProvider.php:181-193]

### Soft Dependency Pattern
```php
// Source: src/Http/Middleware/ShareWorkOSData.php:21-23
if (! class_exists(Inertia::class)) {
    return $next($request);
}
```
Guard at top of `handle()` means zero overhead and zero errors when Inertia is absent.

### Lazy Shared Props
```php
// Source: src/Http/Middleware/ShareWorkOSData.php:25-27
Inertia::share([
    'auth' => fn () => $this->getAuthData($request),
]);
```
Closure defers evaluation until Inertia renders a response. JSON/API responses never trigger it.

### Auth Data Shape
```php
// Source: src/Http/Middleware/ShareWorkOSData.php:57-70 (authenticated path)
[
    'check' => true,
    'user' => [
        'id' => $user->getAuthIdentifier(),
        'workos_id' => $workosId,  // null-safe: only present if getWorkOSId() exists
        'name' => $user->name ?? null,
        'email' => $user->email ?? null,
    ],
    'roles' => $session?->roles ?? [],
    'permissions' => $session?->permissions ?? [],
    'organization' => $session?->organizationId,
    'impersonating' => $session?->impersonator !== null,
    'impersonator' => $session?->impersonator,
]
```

### Anti-Patterns to Avoid
- **Hard Inertia import without guard:** `use Inertia\Inertia` at class level is fine because PHP autoloading only triggers when the class is instantiated or methods called, but the `class_exists` guard at runtime is the actual protection.
- **Eager evaluation in share():** Passing a raw array (not a closure) would execute even for non-Inertia responses.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Inertia prop sharing | Custom response transformer | `Inertia::share()` with closure | Handles lazy evaluation, merge behavior, and response detection natively |
| Soft dependency detection | Custom autoloader probe | `class_exists()` | Standard PHP, zero overhead, no composer overhead |

## Runtime State Inventory

Step 2.5 SKIPPED — this is not a rename/refactor/migration phase.

## Environment Availability

Step 2.6 SKIPPED — phase is code/config-only. All dependencies are PHP packages already installed in the project. Quality gate commands verified directly:

| Command | Available | Result |
|---------|-----------|--------|
| `composer test` | ✓ | 295 passed, 0 failed |
| `composer analyse` | ✓ | 0 errors (PHPStan level 8) |
| `composer format:test` | ✓ | pass |

## Common Pitfalls

### Pitfall 1: Assuming phase requires code changes
**What goes wrong:** Planner creates tasks to implement or modify ShareWorkOSData when it's already complete.
**Why it happens:** "Phase 1" framing implies work to do.
**How to avoid:** D-01 is explicit — implementation exists and 7 tests pass. Plan tasks are audit/verification only.
**Warning signs:** Any task that modifies `src/Http/Middleware/ShareWorkOSData.php`.

### Pitfall 2: PHPStan dynamic property access on Authenticatable
**What goes wrong:** `$user->name` and `$user->email` access at lines 62-63 may trigger PHPStan if annotation coverage is insufficient.
**Why it happens:** `Authenticatable` contract doesn't declare `name`/`email` properties; they live on the concrete model.
**How to avoid:** Current implementation uses `$user->name ?? null` which is already passing PHPStan level 8. No change needed. [VERIFIED: composer analyse output]
**Warning signs:** PHPStan error on "Access to an undefined property" in ShareWorkOSData.

### Pitfall 3: Test for `workos.inertia` alias checks both middleware group and route middleware
**What goes wrong:** Test at line 211-217 uses `hasMiddlewareGroup() || isset($router->getMiddleware()['workos.inertia'])` — an OR check.
**Why it happens:** `aliasMiddleware()` registers in route middleware (not groups). The test's fallback `isset()` branch is what actually passes.
**How to avoid:** No change needed. This is working correctly. Understand the distinction when reading test output.

## Code Examples

### Full middleware implementation (verified complete)
`src/Http/Middleware/ShareWorkOSData.php` — 72 lines, fully implemented. [VERIFIED: read directly]

### Alias registration (verified)
`src/WorkOSServiceProvider.php:192` — `$router->aliasMiddleware('workos.inertia', ShareWorkOSData::class);` [VERIFIED: read directly]

### Consumer usage pattern (for documentation reference)
```php
// In a route group or global middleware stack:
Route::middleware(['auth:workos', 'workos.inertia'])->group(function () {
    // Inertia pages will receive auth props automatically
});
```

## State of the Art

| Area | Current Implementation | Notes |
|------|----------------------|-------|
| Inertia::share() API | Closure-based lazy evaluation | Correct for Inertia v1+ [ASSUMED: based on training knowledge of inertiajs/inertia-laravel] |
| Soft dependency detection | `class_exists()` | Standard Laravel package pattern |

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | Inertia::share() closure-based lazy evaluation is the correct v1+ API | State of the Art | Low — implementation already passes tests with the Inertia facade mock; actual API shape confirmed working |

## Open Questions

1. **PHPStan annotation for dynamic property access**
   - What we know: Current code passes PHPStan level 8 without any `@phpstan-ignore` annotations
   - What's unclear: Whether a future PHPStan upgrade could flag `$user->name` / `$user->email` access
   - Recommendation: No action needed for Phase 1. Flag for Phase 2 if PHPStan is upgraded.

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | Pest PHP ^3.0 |
| Config file | `phpunit.xml` |
| Quick run command | `composer test -- --filter=InertiaMiddleware` |
| Full suite command | `composer test` |

### Phase Requirements → Test Map
| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| INRT-01 | Shares auth state (user, org, roles, permissions, impersonation) to Inertia props | Feature | `composer test -- --filter=InertiaMiddleware` | ✅ `tests/Feature/InertiaMiddlewareTest.php` |
| INRT-02 | Guards with class_exists — no hard Inertia dependency | Feature | `composer test -- --filter=InertiaMiddleware` | ✅ "passes through when Inertia is not installed" test |

### Sampling Rate
- **Per task commit:** `composer test -- --filter=InertiaMiddleware`
- **Per wave merge:** `composer test && composer analyse && composer format:test`
- **Phase gate:** Full suite green before `/gsd-verify-work`

### Wave 0 Gaps
None — existing test infrastructure covers all phase requirements. 7 tests exist and pass.

## Security Domain

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | no | auth state is read-only; no auth decisions made here |
| V3 Session Management | no | session is read via SessionManager, not modified |
| V4 Access Control | no | middleware shares data, does not enforce access |
| V5 Input Validation | no | no user input processed |
| V6 Cryptography | no | session decryption handled upstream in SessionManager |

**Key security property confirmed:** Sensitive fields `accessToken`, `refreshToken`, `expiresAt`, `sessionId` are excluded from shared props (D-05). Only non-sensitive identity and authorization data is shared. [VERIFIED: src/Http/Middleware/ShareWorkOSData.php:57-70]

## Sources

### Primary (HIGH confidence)
- Direct read: `src/Http/Middleware/ShareWorkOSData.php` — full implementation verified
- Direct read: `src/WorkOSServiceProvider.php:181-193` — alias registration verified
- Direct read: `tests/Feature/InertiaMiddlewareTest.php` — 7 tests verified
- `composer test` output — 295 tests pass, 7 in InertiaMiddlewareTest
- `composer analyse` output — PHPStan level 8, 0 errors
- `composer format:test` output — Pint, pass

### Secondary (MEDIUM confidence)
None needed — all claims verified directly against codebase.

### Tertiary (LOW confidence)
None.

## Metadata

**Confidence breakdown:**
- Implementation completeness: HIGH — verified by reading source and running tests
- Quality gates: HIGH — ran all three gates, all pass
- Requirements coverage: HIGH — mapped INRT-01/INRT-02 to specific code lines and tests

**Research date:** 2026-04-06
**Valid until:** Stable indefinitely (no external dependencies, implementation is static)
