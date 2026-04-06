# Domain Pitfalls

**Domain:** Laravel package — Smart Install command, CI/CD, Workbench example app, test utilities (WorkOSFake)
**Researched:** 2026-04-06
**Confidence:** HIGH (most findings grounded in existing codebase inspection + verified patterns)

---

## Critical Pitfalls

### Pitfall 1: auth.json With Real Credentials Committed to Git

**What goes wrong:** `workbench/auth.json` contains a live Flux Pro license token (username + password). The file is not listed in `.gitignore`. If it is ever committed — or if the gitignore entry is missing from `workbench/.gitignore` — real credentials are exposed in git history permanently.

**Why it happens:** Flux Pro requires an auth.json for composer to resolve `composer.fluxui.dev`. Developers add it locally, assume it is ignored, and never verify. The root `.gitignore` does not cover `workbench/auth.json`.

**Consequences:** Exposed API credentials. Flux Pro license revocation risk. Scraper bots harvest GitHub credentials within hours of exposure.

**Prevention:**
- Add `auth.json` to `workbench/.gitignore` (create if missing) before any commit touching the workbench
- Add `workbench/auth.json` to root `.gitignore` as belt-and-suspenders
- Add a pre-commit check or CI lint step that fails if `auth.json` contains non-placeholder values
- Commit only `auth.json.example` with placeholder values documenting the required format

**Detection:** Run `git ls-files workbench/auth.json` — if it returns a path, credentials are tracked.

**Phase:** Workbench / Example App milestone. Resolve before first commit touching workbench.

---

### Pitfall 2: Regex-Based File Manipulation That Silently Fails or Corrupts

**What goes wrong:** `AuthSystemInstaller` and `LaravelWorkosMigrator` use `preg_replace()` on `auth.php`, `User.php`, and `services.php`. If patterns don't match (unexpected whitespace, PHP 8.3 readonly property syntax, class-in-enum edge cases), `preg_replace` returns `null` or the original string unchanged with no error — the user sees "success" but nothing changed.

**Why it happens:** Regex against PHP source files cannot account for all formatting variants. The existing code does check `$result !== null && $result !== $contents`, but only to skip the write — it still reports success to the user in most paths.

**Consequences:**
- `auth.php` not updated, guard missing at runtime → cryptic "Guard [workos] is not defined" exception
- `User.php` traits not injected → `method_exists` fallback chain used silently
- `services.php` WorkOS key not removed, duplicate config confusion

**Prevention:**
- After every regex write, verify the expected string now exists in the file and emit a warning if not: `str_contains(File::get($path), "'workos'")` 
- For `auth.php` specifically, always fall through to displaying manual instructions when auto-modification fails
- Consider using `nikic/php-parser` for structural PHP modifications instead of regex (MEDIUM confidence — adds a dev dependency; weigh against complexity)
- The current partial approach of checking `$updated !== $contents` is good but must be paired with a failure message path

**Detection:** After `workos:install`, verify `config/auth.php` contains `'workos'` guard before reporting success.

**Phase:** Smart Install milestone.

---

### Pitfall 3: Install Command Is Not Idempotent Without `--force`

**What goes wrong:** Running `workos:install` twice in the same project produces inconsistent state. Config publishing skips without `--force`, but `AuthSystemInstaller::updateAuthConfig()` appends the guard block again if the str_contains check misses. Trait injection in `User.php` is checked via `str_contains('HasWorkOSId')` but the import check regex (`addTraitImports`) could add a duplicate import if string detection differs from regex result.

**Why it happens:** The guard-already-exists check (`str_contains($contents, "'workos'")`) and the import-already-exists check (`str_contains($contents, 'HasWorkOSId')`) are good but the write path has two independent guards that could diverge.

**Consequences:** Duplicate `use HasWorkOSId;` import lines causing PHP parse errors in the user's app. Duplicate auth guard blocks causing Laravel config exception.

**Prevention:**
- Add an integration test that runs `workos:install` twice against the same fixture files and asserts the output files are identical to the first run
- Confirm the `str_contains` guard and the regex guard are checking the same string (they currently do, but a test locks this in)

**Detection:** Warning sign is any test that only runs the install once — the second-run case is not currently tested.

**Phase:** Smart Install milestone.

---

### Pitfall 4: WorkOSFake Not Bound via Facade::swap() — Test Isolation Breaks

**What goes wrong:** `WorkOSFake` exists and is well-implemented, but if it is not registered through the service container swap (i.e., `WorkOS::fake()` calls `WorkOS::swap(new WorkOSFake())` rather than just returning an instance), then code that resolves `WorkOS` via dependency injection instead of the facade will not receive the fake. The current `WorkOSFake` is instantiated directly in tests — it is not clear that `WorkOS::fake()` is a static factory method that also performs the swap.

**Why it happens:** Developers create the fake object but forget that `Facade::swap()` must replace the container binding, not just the resolved facade instance. Type-hinted injections bypass the facade and hit the real binding.

