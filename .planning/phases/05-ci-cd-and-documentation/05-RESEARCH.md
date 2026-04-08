# Phase 5: CI/CD and Documentation - Research

**Researched:** 2026-04-07
**Domain:** GitHub Actions CI/CD, README documentation, WorkOSFake API documentation
**Confidence:** HIGH

## Summary

Phase 5 is primarily a documentation accuracy and gap-closure phase. The CI/CD infrastructure is fully functional and complete: `ci.yml` runs a 4-combination matrix (PHP 8.3/8.4 x Laravel 11/12) with tests, PHPStan level 8, and Pint on every PR and push to main. `release.yml` handles label-driven semver releases via `birdcar/actions/auto-release`. The CI badge is already in the README header.

The README (`/.github/README.md`) has comprehensive coverage of installation, configuration, middleware, events, commands, and usage patterns. The primary problem is a factually wrong "Faking WorkOS" section that shows Mockery's `shouldReceive()` pattern — which does not work with `WorkOS::fake()`. The real `WorkOSFake` API is a stateful in-memory fake that supports fluent builder methods and PHPUnit assertions. The existing `WorkOSFakeExampleTest.php` in the workbench is a verified source of truth for correct usage patterns.

Secondary work is expanding the workbench example app description in the README and cross-checking the Contributing section's commands against actual `composer.json` scripts.

**Primary recommendation:** Fix the "Faking WorkOS" section to use the real `WorkOSFake` API, expand the testing docs to cover the full assertion surface, and update the workbench section to enumerate the features it demonstrates.

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions
- **D-01:** CI workflow (ci.yml) already satisfies CICD-01 and CICD-02 — PHP 8.3/8.4 x Laravel 11/12 matrix with tests, PHPStan, Pint
- **D-02:** Release workflow (release.yml) already satisfies CICD-03 — uses birdcar/actions/auto-release with label-driven semver
- **D-03:** CI badge already in README (line 3 of .github/README.md) — satisfies CICD-04
- **D-04:** Fix the "Faking WorkOS" section — currently shows Mockery-style `shouldReceive()` which is wrong. Replace with actual `WorkOSFake` API: `fake()`, `actingAs()`, `assertAudited()`, `withRoles()`, `withPermissions()`, `inOrganization()`, `restore()`
- **D-05:** Expand testing documentation to cover the full `WorkOS::fake()` API surface including `destroySession()`, `assertNotAudited()`, `assertAuditedCount()`, `clearAuditedEvents()`, and the `InteractsWithWorkOS` trait
- **D-06:** Expand workbench example app section to explain what features it demonstrates (auth, todos, org switching, RBAC, audit logging, admin portal)

### Claude's Discretion
- CI hardening details (e.g., whether to add workbench tests to CI, coverage reporting)
- README section ordering and flow improvements
- CHANGELOG formatting for initial release
- Any additional contributing guide details

### Deferred Ideas (OUT OF SCOPE)
None — discussion stayed within phase scope
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| CICD-01 | GitHub Actions CI workflow runs tests, PHPStan, Pint on all PRs | ci.yml verified complete — runs `composer test`, `composer analyse`, `composer format:test` |
| CICD-02 | CI matrix covers PHP 8.3+8.4 x Laravel 11+12 | ci.yml matrix `php: ['8.3', '8.4']` x `laravel: ['11.*', '12.*']` verified |
| CICD-03 | Automated release workflow using birdcar/actions/auto-release (label-driven) | release.yml verified — uses `birdcar/actions/auto-release@main` with semver labels |
| CICD-04 | CI badge visible in README | Badge on line 3 of .github/README.md verified |
| DOCS-01 | Comprehensive README in .github/README.md | File exists with 543 lines; gaps are in testing section accuracy only |
| DOCS-02 | Installation and configuration guide (< 5 minutes) | Install section covers wizard, --mini, --force, migration from Breeze/Jetstream/laravel-workos |
| DOCS-03 | Feature documentation with code examples | All features documented; testing section has wrong code examples that must be fixed |
| DOCS-04 | Contributing section with local development instructions | Section exists; composer script references need cross-check against composer.json |
</phase_requirements>

