<div align="center">
    <h1>Authkit Laravel</h1>
</div>

<p align="center">
    <a href="https://packagist.org/packages/birdcar/authkit-laravel"><img src="https://img.shields.io/packagist/v/birdcar/authkit-laravel.svg?style=flat-square" alt="Packagist"></a>
    <a href="https://packagist.org/packages/birdcar/authkit-laravel"><img src="https://img.shields.io/packagist/php-v/birdcar/authkit-laravel.svg?style=flat-square" alt="PHP from Packagist"></a>
    <a href="https://badge.laravel.cloud/badge/birdcar/authkit-laravel?style=flat"><img src="https://badge.laravel.cloud/badge/birdcar/authkit-laravel?style=flat" alt="Laravel versions"></a>
    <a href="https://github.com/birdcar/authkit-laravel/actions"><img alt="GitHub Workflow Status (main)" src="https://img.shields.io/github/actions/workflow/status/birdcar/authkit-laravel/tests.yml?branch=main&label=Tests&style=flat-square"></a>
    <a href="https://packagist.org/packages/birdcar/authkit-laravel"><img src="https://img.shields.io/packagist/dt/birdcar/authkit-laravel.svg?style=flat-square" alt="Total Downloads"></a>
</p>

A "batteries included" implementation of AuthKit and the entire WorkOS suite
for Laravel — one package, headless plumbing only, no UI opinions.

