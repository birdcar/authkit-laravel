# AuthKit Laravel

## What This Is

A Laravel package (`birdcar/authkit-laravel`) that provides drop-in WorkOS AuthKit integration for Laravel applications. It wraps the WorkOS PHP SDK with Laravel-native patterns — guards, middleware, traits, facades, blade directives, and artisan commands — so developers can have working AuthKit authentication within 15 minutes.

## Core Value

Laravel developers can install this package and have production-ready WorkOS AuthKit authentication without manually wiring up guards, sessions, middleware, or authorization logic.

## Requirements

### Validated

- ✓ Custom `workos` auth guard via `Auth::extend()` — existing
- ✓ Cookie-based session management using WorkOS Halite encryption — existing
- ✓ Automatic session refresh before expiry — existing
- ✓ OAuth login/callback/logout flow via AuthController — existing
- ✓ Model traits: HasWorkOSId, HasWorkOSPermissions, HasOrganization — existing
- ✓ Role and permission middleware (CheckRole, CheckPermission) — existing
- ✓ Blade directives (@workosRole, @workosPermission, @impersonating) — existing
- ✓ WorkOS facade with SDK service proxying — existing
- ✓ `workos()` helper function — existing
- ✓ Webhook controller with signature verification and Laravel event dispatching — existing
- ✓ Webhook listeners for user/org/membership sync — existing
- ✓ Organization switching via redirect through WorkOS login — existing
- ✓ Organization invitation management — existing
- ✓ Impersonation detection and context — existing
- ✓ AuditLogger wrapping WorkOS Audit Logs API — existing
- ✓ AuditMiddleware for automatic route logging — existing
- ✓ `workos:install` artisan command with interactive wizard — existing
- ✓ `workos:sync-users` command for bulk user backfill — existing
- ✓ Publishable config and migrations — existing
- ✓ Boot-time config validation for API key and client ID — existing
- ✓ Configurable session access token lifetime — existing
- ✓ PHPStan level 8 static analysis — existing
- ✓ Pest test suite with 295 tests — existing

### Active

- [ ] Smart Install: Detect existing auth setups (laravel/workos, Breeze, Jetstream, Fortify) and adapt installation flow
- [ ] Smart Install: Three modes — `--force` (overwrite), wizard (default), `--mini` (config + docs link)
- [ ] Smart Install: Config migration from `services.php` to `workos.php` when laravel/workos detected
- [ ] Smart Install: Migration assistant with actionable guidance for existing auth systems
- [ ] CI/CD: GitHub Actions workflow for tests, PHPStan, Pint on PRs
- [ ] CI/CD: Automated release workflow using birdcar/actions/auto-release
- [ ] Example App: Full Laravel 12 Todo app in workbench/ demonstrating all features
- [ ] Example App: Livewire + Flux Pro UI with Tailwind CSS
- [ ] Example App: All Admin Portal intents (SSO, Directory Sync, Audit Logs, Log Streams, Domain Verification)
- [ ] Example App: Basic feature test suite demonstrating testing with the package
- [ ] Documentation: Comprehensive README in .github/README.md
- [ ] Documentation: Installation guide, feature docs, code examples, contributing guidelines
- [ ] WorkOS::fake() and WorkOS::actingAs() test utilities
- [ ] Test assertions (assertAudited, assertNotAudited)
- [ ] ShareWorkOSData middleware for Inertia.js apps

### Out of Scope

- UI components — Package is headless; WorkOS hosts auth UI externally
- Directory Sync webhook handling — WorkOS recommends Events API
- MFA/Passwordless flows — Focus on AuthKit OAuth flow only
- Custom SSO configuration UI — Handled in WorkOS Dashboard
- Mobile app — Web/server-side only
- Vue/React component library — Separate package if needed
- Multi-tenancy database separation — Beyond org context
- GraphQL integration — REST-only for now
- Deployment configurations — Users handle their own hosting

## Context

- This is a fork/rewrite of the official `workos/authkit-laravel` package, aimed at being more feature-complete and Laravel-native
- The workbench/ directory contains a full Laravel 12 example app (Todo app) using Livewire + Flux Pro
- WorkOS PHP SDK v4.29+ is the core dependency — the package delegates to it for crypto, API calls, and session management
- Laravel 12 introduced WorkOS as a first-class auth option during `laravel new`, creating the `laravel/workos` package that the Smart Install must handle
- PHP 8.3+ is required — modern features (readonly, enums, match, named args) are used throughout
- Recent refactoring: consolidated to WorkOS cookie session, modernized with PHP 8.3 features, dropped Laravel 10/PHP 8.2

## Constraints

- **PHP version**: ^8.3 — enforced via composer.json
- **Laravel version**: ^11.0|^12.0 — must support both
- **WorkOS SDK**: ^4.29 — must align with SDK's session management approach
- **Testing**: PHPStan level 8, Pest, Laravel Pint — all must pass before any release
- **Backwards compatibility**: Package is pre-1.0, but existing workbench app must keep working

## Key Decisions

| Decision | Rationale | Outcome |
|----------|-----------|---------|
| Use WorkOS CookieSession + HaliteSessionEncryption | SDK provides battle-tested session crypto; no reason to reimplement | ✓ Good |
| Cookie-based sessions instead of Laravel session store | WorkOS sealed sessions are self-contained; eliminates server-side session storage | ✓ Good |
| Org switch redirects through WorkOS login | Simpler than local session manipulation; ensures fresh tokens with correct org context | ✓ Good |
| Drop Laravel 10 and PHP 8.2 | Enables modern PHP features, reduces compatibility matrix | ✓ Good |
| PHPStan 2.x at level 8 | Better type safety, 50-70% less memory than v1 | ✓ Good |
| Specific exception types over broad catch(\Exception) | Makes debugging easier; SDK errors vs framework errors are distinguishable | ✓ Good |
| Workbench as example app location | Laravel package convention, auto-excluded from package dist | ✓ Good |

## Evolution

This document evolves at phase transitions and milestone boundaries.

**After each phase transition** (via `/gsd-transition`):
1. Requirements invalidated? → Move to Out of Scope with reason
2. Requirements validated? → Move to Validated with phase reference
3. New requirements emerged? → Add to Active
4. Decisions to log? → Add to Key Decisions
5. "What This Is" still accurate? → Update if drifted

**After each milestone** (via `/gsd-complete-milestone`):
1. Full review of all sections
2. Core Value check — still the right priority?
3. Audit Out of Scope — reasons still valid?
4. Update Context with current state

---
*Last updated: 2026-04-06 after initialization*