## Standard Stack

### Core
| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| GitHub Actions | — | CI/CD automation | Native to GitHub; already in use |
| shivammathur/setup-php | v2 | PHP version setup in CI | Standard for PHP packages; already in ci.yml |
| actions/checkout | v4 | Repo checkout in CI | Current standard action version |
| actions/cache | v4 | Composer dependency caching | Speeds up matrix builds |
| birdcar/actions/auto-release | main | Label-driven semver releases | Already in release.yml; project standard |

### No changes to CI stack required
All CI/CD tooling is already in place. [VERIFIED: .github/workflows/ci.yml, .github/workflows/release.yml]

## Architecture Patterns

### CI Workflow Structure
The existing ci.yml uses three separate jobs (not a single monolithic job):
- `tests` — matrix job across PHP/Laravel versions
- `static-analysis` — single PHP 8.3 run of PHPStan level 8
- `code-style` — single PHP 8.3 run of Pint `--test`

This is intentional: PHPStan and Pint don't need to run across every matrix combination since they are not version-sensitive. [VERIFIED: .github/workflows/ci.yml]

### Release Workflow Trigger
The release workflow triggers on `push` to `main` (not on PR), which is correct for label-driven release tooling. The `auto-release` action inspects PR labels on the merged PR. [VERIFIED: .github/workflows/release.yml]

### WorkOSFake API Pattern
The `WorkOS::fake()` method replaces the container binding (`app()->instance('workos', self::$fake)`) and returns a `WorkOSFake` instance. It is NOT Mockery-based — it is a stateful, in-memory fake object. [VERIFIED: src/WorkOS.php:181-187, src/Testing/WorkOSFake.php]

Three usage patterns exist:
1. Direct `WorkOS::fake()` + manual `WorkOS::restore()` in `afterEach`
2. `WorkOS::actingAs($user, roles: [...])` shortcut (calls `fake()` + `actingAs()` in one step)
3. `InteractsWithWorkOS` trait — provides `actingAsWorkOS()` and `fakeWorkOS()` with auto-teardown via `tearDownInteractsWithWorkOS()`

[VERIFIED: workbench/tests/Feature/WorkOSFakeExampleTest.php, src/Testing/Concerns/InteractsWithWorkOS.php]

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| PHP version matrix CI | Custom Docker images | shivammathur/setup-php@v2 | Already handles all PHP extensions, versions, coverage drivers |
| Semver release automation | Custom GitHub Actions | birdcar/actions/auto-release | Already configured and working |
| WorkOS test doubles | Mockery mocks | WorkOS::fake() / WorkOSFake | Real fake exists with full assertion API |

## WorkOSFake Full API Surface

The complete public API of `WorkOSFake`, verified against `src/Testing/WorkOSFake.php`:

**Setup / Builder methods (all return `static` for chaining):**
- `actingAs(Authenticatable $user, array $roles = [], array $permissions = [], ?string $organizationId = null): static`
- `withRoles(array $roles): static` — merges roles into existing set
- `withPermissions(array $permissions): static` — merges permissions into existing set
- `inOrganization(string $organizationId): static`
- `impersonating(array $impersonator): static`

**State mutation:**
- `destroySession(): void` — clears user, roles, permissions, organizationId, impersonator
- `clearAuditedEvents(): void` — resets the captured audit log
- `audit(string $action, array $targets = [], array $metadata = []): void` — manually record an audit event

**Inspection:**
- `user(): ?Authenticatable`
- `session(): ?WorkOSSession`
- `validSession(): ?WorkOSSession`
- `hasRole(string $role): bool`
- `hasPermission(string $permission): bool`
- `isAuthenticated(): bool`
- `isImpersonating(): bool`
- `organizationId(): ?string`
- `getLogoutUrl(?string $returnTo = null): ?string`
- `getAuditedEvents(): array`

