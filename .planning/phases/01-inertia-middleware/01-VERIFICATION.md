---
phase: 01-inertia-middleware
verified: 2026-04-06T19:38:46Z
status: passed
score: 3/3 must-haves verified
---

# Phase 1: Inertia Middleware Verification Report

**Phase Goal:** Package consumers can share WorkOS auth state to Inertia frontends without any hard Inertia dependency
**Verified:** 2026-04-06T19:38:46Z
**Status:** passed
**Re-verification:** No — initial verification

## Goal Achievement

### Observable Truths

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | An Inertia app can read authenticated user, org, roles, permissions, and impersonation state from shared props without additional boilerplate | ✓ VERIFIED | `getAuthData()` returns all 7 keys (check, user, roles, permissions, organization, impersonating, impersonator) via `Inertia::share()` closure in `handle()` |
| 2 | Installing the package in a non-Inertia app does not trigger any Inertia-related errors or warnings | ✓ VERIFIED | `class_exists(Inertia::class)` guard at line 21 passes through without error; `inertiajs/inertia-laravel` absent from composer.json require and require-dev; class loads cleanly without Inertia installed (confirmed via PHP CLI) |
| 3 | The `workos.inertia` middleware alias resolves to a working class | ✓ VERIFIED | `$router->aliasMiddleware('workos.inertia', ShareWorkOSData::class)` at `WorkOSServiceProvider.php:192`; alias test passes |

**Score:** 3/3 truths verified

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `src/Http/Middleware/ShareWorkOSData.php` | Middleware with class_exists guard and lazy Inertia::share() | ✓ VERIFIED | 72 lines; `class_exists(Inertia::class)` at line 21; `fn () => $this->getAuthData($request)` closure at line 26 |
| `src/WorkOSServiceProvider.php` | workos.inertia alias registration | ✓ VERIFIED | `aliasMiddleware('workos.inertia', ShareWorkOSData::class)` at line 192; `ShareWorkOSData` imported at line 36 |
| `tests/Feature/InertiaMiddlewareTest.php` | 7 tests covering auth/unauth/impersonation/alias states | ✓ VERIFIED | 217 lines; 7 tests, 27 assertions; all pass |

### Key Link Verification

| From | To | Via | Status | Details |
|------|----|-----|--------|---------|
| `src/WorkOSServiceProvider.php` | `src/Http/Middleware/ShareWorkOSData.php` | `aliasMiddleware('workos.inertia', ShareWorkOSData::class)` | ✓ WIRED | Line 192; ShareWorkOSData imported at line 36; alias test confirms runtime resolution |
| `src/Http/Middleware/ShareWorkOSData.php` | `Inertia::share()` | closure-based lazy evaluation `fn () => $this->getAuthData` | ✓ WIRED | Line 25-27; closure defers evaluation until Inertia renders; guard at line 21 protects non-Inertia apps |

### Data-Flow Trace (Level 4)

Not applicable — ShareWorkOSData is middleware that shares server-side session state, not a component that fetches and renders external data. The data source is `$request->user()` and `SessionManager::getSession()`, both of which are standard Laravel request/session objects populated by the WorkOS auth guard.

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| 7 InertiaMiddleware tests pass | `composer test -- --filter=InertiaMiddleware` | 7 passed (27 assertions), exit 0 | ✓ PASS |
| Full test suite passes | `composer test` | 295 passed (682 assertions), exit 0 | ✓ PASS |
| PHPStan level 8 clean | `composer analyse` | 0 errors | ✓ PASS |
| Pint style check passes | `composer format:test` | pass | ✓ PASS |

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
|-------------|-------------|-------------|--------|---------|
| INRT-01 | 01-01-PLAN.md | ShareWorkOSData middleware shares auth state (user, org, roles, permissions, impersonation) to Inertia props | ✓ SATISFIED | `getAuthData()` returns check/user/roles/permissions/organization/impersonating/impersonator via lazy `Inertia::share()` closure |
| INRT-02 | 01-01-PLAN.md | ShareWorkOSData guards with class_exists check — no hard Inertia dependency | ✓ SATISFIED | `class_exists(Inertia::class)` at line 21; no Inertia package in composer.json; class loads and passes through cleanly without Inertia installed |

### Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
|------|------|---------|----------|--------|
| `src/Http/Middleware/ShareWorkOSData.php` | 40-48 | Unauthenticated return omits `impersonator` key (returns 6 keys; authenticated returns 7) | ℹ️ Info | Inconsistent prop shape between auth states. Frontend code accessing `auth.impersonator` on unauthenticated responses gets `undefined` instead of `null`. Safe in practice since impersonation requires authentication, but breaks prop shape consistency. Not a blocker — no test coverage gap was detected because the unauthenticated test does not assert the full key set. |

**Note on `use Inertia\Inertia` import:** The middleware file has a top-level `use Inertia\Inertia;` statement despite Inertia not being a declared dependency. PHP resolves `use` aliases lazily — the class is not autoloaded at parse time, only when the symbol is referenced at runtime. Since the `class_exists(Inertia::class)` guard fires before any live reference, no fatal error occurs in non-Inertia environments. Verified via PHP CLI.

### Human Verification Required

None — all required behaviors are verifiable programmatically.

### Gaps Summary

No gaps blocking goal achievement. All three roadmap success criteria are satisfied, both INRT-01 and INRT-02 requirements are implemented and tested, and the full quality gate suite passes.

One informational finding: the unauthenticated `getAuthData()` return omits the `impersonator` key present in the authenticated return. This produces a minor prop-shape inconsistency but does not prevent the goal from being achieved and is not flagged as a gap given that tests pass and the behavior is logically defensible.

---

_Verified: 2026-04-06T19:38:46Z_
_Verifier: Claude (gsd-verifier)_
