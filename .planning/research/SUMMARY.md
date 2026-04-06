# Project Research Summary

**Project:** birdcar/authkit-laravel (milestone additions)
**Domain:** Laravel authentication package — developer experience, CI/CD, testing utilities
**Researched:** 2026-04-06
**Confidence:** HIGH

## Executive Summary

This project is a mature Laravel package that integrates WorkOS AuthKit. Core authentication is fully implemented — OAuth flow, session management, RBAC middleware, webhook handling, org management, and audit logging all exist and work. The active milestone is entirely about developer experience maturity: a Smart Install command with conflict detection, testing utilities that allow consumers to write tests against WorkOS-authenticated routes, a GitHub Actions CI/CD pipeline, and a workbench example app demonstrating all features. The work is additive and does not require changes to core auth logic.

The recommended approach is to build in dependency order. `ShareWorkOSData` middleware is the highest-risk incomplete item (registered as an alias in the package but unimplemented, causing silent failures if consumed). Testing utilities are the highest-value delivery (no other WorkOS Laravel package provides them; this is the most common complaint about `laravel/workos`). The workbench example app depends on both, so it comes last. CI/CD is already substantially implemented — the gap is wiring and hardening, not building from scratch.

The primary risks are credential exposure (`workbench/auth.json` with live Flux Pro credentials not confirmed in `.gitignore`), silent failures from regex-based PHP file manipulation in the install command, and test isolation issues if `WorkOS::fake()` does not properly swap the container binding. All three are preventable with specific, targeted mitigations identified in research.

## Key Findings

### Recommended Stack

No new dependencies are needed for any of the four milestone deliverables. The CI stack uses `shivammathur/setup-php v2`, `actions/checkout v4`, and `actions/cache v4` — the de-facto standard for PHP packages on GitHub Actions. The test matrix covers PHP 8.3/8.4 x Laravel 11/12 on ubuntu-latest only (Windows adds cost with no diagnostic value for this package type). Auto-release uses the author's own `birdcar/actions/auto-release@main` composite action, which is already confirmed functional. Smart Install detection uses `Composer\InstalledVersions` (stable Composer 2 API, no subprocess, no PATH dependency). The Inertia middleware must use a soft `class_exists(\Inertia\Inertia::class)` guard — do not add `inertiajs/inertia-laravel` as a hard dependency.

Documentation stays as a single `README.md`. No VitePress or Docusaurus — premature overhead for a single package at this stage.

**Core technologies:**
- `shivammathur/setup-php v2`: PHP CI setup — de-facto standard, handles extensions and multiple PHP versions cleanly
- `birdcar/actions/auto-release@main`: semver tagging + CHANGELOG — author's own action, label-driven, already confirmed
- `Composer\InstalledVersions`: package detection in Smart Install — synchronous, zero subprocess, stable API
- `class_exists(\Inertia\Inertia::class)`: soft Inertia detection — prevents hard dep forcing Inertia on all users
- `Illuminate\Foundation\Testing\TestCase`: workbench tests — NOT orchestra/testbench, which is for the package's own unit tests

### Expected Features

The package already satisfies all table stakes for a Laravel auth package. The active milestone delivers differentiators that no competing package (including `laravel/workos`) provides.

**Must have (table stakes, already done):**
- OAuth login/callback/logout, Laravel auth guard, cookie sessions, route middleware, RBAC middleware + Blade directives, webhook verification, user model traits, publishable config, artisan install command, PHPStan + Pest CI coverage, comprehensive README

**Should have (differentiators, active milestone):**
- `WorkOS::fake()` + `WorkOS::actingAs()` — drops the #1 friction point for testing AuthKit apps; Sanctum has this, WorkOS does not
- `assertAudited()` / `assertNotAudited()` — makes audit logging testable the same way Laravel tests queued jobs
- Smart Install conflict detection — detects laravel/workos, Breeze, Jetstream, Fortify and adapts; eliminates "I broke my app" installs
- `ShareWorkOSData` Inertia middleware — pre-built Inertia integration; saves boilerplate for every Inertia+WorkOS project
- Workbench example app with feature tests — demonstrates every feature in a running Todo app, rare for auth packages
- Config migration from `services.php` (laravel/workos) — lets teams upgrading from official package keep working

**Defer:**
- Config migration from services.php — useful but narrow audience; ship after core DX features land

**Anti-features (explicitly do not build):**
- Auth views, UI components, Vue/React/Svelte components, custom SSO config UI, MFA/Passwordless flows, Directory Sync webhook handling, multi-tenancy database separation

### Architecture Approach