**Assertions (all return `static` for chaining):**
- `assertAuthenticated(): static`
- `assertGuest(): static`
- `assertHasRole(string $role): static`
- `assertHasPermission(string $permission): static`
- `assertInOrganization(string $orgId): static`
- `assertAudited(string $action, ?callable $callback = null): static`
- `assertNotAudited(string $action): static`
- `assertAuditedCount(int $count): static`

**Facade-level static methods (on `WorkOS` class, not `WorkOSFake`):**
- `WorkOS::fake(): WorkOSFake` — activates fake and returns it
- `WorkOS::actingAs(...): WorkOSFake` — activates fake + authenticates user in one call
- `WorkOS::restore(): void` — tears down fake, restores real service
- `WorkOS::isFaked(): bool` — check if fake is active

**InteractsWithWorkOS trait methods:**
- `actingAsWorkOS(Authenticatable $user, ...): WorkOSFake`
- `fakeWorkOS(): WorkOSFake`
- Auto-teardown via `tearDownInteractsWithWorkOS()` (called by Laravel test lifecycle)

[VERIFIED: src/Testing/WorkOSFake.php, src/WorkOS.php:181-211, src/Testing/Concerns/InteractsWithWorkOS.php]

## Common Pitfalls

### Pitfall 1: Mockery Pattern in "Faking WorkOS" Section
**What goes wrong:** The current README shows `$fake->shouldReceive('userManagement->authenticateWithCode')` which is Mockery syntax. `WorkOSFake` does not extend Mockery and has no `shouldReceive()` method. Anyone copying this code will get a fatal error.
**Why it happens:** The section was likely written before `WorkOSFake` was implemented, based on anticipated API.
**How to avoid:** Replace entire "Faking WorkOS" section with content drawn from `WorkOSFakeExampleTest.php`.
**Warning signs:** The `shouldReceive` and `andThrow` keywords appearing in the WorkOSFake context.

### Pitfall 2: README composer script references
**What goes wrong:** The Contributing section references `composer test:example` for running workbench tests. This script exists in `composer.json`. However, the section does NOT reference `composer fresh` or `composer serve` with their correct paths.
**Why it happens:** Minor inconsistency between documented workflow and actual scripts.
**How to avoid:** Cross-check all `composer *` commands in README against actual `composer.json` scripts before finalizing.
**Verified scripts:** `test`, `test:coverage`, `analyse`, `format`, `format:test`, `serve`, `fresh`, `test:example` [VERIFIED: composer.json:50-65]

