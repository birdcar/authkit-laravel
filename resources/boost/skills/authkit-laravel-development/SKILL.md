---
name: authkit-laravel-development
description: >
  Configure and apply the Authkit Laravel package in Laravel applications.
license: MIT
metadata:
  author: Birdcar
---

# Authkit Laravel

Use this skill when a Laravel application needs to integrate the Authkit Laravel package (`birdcar/authkit-laravel`) — AuthKit hosted authentication plus the wider WorkOS suite (organizations, RBAC, FGA, events, audit logs, feature flags, Vault, API keys, Connect/MCP, Pipes) through Laravel-native mechanisms.

## Primary Goal

- apply the `birdcar/authkit-laravel` package's public API in the smallest correct way

## Workflow

### 1. Install and wire authentication

```bash
composer require birdcar/authkit-laravel
php artisan authkit:install   # publishes config + migrations, appends WORKOS_* env keys, generates the cookie password
php artisan migrate
```

Then one manual wiring: add `Authkit\Authkit\Concerns\HasWorkosUser` + `Authkit\Authkit\Concerns\BelongsToWorkosOrganizations` and `implements Authkit\Authkit\Contracts\WorkosUser` to the app's `User` model. The package registers the `workos` guard itself (defaulting to the `users` provider) — define your own `auth.guards.workos` entry in `config/auth.php` only when a different provider is needed.

The package registers routes itself: `authkit.login` (GET /authkit/login), `authkit.callback`, `authkit.logout` (POST), `authkit.switch-org` (POST). Set `WORKOS_API_KEY`, `WORKOS_CLIENT_ID`, `WORKOS_REDIRECT_URI` in `.env`. For local development against `npx @workos/emulate`, set `AUTHKIT_EMULATE_ENABLED=true` — every package HTTP call follows the override.

Protect routes with `Route::middleware('auth:workos')`. Middleware aliases: `authkit.session` (session refresh), `authkit.org` (require org context), `authkit.mcp` (MCP bearer auth).

### 2. Organizations and multi-tenancy

- Give the app's org model `Authkit\Authkit\Concerns\HasWorkosOrganization` and a nullable unique `workos_id` string column; point `authkit.organization.model` (env `AUTHKIT_ORGANIZATION_MODEL`) at it. Creating a local org auto-registers it in WorkOS.
- Current org: `Authkit::currentOrganization()` or `$request->organization()` (claims-resolved, null when no org context).
- Org switch: POST to `route('authkit.switch-org', ['organizationId' => $org->workos_id])`.
- Membership rows project into the `workos_memberships` table; `$user->organizations()` resolves through it on WorkOS ids.

### 3. Keep projections fresh

Run `php artisan authkit:work` as a long-lived process (poller; at-least-once delivery; a cache lock guarantees a single poller). Optional low-latency webhooks: `Route::workosWebhooks('workos/webhooks')` in `routes/web.php` (signature verification + CSRF exclusion built in) with `WORKOS_WEBHOOK_SECRET` set. Both transports dispatch the same events: typed classes under `Authkit\Authkit\Events\Workos\*` for projection-feeding types, `Authkit\Authkit\Events\GenericWorkosEvent` for everything else. Scaffold listeners with `php artisan make:workos-listener NameOfListener`.

### 4. Authorization

- RBAC: `$user->can('permission.slug')` reads the session's JWT permission claims with zero HTTP — no local role tables, no setup beyond login.
- FGA: `Authkit::check(permissionSlug: 'doc.view', resourceExternalId: (string) $doc->getKey(), resourceTypeSlug: 'document')` calls the WorkOS Check API. Sync app models as FGA resources with the `HasWorkosResource` trait (implement `workosResourceType(): string`).
- Opt-in FGA check cache: `AUTHKIT_FGA_CACHE_ENABLED=true` (+ `_TTL`, `_STORE`) — invalidated by membership/role events automatically.

### 5. The rest of the suite, on demand

- **Audit logs**: add `Authkit\Authkit\Concerns\HasAuditLogs` to a model (lifecycle actions audit automatically as `model.create` etc.), or `AuditLog::log('action.name', targets: [], metadata: [...])` for manual events. Requires an org-scoped session (actor/org resolve from claims).
- **Admin Portal**: `Authkit::portalLink($organization, Authkit\Authkit\Enums\PortalIntent::Sso)` — seven intents.
- **Feature flags**: `Feature::store('workos')->for($user)->active('flag-slug')` (Pennant). Claim-first in HTTP sessions, WorkOS API fallback in queues/console.
- **API keys**: guard `auth:authkit-key` for key-authenticated routes; `HasApiKeys` on the user model (`$user->createApiKey('name', $organization)` — raw value on `->value`, shown once), `HasOrganizationApiKeys` on the org model. Key permissions flow into `Gate` automatically.
- **Vault**: cast attributes with `Authkit\Authkit\Casts\Vaulted::class`; wrap a disk with `['driver' => 'vault', 'disk' => 'local']` in `config/filesystems.php`; KV via `Vault::set($keyContext, $name, $value)` / `Vault::get($name)->value`.
- **Connect/MCP**: `Authkit::connect()` manages OAuth/M2M applications; protect MCP routes with `authkit.mcp`; the package serves `/.well-known/oauth-protected-resource`.
- **Pipes**: `$user->connectedAccounts()` and `$user->pipe('provider-slug')` (auto-refreshed tokens; catch `PipesReauthorizationRequiredException` and redirect to its `reauthorizationUrl`).
- **Invitations/JWT templates/CORS/Groups**: `Authkit::invitations()`, `Authkit::jwtTemplate()`, `Authkit::corsOrigins()`, `Authkit::groups()`.

## Rules, References, and Templates

Read before executing:

- `README.md` — feature table and usage warnings (especially the JWT-template 4KB cookie warning)
- `docs/quickstart.md` — the canonical 5-step install path
- `config/authkit.php` — every tunable, all env-driven (`WORKOS_*` / `AUTHKIT_*`); credentials are read from config only, never `env()` at runtime, so `config:cache` is safe

## Examples

- Protect an app section behind AuthKit login with org context required: `Route::middleware(['auth:workos', 'authkit.org'])->group(...)`; link to `route('authkit.login')`; assert in a feature test that an unauthenticated request gets a 401/redirect and an authenticated one passes.
- Gate a feature by WorkOS permission: seed the permission on a role in the WorkOS dashboard, then `$user->can('reports.export')` in a policy or `@can('reports.export')` in Blade — no package-specific check API for RBAC.
- Encrypt a column: add `'ssn' => Vaulted::class` to the model's `casts()`, add a nullable text column, and assert a saved value round-trips while the raw DB column holds ciphertext.

## Anti-patterns

- do not document package internals here; keep the skill focused on adoption in Laravel apps
- do not call the WorkOS SDK (`WorkOS\...`) directly from app code — every supported surface has a package-level API, and the package's own example app is enforced SDK-free
- do not build local role/permission tables or sync roles into spatie-style packages — RBAC reads from JWT claims; local WorkOS-shaped state is limited to the declared projections (users, organizations, domains, memberships)
- do not read `env('WORKOS_*')` at runtime — read `config('authkit.*')`; runtime `env()` breaks under `config:cache`
- do not mint Widget tokens or build WorkOS UI through this package — Widgets are out of scope by design; UI belongs to the app