All four milestone components attach to the existing package at well-defined seams without touching core auth logic. The architecture is already designed correctly — the milestone is completion and hardening, not redesign. Key patterns to follow: Facade swap for `WorkOS::fake()` (same as Laravel's `Mail::fake()`, `Queue::fake()`), workbench as a standalone Laravel 12 app (NOT orchestra/testbench), Detection-then-Action separation in install command (read-only `EnvironmentDetector` produces immutable `DetectionResult`; write operations happen in separate installers), and matrix CI via `composer require "illuminate/support:$laravel" --no-update` + `composer update`.

**Major components:**
1. `src/Install/` (WizardFlow, EnvironmentDetector, Plans/, installers) — Smart Install orchestration; fully implemented, needs `--force`/`--mini` flags on InstallCommand
2. `src/Testing/` (WorkOSFake, InteractsWithWorkOS) — test double infrastructure; fully implemented, needs proper `Facade::swap()` wiring verification
3. `src/Http/Middleware/ShareWorkOSData` — Inertia shared props middleware; registered as alias but unimplemented
4. `workbench/` — standalone Laravel 12 example app; partial, needs feature completion and test suite
5. `.github/workflows/` — CI + release pipelines; fully implemented, needs serial group enforcement and workbench PHP constraint fix

### Critical Pitfalls

1. **`workbench/auth.json` credentials in git** — Flux Pro license token not confirmed in `.gitignore`. Add `auth.json` to `workbench/.gitignore` before any workbench commit. Run `git ls-files workbench/auth.json` to verify.
2. **Regex file manipulation silently fails** — `preg_replace()` on `auth.php`, `User.php`, `services.php` returns original string unchanged when patterns don't match. After every write, verify expected string exists in file; always fall through to manual instructions on failure.
3. **`WorkOS::fake()` must swap the container binding** — Code resolved via dependency injection (not facade) will hit the real WorkOS API if `app()->instance('workos', $fake)` is not called. Implement via `static::swap(new WorkOSFake())` and add a test resolving via DI after fake.
4. **`WorkOSFake` state bleeds between tests** — `$auditedEvents` accumulates across test lifetime. Call `WorkOS::fake()` in `beforeEach` (not `beforeAll`) to create a fresh instance each test.
5. **Install command idempotency** — Running `workos:install` twice can produce duplicate trait imports or guard blocks. Add an integration test that runs install twice against fixture files and asserts identical output.

## Implications for Roadmap

Based on research, suggested phase structure:

### Phase 1: ShareWorkOSData Middleware
**Rationale:** Already registered as `workos.inertia` alias in `configureMiddleware()` but unimplemented — an unimplemented registered alias is a silent failure waiting to happen. Zero dependencies on other unbuilt components. Lowest complexity of the four milestones.
**Delivers:** Functional Inertia middleware that shares auth state, org, and impersonation props with the frontend; resolves the dangling alias
**Addresses:** ShareWorkOSData differentiator from FEATURES.md
**Avoids:** Silent failure from consuming an unimplemented middleware alias

### Phase 2: Testing Utilities Hardening
**Rationale:** `WorkOSFake` and `InteractsWithWorkOS` are fully implemented but the container swap and teardown patterns need to be verified and tested. This unblocks workbench tests and consumer adoption. Highest-value delivery — fills the #1 gap vs `laravel/workos`.
**Delivers:** Verified `WorkOS::fake()` with proper `Facade::swap()` wiring, test isolation guarantee via `InteractsWithWorkOS::tearDownWorkOS()`, `assertAudited()` / `assertNotAudited()` assertions with documented usage
**Addresses:** WorkOS::fake(), actingAs(), assertAudited() differentiators from FEATURES.md
**Avoids:** DI bypass (Pitfall 4), state bleed between tests (Pitfall 10)

### Phase 3: Workbench Example App Completion
**Rationale:** Depends on ShareWorkOSData (Phase 1) for Inertia demo and testing utilities (Phase 2) for realistic feature tests. PHP constraint mismatch (`^8.2` vs required `^8.3`) and auth.json exposure must be resolved before any commit to this directory.
**Delivers:** Complete Laravel 12 Todo app demonstrating all package features (Livewire, Flux Pro, Admin Portal intents, Inertia), feature tests using `WorkOS::fake()->actingAs()` for middleware-heavy paths
**Addresses:** Workbench example app + feature tests differentiators from FEATURES.md
**Avoids:** auth.json credentials in git (Pitfall 1), actingAs without WorkOS session (Pitfall 5), PHP version mismatch (Pitfall 8), workbench tests using testbench instead of real TestCase

### Phase 4: Smart Install Hardening
**Rationale:** Detection and wizard flow are complete. Remaining work is additive: `--force`/`--mini` flags on `InstallCommand`, post-write verification for regex operations, `.env.example` sync, and idempotency test. Most complex phase due to file manipulation surface area. Decoupled from other phases so its complexity does not block high-value DX items.
**Delivers:** Three-mode install command (`--force`, wizard default, `--mini`), robust post-write verification with manual fallback, `.env.example` kept in sync, idempotency guaranteed by integration test, hardcoded `User::class` reference replaced with config-resolved value
**Addresses:** Smart Install conflict detection + config migration differentiators from FEATURES.md
**Avoids:** Regex silent failure (Pitfall 2), non-idempotent install (Pitfall 3), missing .env.example update (Pitfall 9), hardcoded User::class (Pitfall 12)

### Phase 5: CI/CD Hardening and README
**Rationale:** CI is largely complete. Remaining work is serial group enforcement, workbench PHP constraint alignment, and the README. README cannot be written comprehensively until all features exist, making this the natural final phase.
**Delivers:** Hardened CI with serial group exclusion from parallel runs, workbench PHP constraint aligned to `^8.3`, comprehensive `.github/README.md` with all features documented, config migration from `services.php` for laravel/workos upgraders
**Addresses:** CI/CD table stakes + README table stakes from FEATURES.md; config migration differentiator
**Avoids:** Serial group parallel race (Pitfall 11), workbench PHP mismatch (Pitfall 8), PHPStan gap on workbench code (Pitfall 13), tag spam from unlabeled releases (Pitfall 7)

### Phase Ordering Rationale

- ShareWorkOSData first because it has an active failure mode (unimplemented alias) and no upstream dependencies
- Testing utilities second because workbench tests depend on them and it is the highest-value differentiator
- Workbench third because it is downstream of both Phase 1 and Phase 2; resolving the auth.json risk before committing here is mandatory
- Smart Install fourth because it is independently testable and the most complex; separating it prevents its complexity from blocking the high-value DX items
- CI/CD + README last because CI already works adequately for development; README cannot be complete until all features exist

### Research Flags

Phases likely needing deeper research during planning:
- **Phase 4 (Smart Install):** The `--mini` mode behavior is not fully specified. Research needed on what "mini" means vs wizard vs force — likely a non-interactive install that skips conflict detection and applies defaults.
- **Phase 3 (Workbench):** Admin Portal intents coverage — which specific WorkOS Admin Portal intents should be demonstrated is not specified in research. Check WorkOS docs for current intent list before phase planning.

Phases with standard patterns (skip research-phase):
- **Phase 1 (ShareWorkOSData):** Inertia shared data is a well-documented pattern; `class_exists` soft-detection is established convention
- **Phase 2 (Testing Utilities):** Laravel facade swap pattern is identical to Mail::fake(), Queue::fake() — well-documented, no novel patterns needed
- **Phase 5 (CI/CD):** All patterns are established and already partially implemented in the repo

## Confidence Assessment

| Area | Confidence | Notes |
|------|------------|-------|
| Stack | HIGH | Read directly from existing codebase files and verified against spatie/laravel/workos CI configs |
| Features | HIGH | Grounded in official Laravel docs (Sanctum, Starter Kits) and direct comparison to laravel/workos |
| Architecture | HIGH | Read directly from src/, .github/workflows/, workbench/ — existing code, not inference |
| Pitfalls | HIGH | Most findings from direct codebase inspection; container swap and teardown patterns corroborated by community sources |

**Overall confidence:** HIGH

### Gaps to Address

- **`--mini` flag behavior:** Not implemented and not fully specified. During Smart Install phase planning, define exactly what `--mini` does vs wizard mode vs `--force`.
- **Workbench tests cannot run in public CI:** Flux Pro requires `auth.json` credentials that cannot be in public CI. This is an accepted constraint but means workbench test coverage is local-only. Consider whether a separate private CI job is worth the complexity.
- **Inertia middleware exact prop surface:** No official WorkOS Inertia middleware precedent found. The specific props to share (auth user, org, impersonation flag) should be validated against what the workbench frontend actually consumes before implementation.
- **`workos/workos-php` SDK version lower bound:** CI does not run `--prefer-lowest`. If a method introduced in 4.30+ is used but 4.29 is the declared minimum, this will only surface via bug report. Consider adding a prefer-lowest CI job in Phase 5.

## Sources

### Primary (HIGH confidence)
- Existing codebase: `src/`, `workbench/`, `.github/workflows/`, `composer.json` — read directly
- birdcar/actions/auto-release action.yml — read directly from author's own action repo
- Laravel official docs: Sanctum, Starter Kits, Inertia shared data

### Secondary (MEDIUM confidence)
- spatie/package-skeleton-laravel CI config — PHP matrix patterns
- laravel/workos CI config — PHP matrix patterns  
- packages.tools/testbench — testbench version compatibility table
- freek.dev: installer command patterns, multi-guard auth testing, GitHub Actions for packages

### Tertiary (LOW confidence — needs validation)
- Ryan Chandler: custom facade fakes, swap() teardown requirements — aligns with codebase but single source
- GitHub Discussion #38630: DI bypass with facade fakes — illustrates pitfall but indirect

---
*Research completed: 2026-04-06*
*Ready for roadmap: yes*