**Consequences:** Tests pass when code uses `WorkOS::method()` but fail — or hit real WorkOS API — when code uses injected `WorkOSService`.

**Prevention:**
- Implement `WorkOS::fake()` as a static method on the `WorkOS` facade that calls `static::swap(new WorkOSFake())` and returns the fake
- Add a test that resolves `WorkOSService` via `app(WorkOSService::class)` after calling `WorkOS::fake()` and asserts it returns the fake instance
- Document in `WorkOSFake` that state is not automatically reset between tests — callers must call `WorkOS::fake()` in `beforeEach` or implement a `tearDown`

**Detection:** Tests using `WorkOSFake` directly (not via `WorkOS::fake()`) that also test routes or middleware will exercise the real guard if the container binding isn't swapped.

**Phase:** Test Utilities milestone.

---

### Pitfall 5: Workbench Tests Using `actingAs($user, 'workos')` Without a WorkOS Session

**What goes wrong:** `workbench/tests/Feature/AuthTest.php` calls `$this->actingAs($user, 'workos')` with a plain Eloquent User. The `WorkOSGuard` may attempt to read a `WorkOSSession` from the user when checking roles, permissions, or organization. If no session is attached, guards or middleware that call `$user->workosSession()` return null and may throw.

**Why it happens:** Laravel's `actingAs()` calls `Guard::setUser()` directly, bypassing session creation. Works for the simple "can they access /dashboard" case, but breaks middleware tests for `CheckRole`, `CheckPermission`, `SetCurrentOrganization`.

**Consequences:** Workbench tests pass for basic auth coverage but give false confidence — the middleware-heavy paths are untested.

