# Release Notes

## [Unreleased](https://github.com/birdcar/authkit-laravel/compare/v2.1.0...HEAD)

### Added

- **`Authkit::memberships()`** — the organization-membership management surface `RoleManager`'s docs promised: `create()` / `get()` / `list()` / `update()` / `delete()` plus `deactivate()` / `reactivate()`, accepting org/user models (resolved via `workos_id`) or raw WorkOS ids, with role slugs wrapped into the SDK's `RoleSingle`/`RoleMultiple` value objects. Unlike the pure passthrough managers, every mutation also upserts the local `workos_memberships` projection synchronously, so the request that made the change reads its own write instead of waiting for the events pipeline (which idempotently converges on the same row later).
- **`Authkit::switchToOrganization($org)`** — server-side org switch: the `authkit.switch-org` controller's refresh-scoped-to-org mechanics, extracted into `OrganizationSwitcher` so non-route flows (an onboarding action that just created the user's first org, an org-less-session handoff) can switch without a browser round-trip through the POST route. The rotated session cookie is queued onto the response, and the claims take effect on the next request — callers redirect after switching. The controller now delegates to the same service (`OrganizationSwitchResult` distinguishes its no-session / refused / switched branches).
- **`CurrentOrganizationResolver::forget()`** — clears the resolver's request-lifetime memo; the production counterpart of the `forgetInstance()` reset the testing layer already performed, applied automatically after a successful switch.
- **`MembershipsFake` (`Authkit::fake(['memberships'])`)** — in-memory membership registry that keeps the real manager's projection contract: creates/updates/deletes write the same `workos_memberships` rows, so relation reads behave as in production. `seed()` for fixture state, `assertCreated`/`assertUpdated`/`assertDeleted`/`assertNothingCreated`/`assertNotDeleted`.
- **`OrganizationSwitchFake` (`Authkit::fake(['organization-switch'])`)** — collapses the switch-then-redirect dance for tests: a successful switch re-installs the current fake session with the target `org_id` (and the projection row's role), refusing — like WorkOS — when the user holds no active projected membership in the target org. `refuse()`/`allow()` script the rejected-refresh path; `assertSwitched`/`assertNothingSwitched`.
- **Renames now sync.** `HasWorkosOrganization` gained an `updated` observer hook dispatching the new `UpdateWorkosOrganization` job when the `name` attribute changes — previously a local rename left the WorkOS organization (and everything the hosted UI shows) carrying the old name forever. Idempotent like its siblings: stacked renames converge on the row's current name at dequeue time, and rows that never synced are skipped (the pending create job carries the current name). `OrganizationSyncFake` captures it (`assertUpdateRequested()`), and `assertNothingSyncRequested()` now covers all three jobs.
- **`OrganizationSyncFake::autoCompleting()`** — completes each captured org-create's local effect inline (fake `workos_id` on save), for flows that read `workos_id` in the same request that creates the organization — an onboarding action under `sync_mode: sync` — while keeping the job assertable.
- `authkit:install` now seeds `AUTHKIT_EMULATE_ENABLED` / `AUTHKIT_EMULATE_BASE_URL` into `.env` and `.env.example` (off by default), so switching local development onto `npx @workos/emulate` is a one-line flip instead of a docs hunt.

### Fixed

- **`authkit.login` now works against real WorkOS.** The login redirect never sent `provider=authkit`, and a selector-less `/authorize` is rejected by WorkOS with an "invalid connection selector" error page before any login UI renders — the emulator tolerated it, so the gap only surfaced on the first real-environment login (the token audit). Hosted AuthKit still routes SSO-captured domains itself.
- **An OAuth error callback no longer loops the browser.** `?error=access_denied` (user cancelled) or any other error callback carries no `code`, so the code/state validation redirected "back" — to the authorize URL — which re-ran the redirect and looped forever. Error callbacks (and bookmarked/replayed callback URLs) now land on `/` with a friendly retry message flashed under the `authkit` error key.
- **Publishing the migrations no longer breaks `php artisan migrate`.** Published copies previously got re-timestamped (`publishesMigrations` + `update_date_on_publish`), so the migrator saw them and the auto-loaded package migrations as distinct pending migrations, ran the same DDL twice, and died on a duplicate column. Migrations now publish verbatim, letting the migrator's name-keyed dedupe collapse the two sources — and an app-edited published copy wins over the package original.

### Removed

- The leftover `authkit-laravel:placeholder` Artisan command no longer ships (scaffolding debris; it did nothing but print a line).

### Documentation

- **The JWT token audit has been run against a real WorkOS environment** — `docs/token-audit-findings.md` now carries observed values (issuer format, `aud` shape, and default-presence of `role`/`roles`/`permissions`/`feature_flags`, including the zero-membership and auto-scoping behaviors). `config/authkit.php`'s `jwt` comments now cite the findings instead of a TBD.

## [v2.1.0](https://github.com/birdcar/authkit-laravel/compare/v2.0.0...v2.1.0) - 2026-08-13

v2.1.0 brings the missing chapter: first-party testing support, so consuming apps can write fast, fully offline Pest tests against every WorkOS surface this package wraps. The full guide lives at [docs/testing.md](https://github.com/birdcar/authkit-laravel/blob/main/docs/testing.md).

### Added

- **`Authkit::actingAs($user, [...])` / `Authkit::actingAsGuest()`** — synthetic `workos` sessions with no cookie, JWKS fetch, or SDK call. Claims flow through the exact contracts the real guard uses, so `Gate` checks, `Authkit::currentOrganization()`, `$request->organization()`, and the Pennant `workos` store behave as they would against a genuine login. Supports organizations (model or `org_...` string), roles, permissions, feature flags, impersonation (fires `Impersonating`), and raw claim overrides.
- **`Authkit::fake()`** — swaps manager bindings for in-memory fakes and returns a typed scripting/assertion handle. Fake everything or a subset (`Authkit::fake(['fga', 'vault'])`); unfaked managers keep real behavior on every code path. Fakes for **FGA** (scriptable allow/deny, default deny, `assertChecked`), **Invitations** (stateful send/revoke/accept registry), **Audit Logs** (`AuditLog::assertLogged(...)` with real actor resolution and metadata sanitization, export lifecycle, schema registrations), **organization sync** (Bus-level capture + `completeSync()`), **API keys** (user- and org-scoped create/list/revoke, and the `authkit-key` guard authenticates fake keys through its real machinery), **Vault** (in-memory KV with version history and optimistic-locking `ConflictException` parity; the `Vaulted` cast and `vault` disk run their real code paths over visibly-marked fake crypto), **Pipes** (connected-account fixtures, scripted tokens, both business exceptions triggerable), and **Groups** (CRUD, membership, role assignments — FGA cache-bust contract preserved).
- **WorkOS traffic now rides the application's HTTP client** (`authkit.http.transport`, default `laravel`; set `AUTHKIT_HTTP_TRANSPORT=guzzle` to opt out). Laravel's native testing idioms finally see WorkOS calls — `Http::preventStrayRequests()`, `Http::fake()`, `Http::assertSent()` — and in production, global HTTP middleware and HTTP client events observe WorkOS requests like any other outbound call. The SDK's retry, error-mapping, and JWKS-cache behavior is unchanged.

### Changed

- Manager classes that gained fakes (`FgaChecker`, `InvitationManager`, `AuditLogManager`, `VaultManager`, `VaultCrypto`, `PipesManager`, `GroupManager`) are no longer `final` — each now has a deliberately designed testing subclass, and an arch test guards the seam.
- Calling a testing assertion (e.g. `AuditLog::assertLogged(...)`) without faking first now throws a `LogicException` naming the fix instead of an undefined-method fatal.

### Fixed

- Compatibility with `workos/workos-php` 9.2.0: the events poller passes the now-required `$events` filter explicitly (empty = all event types, the same wire request as before).

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
