# Release Notes

## [Unreleased](https://github.com/birdcar/authkit-laravel/compare/v2.0.0...HEAD)

### Added

- First-party testing layer ([docs/testing.md](docs/testing.md)): `Authkit::actingAs()` / `Authkit::actingAsGuest()` synthetic `workos` sessions (claims flow through the real guard contracts — Gate, current organization, and Pennant flags all work offline), and `Authkit::fake()` with in-memory fakes for FGA, Invitations, Audit Logs, organization sync, API keys (user- and org-scoped, incl. the `authkit-key` guard's validate path), Vault (the `Vaulted` cast and `vault` disk run their real code paths over marker-prefixed fake crypto), Pipes, and Groups — each recording calls and exposing Laravel-style `assert*` helpers
- WorkOS SDK traffic now rides the application's HTTP client by default (`authkit.http.transport`, `AUTHKIT_HTTP_TRANSPORT=guzzle` to opt out) — `Http::fake()`, `Http::preventStrayRequests()`, and `Http::assertSent()` see WorkOS calls natively in tests, and global HTTP middleware/events observe them in production
- `tests/Testing` suite covering the new layer end to end

### Changed

- Manager classes that gained fakes (`FgaChecker`, `InvitationManager`, `AuditLogManager`, `VaultManager`, `VaultCrypto`, `PipesManager`, `GroupManager`) are no longer `final` — each now has a deliberately designed testing subclass, and `tests/Testing/ManagerSwappabilityTest.php` guards the seam

## [v2.0.0](https://github.com/birdcar/authkit-laravel/compare/v1.0.1...v2.0.0) - 2026-08-13

### v2.0.0

A ground-up rebuild of `birdcar/authkit-laravel`: one package wrapping AuthKit and the entire WorkOS platform behind Laravel-native mechanisms — headless plumbing only, no UI opinions.

> **Breaking change:** v2.0.0 shares no API surface with the v1.x line. v1.x was built on `workos/workos-php` v4/v5 and is superseded entirely; there is no in-place upgrade path. Treat this as a new integration and follow [docs/quickstart.md](https://github.com/birdcar/authkit-laravel/blob/main/docs/quickstart.md).

#### Requirements

- PHP 8.3 – 8.5
- Laravel 12 or 13
- `workos/workos-php` ^9.1

#### What's included

- **AuthKit hosted auth** — `authkit.login` / `authkit.callback` / `authkit.logout` routes with PKCE + state handled for you
- **Sessions & tokens** — `workos` guard over the sealed session cookie, `iss`/`aud` hardening, refresh middleware, cookie-size guardrails
- **User management** — `HasWorkosUser` trait with local user projection and `workos_id` ↔ `external_id` linking on first login
- **Organizations** — domains + memberships as local Eloquent projections, claims-resolved current org, org-switch route, tenant middleware
- **Events pipeline** — `php artisan authkit:work` cursor-persisted poller, typed events, `make:workos-listener` generator
- **Webhooks** — `Route::workosWebhooks()` macro with signature verification and CSRF exclusion built in
- **RBAC** — role/permission claims into `Gate::before`; `$user->can()` costs zero HTTP
- **FGA** — `Authkit::check()` against the WorkOS Check API, resource sync trait, opt-in cache with events-driven invalidation
- **Audit Logs** — `HasAuditLogs` model trait, `AuditLog::log()` facade, schema/export/retention passthroughs
- **Admin Portal** — `Authkit::portalLink()` across all seven intents
- **Feature Flags** — first-party Pennant driver (`Feature::store('workos')`)
- **API Keys** — `authkit-key` guard with key permissions into Gate, issue/revoke/list on user + org models
- **Vault** — `Vaulted` Eloquent cast, `vault` filesystem driver, `Vault` KV facade (BYOK envelope encryption)
- **Connect & MCP** — application registry, `authkit.mcp` bearer middleware (RFC 6750), protected-resource metadata route (RFC 9728)
- **Pipes** — `$user->connectedAccounts()` / `$user->pipe('slug')` with WorkOS-managed token refresh
- **Depth extensions** — invitations, JWT templates, CORS origins, Groups API

#### Verification

- Full CI matrix green: PHP 8.3–8.5 × Laravel 12/13 × prefer-lowest/prefer-stable × ubuntu/windows (24 jobs)
- 530 tests / 1,693 assertions, PHPStan level 7 clean, 100% type coverage
- Acceptance suite runs the full login → org projection → `can()` journey against a live `@workos/emulate` server

#### Quickstart trial

Waived for this release by maintainer decision: the first consumer (a starter kit built on this package) serves as the live end-to-end trial, with patch releases to follow if anything snags.

## [v1.0.1](https://github.com/birdcar/authkit-laravel/compare/v1.0.0...v1.0.1) - 2026-08-13

Hotfix on the original v1 line: restored `workos/workos-php` v5 compatibility.
This is the last release of the original codebase — v2.0.0 is a ground-up
rebuild with a new API surface.

## [v0.1.0](https://github.com/birdcar/authkit-laravel/compare/...v0.1.0) - 2026-01-23

Initial pre-release.