**Prevention:**
- Use `WorkOS::fake()->actingAs($user, roles: [...])` for workbench tests that exercise middleware
- Add a workbench `TestCase` helper `actingAsWorkOSUser()` (pattern already exists in the package's own `TestCase`) so workbench tests don't have to set up sessions manually
- Add at least one workbench test per middleware class that verifies role/permission enforcement

**Detection:** If a workbench test uses `actingAs($user, 'workos')` without also calling `$user->setWorkOSSession(...)` or using `WorkOS::fake()`, it will miss session-dependent behavior.

**Phase:** Example App / Workbench milestone.

---

## Moderate Pitfalls

### Pitfall 6: CI Matrix Missing Composer `lowest` Dependencies Run

**What goes wrong:** The current CI matrix tests PHP 8.3/8.4 against Laravel 11/12 with default (highest) dependencies. It does not run `composer update --prefer-lowest`, which would catch cases where the package declares `^4.29` on `workos/workos-php` but actually uses methods introduced in 4.30+.

**Prevention:** Add a matrix entry with `dependency-version: lowest` and run `composer update --prefer-lowest --prefer-stable` in that job. This is the standard Spatie/Laravel package CI pattern.

**Detection:** A bug report from a user on an older SDK minor version is the typical first indicator.

**Phase:** CI/CD milestone.

---

### Pitfall 7: Release Workflow Triggering on Every Main Push Creates Tag Spam

**What goes wrong:** `release.yml` runs on every push to `main` using `birdcar/actions/auto-release@main`. If commits that should not trigger a release (documentation changes, CI fixes, refactors) don't carry `skip-release` or `no-release` labels, a patch tag is created for every merge. Packagist ingests every tag as a new version.

**Prevention:**
- Establish a commit/PR label convention now and document it in CONTRIBUTING
- The `skipLabels` field is already configured (`skip-release,no-release`) — enforce these labels in PR template
- Prefer branch-based release gating: only trigger `release.yml` on pushes to a `release/*` branch or on tag pushes, not on every main merge

**Detection:** Check the release history on Packagist after a month — if there are 20 patch versions for minor CI tweaks, the labeling discipline is broken.

**Phase:** CI/CD milestone.

---

### Pitfall 8: Workbench composer.json Pins `php: ^8.2` While Package Requires `^8.3`

**What goes wrong:** `workbench/composer.json` has `"php": "^8.2"` but the parent package requires `^8.3`. This means the workbench will accept PHP 8.2 locally, potentially masking use of PHP 8.3-only syntax in workbench app code. A contributor on PHP 8.2 could successfully run the workbench and submit code that uses 8.2-compatible patterns, but the CI (which tests the package at 8.3+) might not catch regressions introduced from the other direction.

**Prevention:** Align `workbench/composer.json` `php` constraint to `^8.3` to match the package.

**Detection:** Run `php -v` in the workbench and compare against root `composer.json`.

**Phase:** Example App / Workbench milestone.

---

### Pitfall 9: `EnvManager::applyChanges()` Appends to `.env` Without Updating `.env.example`

**What goes wrong:** The env manager adds `WORKOS_*` keys to `.env` correctly, but never touches `.env.example`. Future contributors cloning the repo will not know which variables are required. This is a documentation/UX gap that leads to "why doesn't this work on a fresh clone?" support questions.

**Prevention:** After writing to `.env`, also append the same keys (with placeholder values) to `.env.example` if they are not already present. This mirrors what Laravel Breeze/Jetstream do.

**Detection:** After running `workos:install`, diff `.env` and `.env.example` — any `WORKOS_*` keys present in one but not the other is the signal.

**Phase:** Smart Install milestone.

---

### Pitfall 10: WorkOSFake State Not Reset Between Tests Causes Assertion Bleed

**What goes wrong:** `WorkOSFake` accumulates `$auditedEvents` across the lifetime of the fake instance. If the fake is instantiated once in a test suite's `beforeEach` but `clearAuditedEvents()` is not called, audit assertions from test N pollute test N+1.

**Prevention:**
- Call `WorkOS::fake()` in `beforeEach` (creates a fresh instance each test) rather than creating a single fake in a `beforeAll`
- Document this behavior in the `WorkOSFake` docblock
- Add a test that verifies `assertNotAudited` passes on a fresh fake even when the previous test called `audit()`

**Detection:** Flaky `assertNotAudited` failures that pass when run in isolation but fail when run in suite order.

**Phase:** Test Utilities milestone.

---

## Minor Pitfalls

### Pitfall 11: Install Command Tests Require `serial` Group — Not Enforced in CI

**What goes wrong:** `InstallCommandTest.php` has `uses()->group('serial')` to prevent parallel test conflicts when writing to shared paths (`config_path`, `app_path`). If Pest is run with `--parallel` without a `--group` exclusion for `serial`, tests will race and fail intermittently.

**Prevention:** Add `--exclude-group serial` to the parallel test script, or configure `phpunit.xml` to always run `serial` group sequentially. Document the group in the test suite README.

**Phase:** CI/CD milestone.

---

### Pitfall 12: `AuthSystemInstaller::updateOrganizationModel()` Writes Stub With Hardcoded `User::class` Reference

**What goes wrong:** The generated `Organization.php` stub includes `$this->belongsToMany(User::class, 'organization_memberships')` with a hardcoded reference to `User`. If the user's app uses a custom user model class (common in multi-tenant apps), the generated model has an invalid reference.

**Prevention:** Resolve the user model class from `config('auth.providers.users.model', 'App\\Models\\User')` when generating the stub, or add a comment flagging the hardcoded reference for review.

**Phase:** Smart Install milestone.

---

### Pitfall 13: Static Analysis Not Run Against Workbench in CI

**What goes wrong:** PHPStan runs only against `src/` in CI (`composer analyse`). The workbench app has its own PHP code (models, controllers, Livewire components) that is never analyzed. Type errors in workbench code are only discovered when running the example app locally.

**Prevention:** Add a separate CI step that runs PHPStan (or Larastan) against `workbench/app/` at a reasonable level (e.g., level 5). This catches regressions in example code that would mislead users copying from it.

**Phase:** CI/CD or Example App milestone.

---

## Phase-Specific Warnings

| Phase Topic | Likely Pitfall | Mitigation |
|---|---|---|
| Smart Install — file write | Regex manipulation silent failure (#2) | Verify post-write, emit manual fallback instructions |
| Smart Install — env handling | `.env.example` not updated (#9) | Write to both files atomically |
| Smart Install — idempotency | Duplicate trait/guard injection (#3) | Add second-run integration test |
| CI/CD — matrix | No lowest-deps run (#6) | Add `--prefer-lowest` matrix entry |
| CI/CD — releases | Tag spam on main (#7) | Add skip label discipline to PR template |
| CI/CD — parallel tests | Serial group not enforced (#11) | Exclude serial group from parallel runs |
| Example App — credentials | auth.json in git (#1) | gitignore before first commit |
| Example App — PHP version | workbench accepts 8.2 (#8) | Align constraint to ^8.3 |
| Example App — test quality | actingAs without session (#5) | Use WorkOS::fake() in workbench tests |
| Test Utilities — isolation | Fake state bleed (#10) | Fresh fake per test, document reset |
| Test Utilities — DI bypass | Fake not swapped in container (#4) | Implement WorkOS::fake() via swap() |

---

## Sources

- Direct codebase inspection: `src/Install/AuthSystemInstaller.php`, `src/Install/EnvManager.php`, `src/Install/LaravelWorkosMigrator.php`, `src/Testing/WorkOSFake.php`, `workbench/composer.json`, `workbench/auth.json`, `.gitignore`, `tests/Feature/InstallCommandTest.php`, `.github/workflows/ci.yml`, `.github/workflows/release.yml`
- Ryan Chandler, "Creating custom Facade fakes in Laravel": swap() dual behavior and teardown requirements (MEDIUM confidence)
- GitHub Discussion #38630: Storage facade fake not swapping for performed requests — illustrates DI bypass pattern (MEDIUM confidence)
- Freek Van der Herten, "Creating installer commands for Laravel packages" (freek.dev/2333): installer fragility acknowledged, file manipulation difficulty cited (MEDIUM confidence)
- GitHub Actions conventional commits pitfalls: GITHUB_TOKEN not spawning new workflow runs (MEDIUM confidence)