### Pitfall 3: InteractsWithWorkOS import path
**What goes wrong:** Developers might guess the trait is in `WorkOS\AuthKit\Support\` based on the file at `src/Support/InteractsWithWorkOS.php` referenced in some early CONTEXT references. The actual location is `src/Testing/Concerns/InteractsWithWorkOS.php`.
**Why it happens:** Path discrepancy between an earlier scout report (referenced `src/Support/`) and the actual file.
**How to avoid:** Use the verified namespace `WorkOS\AuthKit\Testing\Concerns\InteractsWithWorkOS`. [VERIFIED: src/Testing/Concerns/InteractsWithWorkOS.php:5]

### Pitfall 4: fake() requires WorkOS::restore() or trait teardown
**What goes wrong:** If `WorkOS::restore()` is not called after a test, the fake persists across subsequent tests in the same process. This causes unrelated tests to behave as if WorkOS is faked.
**Why it happens:** `WorkOS::fake()` sets a static property (`self::$fake`) and swaps the container binding. Neither is automatically undone by Laravel's test teardown unless the `InteractsWithWorkOS` trait is used.
**How to avoid:** Always pair `WorkOS::fake()` with `->afterEach(fn () => WorkOS::restore())` or use the `InteractsWithWorkOS` trait.

## Code Examples

Verified patterns from `workbench/tests/Feature/WorkOSFakeExampleTest.php`:

### Pattern 1: Direct fake with manual restore
```php
// Source: workbench/tests/Feature/WorkOSFakeExampleTest.php
it('can authenticate as a user', function () {
    $user = User::factory()->create();
    $fake = WorkOS::fake();

    $fake->actingAs($user);

    $this->get('/dashboard')->assertOk();
})->afterEach(fn () => WorkOS::restore());
```

### Pattern 2: Shortcut via WorkOS::actingAs()
```php
// Source: .github/README.md (existing correct section)
test('authenticated user can view dashboard', function () {
    $user = User::factory()->create();

    WorkOS::actingAs($user, roles: ['admin'], permissions: ['posts:write']);

    $this->get('/dashboard')->assertOk();
});
```
Note: `WorkOS::actingAs()` implicitly calls `fake()`. Always pair with restore.

### Pattern 3: Incremental builder
```php
// Source: workbench/tests/Feature/WorkOSFakeExampleTest.php
$fake = WorkOS::fake()
    ->actingAs($user, roles: ['member'], permissions: ['todos.read'])
    ->withRoles(['admin'])
    ->withPermissions(['todos.write'])
    ->inOrganization('org_xyz');
