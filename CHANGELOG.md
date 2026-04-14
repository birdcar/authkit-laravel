# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0](https://github.com/birdcar/authkit-laravel/compare/v0.8.0...v1.0.0) (2026-04-14)


### ⚠ BREAKING CHANGES

* This release graduates the package from pre-1.0 to stable. No API changes from 0.8.0.

### Features

* Stabilize public API for v1.0.0 ([f836154](https://github.com/birdcar/authkit-laravel/commit/f8361541ca31bf5eccba8910a8b0c3b23f5e2eb6))

## [0.8.0](https://github.com/birdcar/authkit-laravel/compare/v0.7.0...v0.8.0) (2026-04-14)


### ⚠ BREAKING CHANGES

* Requires workos/workos-php ^5.0. SessionManager, AuditLogger, and all service constructors changed. WorkOS facade methods updated. Existing v4 session cookies will be invalidated.

### Features

* Add API key validation ([39ef63f](https://github.com/birdcar/authkit-laravel/commit/39ef63fe3298404828fc569865923ac2b14a47f9))
* Add feature flags and FGA services ([8022eba](https://github.com/birdcar/authkit-laravel/commit/8022ebaf8df301c9b4cfb9136d0e8c86f0303058))
* Add session token parity and auth flow ([a49e311](https://github.com/birdcar/authkit-laravel/commit/a49e311322069743fe8d9a9dacb536c5e6c84735))
* Add typed dsync and domain verification events ([8a69275](https://github.com/birdcar/authkit-laravel/commit/8a69275589c386e0f01d7d7a1514aa6f6f8cbb72))
* Add Vault, Radar, Pipes, and Domain services ([ed37f3b](https://github.com/birdcar/authkit-laravel/commit/ed37f3b00ebec885a87d17a6aa2b634ad5faa4a5))
* Upgrade WorkOS PHP SDK to v5 ([6a96b6d](https://github.com/birdcar/authkit-laravel/commit/6a96b6dcc0e9e81258d7407563babd7c3aa66b96))

## [0.7.0](https://github.com/birdcar/authkit-laravel/compare/v0.6.0...v0.7.0) (2026-04-11)


### Features

* **commands:** Add `workos:make-listener` artisan command — interactively scaffolds event listeners with correct imports, `HandlesWorkOSEvents` trait, and union type hints for multi-event handling ([ff05d89](https://github.com/birdcar/authkit-laravel/commit/ff05d89))


## [0.6.0](https://github.com/birdcar/authkit-laravel/compare/v0.5.0...v0.6.0) (2026-04-11)


### Features

* **listeners:** Add `HandlesWorkOSEvents` trait for consumer listeners with `resolveUser()`, `resolveOrganization()`, `audit()`, `logEvent()`, and `withinTransaction()` helpers ([fd8a31a](https://github.com/birdcar/authkit-laravel/commit/fd8a31a))
* **config:** Add per-event `sync.listeners` config — replace, disable, or keep default listeners per event class ([fd8a31a](https://github.com/birdcar/authkit-laravel/commit/fd8a31a))
* **events:** Add `get()` method to `WorkOSEventReceived` for API consistency with typed sync events ([fd8a31a](https://github.com/birdcar/authkit-laravel/commit/fd8a31a))


### Bug Fixes

* **testing:** Fix Facade cache invalidation in `WorkOS::fake()` and `WorkOS::restore()` — Facade calls after `fake()` now correctly resolve to the fake instance ([fd8a31a](https://github.com/birdcar/authkit-laravel/commit/fd8a31a))


## [0.5.0](https://github.com/birdcar/authkit-laravel/compare/v0.4.2...v0.5.0) (2026-04-11)


### ⚠ BREAKING CHANGES

* **events:** Event namespace changed from `Events\Webhooks\*` to `Events\Sync\*`. `WebhookReceived` renamed to `WorkOSEventReceived`. `HasWebhookData` trait renamed to `HasEventData`. Default listeners renamed from `*FromWebhook` to `*FromWorkOS`.

### How to upgrade

1. **Update event imports** — find-and-replace across your codebase:
   - `WorkOS\AuthKit\Events\Webhooks\` → `WorkOS\AuthKit\Events\Sync\`
   - `WorkOS\AuthKit\Events\WebhookReceived` → `WorkOS\AuthKit\Events\WorkOSEventReceived`
   - `HasWebhookData` → `HasEventData` (if referencing the trait directly)
2. **Update listener imports** (if referencing default listeners):
   - `SyncUserFromWebhook` → `SyncUserFromWorkOS`
   - `SyncOrganizationFromWebhook` → `SyncOrganizationFromWorkOS`
   - `SyncMembershipFromWebhook` → `SyncMembershipFromWorkOS`

### Bug Fixes

* **events:** Replace invalid `user.session_revoked` with `session.revoked` per WorkOS Events API docs ([6d24bcb](https://github.com/birdcar/authkit-laravel/commit/6d24bcb))
* **events:** Add missing `authentication.passkey_succeeded` to EVENT_MAP ([6d24bcb](https://github.com/birdcar/authkit-laravel/commit/6d24bcb))


### Code Refactoring

* Rename event infrastructure from `Webhooks` to `Sync` — events can arrive via webhooks or the Events API, so the old naming was misleading ([0f272d1](https://github.com/birdcar/authkit-laravel/commit/0f272d1))


### Tests

* Add EVENT_MAP validation test that checks all event names against the live WorkOS API ([99e5c67](https://github.com/birdcar/authkit-laravel/commit/99e5c67))


## [0.4.2](https://github.com/birdcar/authkit-laravel/compare/v0.4.1...v0.4.2) (2026-04-11)


### Bug Fixes

* **events:** Use millisecond-precision UTC (`2026-04-04T00:00:00.000Z`) for Events API `range_start` — the WorkOS API requires exactly 3-digit milliseconds with Z suffix; date-only and microsecond formats are both rejected. Also normalizes `--since` input so any parseable date string works. ([f12ed9a](https://github.com/birdcar/authkit-laravel/commit/f12ed9a))


## [0.4.1](https://github.com/birdcar/authkit-laravel/compare/v0.4.0...v0.4.1) (2026-04-11) [YANKED]


### Bug Fixes

* **events:** Use date-only format (YYYY-MM-DD) for Events API `range_start` parameter — incorrect fix, date-only format is also rejected by WorkOS ([8e12acd](https://github.com/birdcar/authkit-laravel/commit/8e12acd))


## [0.4.0](https://github.com/birdcar/authkit-laravel/compare/v0.3.0...v0.4.0) (2026-04-11)


### ⚠ BREAKING CHANGES

* **config:** The `workos.webhooks.sync_enabled` config key has been removed. Event sync is now routed per-category through `workos.events.routing.categories`, where each category (`user`, `organization`, `organization_membership`, `dsync`, `session`, `authentication`) can be set to `'webhooks'`, `'events_api'`, or `'both'`.
* **events-listen:** The `workos:events-listen` command has been completely rewritten. It is no longer an SSE-based stream — it now polls `GET /events` with cursor-based pagination and persists its cursor in Laravel Cache across restarts.

### How to upgrade

1. **Publish the updated config** — run `php artisan vendor:publish --tag=workos-config --force` to get the new `workos.events` section. Review your existing `config/workos.php` for any customizations before overwriting.
2. **Remove `WORKOS_WEBHOOK_SYNC_ENABLED`** from your `.env` — this env var no longer has any effect.
3. **Configure event routing** — by default, all categories sync via webhooks except `dsync` which uses `events_api`. Adjust per-category with env vars (`WORKOS_SYNC_USER`, `WORKOS_SYNC_ORGANIZATION`, `WORKOS_SYNC_DSYNC`, etc.) or edit `config/workos.php` directly.
4. **Update process supervisors** — if you were running `workos:events-listen`, it now behaves like `queue:work`: a persistent polling loop with graceful shutdown (SIGTERM/SIGINT). Update your Supervisor/systemd configs accordingly. New flags: `--once` (single poll), `--since` (bootstrap from date), `--sleep` (poll interval override).


### Features

* Rewrite events-listen as a correct REST polling worker with cursor persistence, automatic backoff, and graceful shutdown ([4a629a3](https://github.com/birdcar/authkit-laravel/commit/4a629a3))
* Add event sync routing and EventRouting service — per-category control over whether events flow through webhooks, the Events API, or both ([4ef31a7](https://github.com/birdcar/authkit-laravel/commit/4ef31a7))


### Documentation

* Add comprehensive documentation for events API, configuration, commands, and webhooks ([b093eba](https://github.com/birdcar/authkit-laravel/commit/b093eba))
* Add events API worker ideation contract and specs ([0c3fc03](https://github.com/birdcar/authkit-laravel/commit/0c3fc03))


## [0.3.0](https://github.com/birdcar/authkit-laravel/compare/v0.2.0...v0.3.0) (2026-04-10)


### Features

* Add Laravel 13 support (`illuminate/contracts ^13.0`, `illuminate/support ^13.0`, `orchestra/testbench ^11.0`)


## [0.2.0](https://github.com/birdcar/authkit-laravel/compare/v0.1.0...v0.2.0) (2026-04-08)


### ⚠ BREAKING CHANGES

* HasOrganization::switchOrganization() has been removed. Use the /organizations/switch endpoint or redirect to WorkOS login with organization_id parameter.
* SessionManagerInterface and the Laravel session driver have been removed. Config keys session.cookie_session and session.refresh_buffer_minutes no longer exist.
* Removed deprecated trait aliases. Users must update imports from WorkOS\AuthKit\Traits\* to WorkOS\AuthKit\Models\Concerns\* namespace.
* PHP 8.2 and Laravel 10 are no longer supported. Upgrade to PHP 8.3+ and Laravel 11+ before updating this package.
* The pivot table is renamed from `organization_user` to `organization_memberships` for clarity. The bundled Organization model is removed - users must provide their own via config or use the install wizard to generate one.

### Features

* **02-02:** add WorkOSFakeExampleTest.php with three fake patterns ([75b1636](https://github.com/birdcar/authkit-laravel/commit/75b163656051c8120fae4c52d7148bb3b4df06f4))
* **02-02:** convert AuthTest dashboard test to WorkOS::actingAs() pattern ([9e3e2b5](https://github.com/birdcar/authkit-laravel/commit/9e3e2b5c84ceca4471bac9f17a663cceac8ff65f))
* **03-01:** add NodeToolingDetector with WorkOS CLI delegation ([ccd81c5](https://github.com/birdcar/authkit-laravel/commit/ccd81c5d578f69e979ac5b7519119494e34d1d88))
* **03-02:** add --force bypass to WizardFlow, LaravelWorkosMigrator, AuthSystemInstaller ([add644e](https://github.com/birdcar/authkit-laravel/commit/add644ed82b660cef53f044c0f14d9e7e6d86a86))
* **03-02:** harden --mini to write env placeholders and migration plan file ([77d63c4](https://github.com/birdcar/authkit-laravel/commit/77d63c4318e68f53b912f607725c920e4576a610))
* **03-03:** add per-key duplicate guard to EnvManager.applyChanges ([427c20e](https://github.com/birdcar/authkit-laravel/commit/427c20eaa21377e93fc0de60138e1ace7dcc4379))
* **03-03:** harden AuthSystemInstaller post-write verification ([cf514a2](https://github.com/birdcar/authkit-laravel/commit/cf514a2b2ca1e770422596133bab3f75539f901b))
* **04-02:** add RBAC middleware to routes and remove ExampleTest stub ([a60c0f1](https://github.com/birdcar/authkit-laravel/commit/a60c0f13de40584fbfc2a980f465da1f63f427e4))
* **04-02:** convert feature tests to WorkOS::fake() and add RBAC tests ([b040176](https://github.com/birdcar/authkit-laravel/commit/b04017661b3ce3a7cacfe65b7c82c28ce8c3ba77))
* add AdminPortal Livewire widget components (Phase 4) ([c0f848b](https://github.com/birdcar/authkit-laravel/commit/c0f848bcce5b5c05d8f42c0c16b246fad87e6fcf))
* add API Keys, Data Integrations, Directory Sync, and Settings widgets (Phase 5) ([c9676bf](https://github.com/birdcar/authkit-laravel/commit/c9676bf1e09c00360b59eda329c0ee16113ad350))
* add release-please, Codecov, Dependabot, and Packagist-ready install ([6959c17](https://github.com/birdcar/authkit-laravel/commit/6959c1739e4e2b49e59cd7f660fdde2883622050))
* add UserManagement Livewire widget components ([e6a687c](https://github.com/birdcar/authkit-laravel/commit/e6a687c0c00aa34a09dcb0d80ae87b6718b3d7df))
* add UserProfile Livewire widget components (Phase 3) ([0905c00](https://github.com/birdcar/authkit-laravel/commit/0905c001fd312a025310fdbb18bd26509b2f6502))
* Drop Laravel 10 and PHP 8.2 support ([3876fae](https://github.com/birdcar/authkit-laravel/commit/3876fae3bc21fb32b60e49fc9a52a7b8b46bb99d))
* **install:** Add environment detection for smart install ([5ccd57e](https://github.com/birdcar/authkit-laravel/commit/5ccd57e262f9db7bd323ca3a2625dd650f2e1315))
* **install:** Add interactive wizard with component installers ([1471a1a](https://github.com/birdcar/authkit-laravel/commit/1471a1a421ef2e6e1ca3ebe69b818f8bb73bd967))
* **install:** Add migration plan generator for existing auth ([6cd46d9](https://github.com/birdcar/authkit-laravel/commit/6cd46d99c7870a88a697483220aec046970be31e))
* **install:** Enhance wizard with model generation and migration ([76cd01a](https://github.com/birdcar/authkit-laravel/commit/76cd01a0c5b4d6c2745a98728e9d7cf70b315905))
* **widgets:** add Livewire widget infrastructure — traits, CSS, service provider ([14f131f](https://github.com/birdcar/authkit-laravel/commit/14f131fc8087451b49c8aca611fcd6d47aac44e0))


### Bug Fixes

* **02-01:** rename tearDownWorkOS to tearDownInteractsWithWorkOS ([c4e309b](https://github.com/birdcar/authkit-laravel/commit/c4e309ba886d50a59202c185062d02444dcef169))
* **03:** revise plans based on checker feedback ([3d8c201](https://github.com/birdcar/authkit-laravel/commit/3d8c2019f751346cee4048ef9338ca80c1dc9805))
* Add Laravel 10 compatibility for migration publishing ([9b9ef30](https://github.com/birdcar/authkit-laravel/commit/9b9ef30dcd0b9e313283d02bb7d17e2b13980663))
* **docs:** correct InteractsWithWorkOS teardown advice and audit example ([c9738b1](https://github.com/birdcar/authkit-laravel/commit/c9738b18456baa6e8002a271261493fba8fc6c03))
* Improve code quality and validation ([e8689a7](https://github.com/birdcar/authkit-laravel/commit/e8689a7cc550f866ee81d745486566e149bb1127))
* Resolve PHPStan level 8 errors ([30a39ba](https://github.com/birdcar/authkit-laravel/commit/30a39ba32d6436ed52bb66339890f9576ec42864))
* **testing:** add getLogoutUrl and destroySession to WorkOSFake ([fd3eba4](https://github.com/birdcar/authkit-laravel/commit/fd3eba4e7c1f6bfcf63f2b793ca81573257f8aa4))
* **tests:** register workos guard in test environment and use it in AuditLogger ([3215a85](https://github.com/birdcar/authkit-laravel/commit/3215a85bdf61610923f1c64e4303f0b666bdc475))
* **tests:** Use uses() instead of pest() for group configuration ([1290893](https://github.com/birdcar/authkit-laravel/commit/12908932b4b2cca735ec4604b6fe983c466cd7c5))
* Use explicit SQLite database for tests ([a3676af](https://github.com/birdcar/authkit-laravel/commit/a3676af5f758e38eb8906a490bd2595a170ecf6e))


### Miscellaneous Chores

* **04-01:** satisfy WORK-06 and WORK-07 compliance gaps ([e7d10df](https://github.com/birdcar/authkit-laravel/commit/e7d10df4557f3ffb48b3421c4b5c5067eb9c4d79))
* add project config ([4703d45](https://github.com/birdcar/authkit-laravel/commit/4703d4559ac16d77e3c4e7e8716d19f5d08aef5c))
* merge executor worktree (02-01 teardown fix + DI test) ([8a7321a](https://github.com/birdcar/authkit-laravel/commit/8a7321a2888e87c440fbef4a5bc260ea60e57eb1))
* merge executor worktree (02-02 workbench example tests) ([aec17d2](https://github.com/birdcar/authkit-laravel/commit/aec17d298cc4e746e4febe861284697eb580cad0))
* merge executor worktree (worktree-agent-afba48ba) ([9fc498d](https://github.com/birdcar/authkit-laravel/commit/9fc498d7c52ff345f14a2d80e496b1ac0304c61e))
* Remove PruneSessionsCommand ([e7569ca](https://github.com/birdcar/authkit-laravel/commit/e7569ca764abfa43698255cb3f35068c350aa4d9))
* Rename package to birdcar/authkit-laravel ([9211719](https://github.com/birdcar/authkit-laravel/commit/92117191b61141569b4010f1a8013595e0dbdd36))
* update planning config ([240939e](https://github.com/birdcar/authkit-laravel/commit/240939e31adf231833bafb130611b7d5912df3a4))


### Code Refactoring

* Consolidate to WorkOS cookie session ([4f1cd6e](https://github.com/birdcar/authkit-laravel/commit/4f1cd6ea8ee313689cc124efb4af45d2205eccc5))
* Modernize codebase with PHP 8.3 features ([5673977](https://github.com/birdcar/authkit-laravel/commit/567397720ce3bbe70aebcc9b8d5f4baa4d7eab5e))
* Redirect org switch through WorkOS login ([d4884dc](https://github.com/birdcar/authkit-laravel/commit/d4884dcbad873ff20afd2a65fbaeb030d9fea314))
* Remove package Organization model, rename pivot table ([0815b87](https://github.com/birdcar/authkit-laravel/commit/0815b87eeae9bdb0dda9a03887d04e0011389cf5))
