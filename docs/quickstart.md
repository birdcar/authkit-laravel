# Quickstart

Get AuthKit Laravel running in a fresh Laravel app in under 10 minutes.

1. **Require the package.**

   ```bash
   composer require birdcar/authkit-laravel
   ```

2. **Install AuthKit.** This publishes the config and migrations, appends the
   `WORKOS_*` keys to your `.env` and `.env.example`, and generates a session
   cookie password. It is safe to re-run.

   ```bash
   php artisan authkit:install
   ```

   Open `.env` and paste your real `WORKOS_API_KEY` and `WORKOS_CLIENT_ID`
   from the [WorkOS Dashboard](https://dashboard.workos.com), and confirm
   `WORKOS_REDIRECT_URI` matches the redirect URI configured for your AuthKit
   environment (the installer generated `WORKOS_COOKIE_PASSWORD` for you).

3. **Wire your user model.** Add the trait and contract to your `User` model
   (the package registers the `workos` guard for you, defaulting to your
   `users` provider — define your own `auth.guards.workos` entry only if you
   need a different provider):

   ```php
   // app/Models/User.php
   use Authkit\Authkit\Concerns\BelongsToWorkosOrganizations;
   use Authkit\Authkit\Concerns\HasWorkosUser;
   use Authkit\Authkit\Contracts\WorkosUser;

   class User extends Authenticatable implements WorkosUser
   {
       use BelongsToWorkosOrganizations, HasWorkosUser;
   }
   ```

4. **Run the migrations.**

   ```bash
   php artisan migrate
   ```

5. **Log in.** Visit `/authkit/login` in your browser (or link to
   `route('authkit.login')` — the package registered the routes for you).
   Organizations and role-based authorization are live immediately from the
   JWT claims on your first login: `$user->can('your.permission')` reads the
   token's permission claims with zero extra HTTP calls,
   `Authkit::currentOrganization()` resolves the session's organization, and
   your local `users` row is linked to WorkOS (`workos_id` stored locally,
   your primary key stored as the WorkOS `external_id`).

That's it. Protect routes with `Route::middleware('auth:workos')`, and see the
[README](../README.md) for everything else the package wraps — FGA, Feature
Flags, Vault, Audit Logs, API keys, Connect/MCP, Pipes, and more. The sections
below cover the pieces most apps add next.

## Environment Variables

In addition to the core WorkOS credentials (`WORKOS_API_KEY`,
`WORKOS_CLIENT_ID`, `WORKOS_REDIRECT_URI`, `WORKOS_COOKIE_PASSWORD`), the
events pipeline and webhooks read:

```dotenv
# Required to receive WorkOS webhooks — the endpoint secret from the
# WorkOS Dashboard's Webhooks page. Signature verification fails fast
# (with an exception naming authkit.webhooks.secret) when this is unset.
WORKOS_WEBHOOK_SECRET=

# Optional events-pipeline tuning (defaults shown).
AUTHKIT_EVENTS_POLL_INTERVAL=5
AUTHKIT_EVENTS_BATCH_LIMIT=100
AUTHKIT_EVENTS_BACKFILL_MINUTES=5
AUTHKIT_EVENTS_LOCK_TTL=90
AUTHKIT_WEBHOOKS_TOLERANCE=180
```

For local development against [`workos/emulate`](https://www.npmjs.com/package/@workos/emulate),
set `AUTHKIT_EMULATE_ENABLED=true` — every package HTTP call (the SDK client,
the guard's JWKS verification, logout URLs) follows the override.

## Running the Events Worker

Run `php artisan authkit:work` in a separate long-lived process (alongside
`php artisan serve`, your queue worker, etc.) — it polls the WorkOS Events API,
dispatches typed/generic Laravel events, and keeps the local projections
(users, organizations, organization domains, memberships) fresh.

Delivery is **at-least-once**: keep every listener idempotent (upsert or
delete-if-exists keyed on `$event->resourceId()`, never `$event->id`). A cache
lock guarantees a single poller; a second `authkit:work` process exits
immediately without calling the WorkOS API. Use `--once` to process a single
batch and exit (useful for schedulers and smoke tests).

Scaffold a listener for any WorkOS event type with:

```bash
php artisan make:workos-listener LogWorkosEvents
```

## Receiving Webhooks (optional, low-latency)

Webhooks are a latency shortcut sharing the exact same Laravel event objects —
the poller remains the durable source of sync. Register the endpoint with one
line in `routes/web.php` (CSRF is excluded for you; signature verification is
applied for you):

```php
Route::workosWebhooks('workos/webhooks');
```

Point a WorkOS Dashboard webhook at `https://your-app.test/workos/webhooks`
and set `WORKOS_WEBHOOK_SECRET`.

## Wiring Into `php artisan dev`

### Recipe A — Laravel 13.16+ (preferred)

Copy into your app's `AppServiceProvider::boot()`:

```php
use Illuminate\Foundation\DevCommands;

DevCommands::artisan('authkit:work', 'authkit-events')->purple();
DevCommands::register('npx @workos/emulate', 'workos-emulate')->orange();
```

### Recipe B — Laravel 12, or Laravel 13 before 13.16

Add a `dev` script to your app's `composer.json`, following the same
`concurrently` shape Laravel's own skeleton used before `artisan dev` existed:

```json
"dev": [
    "Composer\\Config::disableProcessTimeout",
    "npx concurrently -c \"#93c5fd,#fca5a5,#fdba74\" \"php artisan serve\" \"php artisan authkit:work\" \"npx @workos/emulate\" --names=serve,authkit-events,workos-emulate --kill-others"
]
```

Then run `composer dev`.