Where [`laravel/workos`](https://github.com/laravel/workos) wires AuthKit
login into a starter kit, this package wraps the **whole WorkOS platform**
behind Laravel-native mechanisms: a `workos` session guard over the AuthKit
sealed cookie, organizations and memberships as local Eloquent projections
kept fresh by an events pipeline, RBAC answered from JWT claims with zero HTTP
per check, FGA via the Check API, a first-party Pennant driver, audit logs on
model lifecycle hooks, Vault as an Eloquent cast + filesystem driver + KV
facade, an API-key auth guard, Connect application management, MCP bearer
auth, and Pipes connected accounts.

WorkOS stays canonical everywhere: local rows are declared projections linked
by `workos_id`/`external_id`, refreshed by the events pipeline — never a
second source of truth.

## Quickstart

Five steps from `composer require` to a working login with organizations and
role-based authorization live: **[docs/quickstart.md](docs/quickstart.md)**.

## What's Included

| Area | Surface |
|---|---|
| AuthKit hosted auth | `authkit.login` / `authkit.callback` / `authkit.logout` routes (thin wrappers over public form requests), PKCE + state handled for you |
| Sessions & tokens | `workos` guard over the sealed session cookie, `iss`/`aud` hardening, refresh middleware (`authkit.session`), 4KB cookie-ceiling guardrails |
| User management | `HasWorkosUser` trait: local user projection, `workos_id` ↔ `external_id` linking on first login, impersonation surfaced as an event |
| Organizations | `HasWorkosOrganization` + `BelongsToWorkosOrganizations` traits, domains + memberships projections, claims-resolved current org (`Authkit::currentOrganization()`, `$request->organization()`), org-switch route, `authkit.org` tenant middleware |
| Events pipeline | `php artisan authkit:work` poller (cursor-persisted, single-flight), typed events for projection-feeding types + `GenericWorkosEvent` for everything else, `make:workos-listener` generator |
| Webhooks | `Route::workosWebhooks()` macro — signature verification and CSRF exclusion built in, same event objects as the poller |
| Authorization (RBAC) | Role/permission claims into `Gate::before` — `$user->can('posts.manage')` costs zero HTTP |
| Authorization (FGA) | `Authkit::check()` against the WorkOS Check API, `HasWorkosResource` trait for resource sync, resource-graph helpers, opt-in check cache with events-driven invalidation |
| Audit Logs | `HasAuditLogs` model trait (create/update/archive/delete/restore), `AuditLog::log()` facade, schema/export/retention passthroughs |
| Admin Portal | `Authkit::portalLink($org, PortalIntent::Sso)` across all seven intents |
| Feature Flags | First-party Pennant driver (`Feature::store('workos')`): claim-first in HTTP, WorkOS API fallback in queues/console |
| API Keys | `authkit-key` guard, key permissions into Gate, issue/revoke/list on user + org models (raw value shown once, structurally) |
| Vault | `Vaulted` Eloquent cast, `vault` filesystem driver wrapping any disk, `Vault` KV facade — BYOK envelope encryption throughout |
| Connect & MCP | `Authkit::connect()` application registry, `authkit.mcp` bearer middleware (RFC 6750), `/.well-known/oauth-protected-resource` (RFC 9728) |
| Pipes | `$user->connectedAccounts()` / `$user->pipe('slug')` with WorkOS-managed token refresh, org provider-config passthrough |
| Depth extensions | Invitations, JWT template + CORS origin passthroughs, Groups API |

Directory Sync ships no dedicated module by design — WorkOS-managed
provisioning plus events-pipeline listeners cover it. Widgets are excluded
from v1 entirely: UI belongs to your app, this package is plumbing.

## Installation

```bash
composer require birdcar/authkit-laravel
php artisan authkit:install
```

The installer publishes the config and migrations, appends the `WORKOS_*`
keys to your `.env` and `.env.example`, and generates a session cookie
password. It is safe to re-run: existing keys are left untouched. Follow
[the quickstart](docs/quickstart.md) for the two wiring steps that remain.

You may instead publish resources individually:

```bash
php artisan vendor:publish --tag="authkit-config"
php artisan vendor:publish --tag="authkit-laravel-migrations"
php artisan vendor:publish --tag="authkit-laravel-views"
php artisan vendor:publish --tag="authkit-laravel-lang"
php artisan vendor:publish --tag="authkit-laravel-assets"
```

Or everything at once with `--tag="authkit-laravel"`.

## Local Development Against the Emulator

Every package HTTP call — the SDK client, the guard's JWKS verification,
logout URLs — honors one switch:

```dotenv
AUTHKIT_EMULATE_ENABLED=true
```

Run [`@workos/emulate`](https://www.npmjs.com/package/@workos/emulate)
locally (`npx @workos/emulate`) and the whole login → claims → RBAC loop
works offline. The package's own acceptance suite runs this exact journey.

## Usage Notes

### JWT Templates

`Authkit::jwtTemplate()` wraps the environment's JWT template:

```php
use Authkit\Authkit\Facades\Authkit;

$template = Authkit::jwtTemplate()->get();

Authkit::jwtTemplate()->update('{"plan": "{{ organization.name }}"}');
```

> [!WARNING]
> **Editing the JWT template changes every access token your environment mints
> from that moment on — and the AuthKit sealed session cookie that carries
> those tokens has a hard 4KB browser ceiling.**
>
> A template that grows the claim set (for example by embedding large
> role/permission arrays) can push the sealed cookie past 4KB, at which point
> browsers silently truncate or drop it: the `workos` guard can no longer
> unseal the session and users are locked out of login entirely. Claims are
> also what back this package's zero-HTTP RBAC checks and the Pennant
> `feature_flags` claim, so template edits shift authorization behavior, not
> just token cosmetics.
>
> Every `update()` call logs a warning and dispatches the
> `Authkit\Authkit\Events\JwtTemplateUpdated` event (carrying the before/after
> content) — listen for it to wire your own alerting. **Always verify a real
> login end-to-end in staging after a template change before deploying it.**
> If you need bulky data available at runtime, keep it out of the template and
> use the runtime APIs instead of growing the token.

### MCP Servers Behind `laravel/mcp`

When protecting a [`laravel/mcp`](https://github.com/laravel/mcp) server with
the `authkit.mcp` middleware, note that `laravel/mcp`'s own
`AddWwwAuthenticateHeader` middleware rewrites 401 challenges on `Mcp::web`
routes and drops the `resource_metadata` pointer this package emits per
RFC 9728. Clients that discover the protected-resource document from the
challenge header should read it from
`/.well-known/oauth-protected-resource` directly.

## Release Process

Maintainers: see [docs/release-checklist.md](docs/release-checklist.md) —
including the required human quickstart trial log.

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Thank you for considering contributing to Authkit Laravel! Please review our [contributing guide](.github/CONTRIBUTING.md) to get started.

## Security Vulnerabilities

Please review [our security policy](.github/SECURITY.md) on how to report security vulnerabilities.

## Credits

- [Birdcar](https://github.com/birdcar)
- [All Contributors](../../contributors)

## License

Authkit Laravel is open-sourced software licensed under the [MIT license](LICENSE.md).