```

### Pattern 4: InteractsWithWorkOS trait
```php
// Source: workbench/tests/Feature/WorkOSFakeExampleTest.php
describe('using InteractsWithWorkOS trait', function () {
    uses(InteractsWithWorkOS::class);

    it('authenticates via actingAsWorkOS', function () {
        $user = User::factory()->create();
        $fake = $this->actingAsWorkOS($user, roles: ['editor']);

        $this->get('/dashboard')->assertOk();
        $fake->assertHasRole('editor');
    });
});
// No afterEach needed — trait handles teardown automatically
```

### Pattern 5: Audit assertions
```php
// Source: workbench/tests/Feature/WorkOSFakeExampleTest.php
it('captures audit events', function () {
    $fake = WorkOS::fake()->actingAs($user);

    $fake->audit('todo.created', metadata: ['title' => 'My Task']);

    $fake->assertAudited('todo.created');
    $fake->assertNotAudited('todo.deleted');
    $fake->assertAuditedCount(1);
})->afterEach(fn () => WorkOS::restore());
```

## Current README Accuracy Audit

[VERIFIED: .github/README.md]

| Section | Accurate? | Issue |
|---------|-----------|-------|
| CI badge (line 3) | Yes | None |
| Features list | Yes | Minor: lists `WorkOS::actingAs()` as a feature bullet; could be more specific |
| Requirements | Yes | None |
| Installation | Yes | None |
| Configuration | Yes | None |
| Authentication Routes | Yes | None |
| Protecting Routes | Yes | None |
| Organizations | Yes | None |
| Roles and Permissions | Yes | None |
| Audit Logging | Yes | None |
| Admin Portal | Yes | None |
| Webhooks | Yes | None |
| Impersonation | Yes | None |
| Testing > WorkOS::actingAs() | Yes | Correct usage; no Mockery |
| **Testing > Faking WorkOS** | **NO** | Uses `shouldReceive()` / `andThrow()` — Mockery pattern, not WorkOSFake API |
| Middleware table | Yes | None |
| Blade Directives | Yes | None |
| Events | Yes | None |
| Artisan Commands | Yes | None |
| Example Application | Partial | Describes "all package features" vaguely; D-06 requires listing specific features |
| Contributing | Yes | Commands match composer.json scripts |

## CHANGELOG Gap

`CHANGELOG.md` has only an `[Unreleased]` section with a brief "Added" list. It correctly uses Keep a Changelog format. No structural changes needed; content is appropriate for a pre-1.0 release. [VERIFIED: CHANGELOG.md]

## CI Hardening Options (Claude's Discretion)

These are options the planner may include at discretion:

1. **Workbench tests in CI:** Currently not run in CI (blocked by Flux Pro requiring paid credentials — explicitly out of scope per REQUIREMENTS.md). No change recommended.
2. **Coverage reporting:** `test:coverage` script exists but `coverage: none` is set in ci.yml. Adding coverage would require a coverage driver (pcov/xdebug) and a coverage threshold service (Coveralls, Codecov). Given this is pre-1.0, this is low priority — recommend leaving as-is.
3. **Dependabot:** Could add `.github/dependabot.yml` for automatic dependency update PRs. Low effort, good practice for a public package.

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | Pest 3.x (main package), Pest 4.x (workbench) |
| Config file | `tests/Pest.php` |
| Quick run command | `composer test` |
| Full suite command | `composer test` |

### Phase Requirements → Test Map
| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| CICD-01 | CI runs on PR | manual/smoke | Push a test PR | N/A — workflow validation |
| CICD-02 | Matrix covers 4 combinations | manual/smoke | Push a test PR | N/A — workflow validation |
| CICD-03 | Release on label | manual/smoke | Apply label to merged PR | N/A — workflow validation |
| CICD-04 | Badge in README | manual | Visual inspection | N/A |
| DOCS-01 | README exists and is comprehensive | manual | Read .github/README.md | ✅ |
| DOCS-02 | Install guide complete | manual | Follow instructions | ✅ |
| DOCS-03 | Feature docs with correct code examples | manual | Review testing section | ✅ (after fix) |
| DOCS-04 | Contributing section accurate | manual | Verify composer commands | ✅ |

This phase has no automated test requirements — all verification is manual inspection and workflow validation.

### Wave 0 Gaps
None — this phase modifies only documentation files, not source code. No test infrastructure changes needed.

## Security Domain

This phase modifies only documentation and workflow files. No new code is introduced. No ASVS categories apply.

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | `test:example` composer script correctly runs workbench Pest tests | Contributing section | Low — script content is `cd workbench && ./vendor/bin/pest`, straightforward |

## Open Questions

1. **Should `WorkOS::isFaked()` be documented in the README?**
   - What we know: The method exists on the `WorkOS` class (not `WorkOSFake`) and returns bool
   - What's unclear: Whether application code would ever use this vs test code
   - Recommendation: Include in the API reference table but don't add a dedicated example; it's a utility method

2. **Should `impersonating()` be documented in the testing section?**
   - What we know: `WorkOSFake::impersonating(array $impersonator)` exists and is part of the public API
   - What's unclear: Whether anyone needs to test impersonation scenarios in their app tests
   - Recommendation: Include in the API surface table but mark as advanced/optional

## Sources

### Primary (HIGH confidence)
- `src/Testing/WorkOSFake.php` — Full WorkOSFake API (all public methods)
- `src/WorkOS.php:181-211` — `fake()`, `actingAs()`, `restore()`, `isFaked()` static methods
- `src/Testing/Concerns/InteractsWithWorkOS.php` — Trait API
- `.github/workflows/ci.yml` — CI matrix and job structure
- `.github/workflows/release.yml` — Release workflow configuration
- `.github/README.md` — Current documentation state
- `workbench/tests/Feature/WorkOSFakeExampleTest.php` — Verified usage patterns
- `composer.json:50-65` — Actual composer scripts

### Secondary (MEDIUM confidence)
- CONTEXT.md D-04, D-05, D-06 — User-confirmed problem areas

### Tertiary (LOW confidence)
None.

## Metadata

**Confidence breakdown:**
- CI/CD status: HIGH — workflows read directly from source
- README accuracy audit: HIGH — each section cross-checked against source code
- WorkOSFake API surface: HIGH — read directly from implementation file
- Contributing commands: HIGH — cross-checked against composer.json

**Research date:** 2026-04-07
**Valid until:** 2026-05-07 (stable — no external dependencies)
