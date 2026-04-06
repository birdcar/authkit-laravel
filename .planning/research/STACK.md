# Technology Stack: CI/CD, Example App, Documentation, and Test Utilities

**Project:** birdcar/authkit-laravel (milestone additions)
**Researched:** 2026-04-06
**Scope:** What's needed to add Smart Install, CI/CD, example app, documentation, and test utilities to the existing package. Does NOT re-cover the authentication system itself.

---

## Current Baseline (Already Exists)

| Component | Version | Status |
|-----------|---------|--------|
| PHP | ^8.3 | enforced in composer.json |
| Laravel | ^11.0\|^12.0 | confirmed |
| orchestra/testbench | ^9.0\|^10.0 | package-level tests |
| pestphp/pest | ^3.0 | package-level tests |
| phpstan/phpstan | ^2.0 | level 8, bleedingEdge.neon |
| laravel/pint | ^1.0 | code style |
| workos/workos-php | ^4.29 | core SDK dependency |
| WorkOSFake | exists | src/Testing/WorkOSFake.php |
| InteractsWithWorkOS trait | exists | src/Testing/Concerns/InteractsWithWorkOS.php |

The `WorkOS::fake()`, `WorkOS::actingAs()`, `WorkOS::restore()`, `assertAudited()`, and `assertNotAudited()` are all fully implemented in the codebase. The testing utilities milestone is primarily about exposing them properly to consumers and adding the workbench example tests, not building the infrastructure from scratch.

---

## CI/CD Stack

### GitHub Actions: Test Matrix

**Recommendation:** Single `tests.yml` workflow with PHP/Laravel matrix.

| Tool | Version | Why |
|------|---------|-----|
| `actions/checkout` | v4 | Current standard; v3 deprecated |
| `shivammathur/setup-php` | v2 | De-facto standard for PHP in GitHub Actions; handles extensions, multiple PHP versions, coverage drivers cleanly |
| `actions/cache` | v4 | Cache Composer vendor directory between runs |

**Matrix — PHP x Laravel:**

| PHP | Laravel | Testbench |
|-----|---------|-----------|
| 8.3 | ^11.0 | ^9.0 |
| 8.3 | ^12.0 | ^10.0 |
| 8.4 | ^11.0 | ^9.0 |
| 8.4 | ^12.0 | ^10.0 |

Confidence: HIGH — confirmed against spatie/package-skeleton-laravel active CI config and laravel/workos CI config.

**Do NOT add Windows matrix.** This package has no Windows-specific code paths. Ubuntu-latest only. Doubles CI time with zero diagnostic value for this package type.

**Do NOT add prefer-lowest stability variant.** The package's production dependency graph is three packages (illuminate/contracts, illuminate/support, workos/workos-php). prefer-lowest testing catches transitive breakage in large graphs; it does not apply here.

### GitHub Actions: Code Quality

Two jobs that run in parallel with the test matrix:

**Pint — code style check:**
Run `vendor/bin/pint --test` (fail on violation, do not auto-commit fixes). Auto-fixing on CI mutates the tree silently and causes confusing PR histories.

**PHPStan — static analysis:**
Run `vendor/bin/phpstan analyse src --level=8 --memory-limit=512M`. The existing `phpstan.neon` with `bleedingEdge.neon` needs no changes.

Confidence: HIGH — both commands are already defined in the root `composer.json` scripts.

### GitHub Actions: Auto-Release

**Recommendation:** `birdcar/actions/auto-release@main`

This is the author's own reusable composite action. Behavior confirmed from action.yml:
- Triggers on PR merge
- Reads CHANGELOG.md to populate release body
- Creates a tagged GitHub release
- Bumps version based on PR labels: `release.major`, `release.minor`, `release.patch`
- Skips release on labels: `release.skip`, `release.docs`, `release.dependencies`, `release.ci`
- Default bump when no label present: `patch`
- Outputs: `version`, `tag`, `release_url`, `skipped`
- Runtime: Node 20

Required input: `githubToken` — use `${{ secrets.GITHUB_TOKEN }}` with `contents: write` permission declared in the workflow.

Workflow trigger: `pull_request` event `types: [closed]`, gated on `github.event.pull_request.merged == true`.

Confidence: MEDIUM — sourced directly from action.yml; behavior matches PROJECT.md description of "automated release workflow using birdcar/actions/auto-release".

---

## Workbench / Example App Stack

The workbench app already exists at `workbench/` with the full stack. Completing the example app is a content and feature task, not a stack decision.

| Technology | Version | Role | Status |
|------------|---------|------|--------|
| Laravel Framework | ^12.0 | App framework | exists |
| Livewire | ^4.0 | Reactive components | exists |
| Flux / Flux Pro | ^2.11 | UI component system | exists |
| Tailwind CSS | ^4.1.18 | Styling | exists |
| Vite | ^7.0.7 | Frontend build | exists |
| Pest | ^4.0 | Workbench test suite | exists |
| pestphp/pest-plugin-laravel | ^4.0 | Laravel testing helpers | exists |
| SQLite | file-based | Dev database | exists |
| Concurrently | ^9.0.1 | Dev server orchestration | exists |

No new frontend dependencies needed. The Flux Pro + Livewire + Tailwind CSS 4 stack is installed and functional.

**Known inconsistency to fix:** `workbench/composer.json` currently specifies `php: ^8.2` but the package requires `^8.3`. Fix to `^8.3` when updating the workbench.

Confidence: HIGH — read directly from workbench/composer.json.

### Workbench Test Utilities

