# Quickstart

> This file is seeded by the events-pipeline phase; the full quickstart is
> assembled during the acceptance phase.

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