Workbench tests should use the `InteractsWithWorkOS` trait already shipped in the package:

```php
use WorkOS\AuthKit\Testing\Concerns\InteractsWithWorkOS;

uses(InteractsWithWorkOS::class);

beforeEach(function () {
    $this->actingAsWorkOS($user, roles: ['admin']);
});
```

No additional testing libraries needed. `pestphp/pest-plugin-laravel` ^4.0 already in workbench provides `actingAs()`, `assertStatus()`, `assertRedirect()`, etc.

Confidence: HIGH — code is implemented and wired correctly in the package.

---

## Documentation Stack

**Recommendation:** Plain Markdown in `.github/README.md`

No documentation site generator (VitePress, Docusaurus) is warranted. The standard for Laravel packages is a well-structured single README. Consumers find it via Packagist and GitHub.

Structure:
- `.github/README.md` — primary README (GitHub auto-discovers this over root README)
- `CHANGELOG.md` — already exists, populated by the auto-release action

**Do NOT add VitePress or Docusaurus.** The overhead of a separate docs site is not justified until adoption reaches the level where versioned docs are necessary. The workbench app serves as living documentation of all features.

Confidence: HIGH — standard Laravel package convention (confirmed by spatie, laravel/workos, and similar packages).

---

## Smart Install Stack

Smart Install is a PHP-only Artisan command extension. No new dependencies are needed.

Detection logic uses `Composer\InstalledVersions`:

| Check | Method |
|-------|--------|
| Detect `laravel/workos` | `\Composer\InstalledVersions::isInstalled('laravel/workos')` |
| Detect Breeze | `\Composer\InstalledVersions::isInstalled('laravel/breeze')` |
| Detect Jetstream | `\Composer\InstalledVersions::isInstalled('laravel/jetstream')` |
| Detect Fortify | `\Composer\InstalledVersions::isInstalled('laravel/fortify')` |
| Config migration | `Illuminate\Filesystem\Filesystem` for file read/write |

`Composer\InstalledVersions` is available in all Composer 2.x projects. It runs synchronously with no subprocess, no PATH dependency, and no shell injection surface. It is the correct approach over `shell_exec` or running a composer process.

The existing `workos:install` command uses `Illuminate\Console\Command` with `$this->choice()`, `$this->confirm()`, `$this->info()`, and `$this->error()`. The Smart Install extension continues this exact pattern.

Confidence: HIGH — `Composer\InstalledVersions` is stable Composer 2 API; command patterns already established in codebase.

---

## ShareWorkOSData Middleware (Inertia)

This is a new optional middleware that injects WorkOS auth data into Inertia's shared page props.

| Dependency | Already Present? | Note |
|------------|-----------------|------|
| `inertiajs/inertia-laravel` | NOT a package dep | Optional/conditional |
| `Inertia\Inertia` facade | Available if user has Inertia | Guard with `class_exists` |

**Recommendation:** Implement as a standalone middleware that soft-detects Inertia:

```php
if (class_exists(\Inertia\Inertia::class)) {
    \Inertia\Inertia::share(['workos' => [...]]);
}
```

Do NOT add `inertiajs/inertia-laravel` as a hard package dependency. It must be a soft/optional integration. Document it as: "add this middleware in `HandleInertiaRequests` if your app uses Inertia".

Confidence: MEDIUM — consistent with how other Laravel packages (Ziggy, etc.) handle optional Inertia integration; no official WorkOS Inertia middleware precedent found to confirm exact API surface.

---

## Alternatives Considered

| Category | Recommended | Alternative | Why Not |
|----------|-------------|-------------|---------|
| PHP CI setup | shivammathur/setup-php v2 | setup-php v1, default PHP | v1 deprecated; default PHP lacks reliable extension control |
| Test matrix OS | ubuntu-latest only | + windows-latest | No Windows-specific code; doubles CI cost |
| Test matrix stability | prefer-stable only | + prefer-lowest | Thin dep tree; lowest stability adds no diagnostic value |
| Documentation | README.md only | VitePress / Docusaurus | Premature for a single package; adds maintenance burden |
| Package detection | Composer\InstalledVersions | Running a composer subprocess | Subprocess adds latency, PATH dependency, injection surface |
| Inertia integration | soft dependency (class_exists) | Hard require inertiajs/inertia-laravel | Forces Inertia on all users of the package |
| Workbench database | SQLite (existing) | MySQL / Postgres | SQLite is zero-config, adequate for dev and CI |

---

## Sources

- spatie/package-skeleton-laravel run-tests.yml: PHP 8.3/8.4 x Laravel 12/13 x Testbench 10/11 (fetched 2026-04-06)
- laravel/workos tests.yml: PHP 8.2-8.5 x Laravel 11-13 matrix (fetched 2026-04-06)
- birdcar/actions auto-release action.yml: label-driven semver, CHANGELOG.md integration, Node 20 (fetched 2026-04-06)
- packages.tools/testbench: Laravel 11 = Testbench 9.x, Laravel 12 = Testbench 10.x, Laravel 13 = Testbench 11.x
- Packagist orchestra/testbench: v11.0.0 targets Laravel ^13.0.0 (released 2026-03-16)
- pestphp/pest-plugin-laravel 4.x: requires pest ^4.4.1, supports testbench ^9.13|^10.9|^11.0
- ryangjchandler.co.uk — custom facade fakes pattern (MEDIUM confidence, consistent with existing WorkOSFake implementation)
- Existing codebase: WorkOSFake, InteractsWithWorkOS, WorkOS::fake/actingAs/restore all confirmed present and correct
