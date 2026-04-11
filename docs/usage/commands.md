# Artisan Commands Reference

Complete reference for all WorkOS artisan commands.

## Overview

The package registers three main commands:

1. `workos:install` — Interactive setup wizard
2. `workos:sync-users` — Manual user sync from WorkOS
3. `workos:events-listen` — Events API polling worker

## workos:install

Interactive setup wizard that detects your environment, publishes configuration, creates models, and runs migrations.

### Usage

```bash
php artisan workos:install
```

Run with options:

```bash
# Skip interactive prompts and overwrite files
php artisan workos:install --force

# Minimal setup: config only, with setup instructions
php artisan workos:install --mini
```

### Signature

```
workos:install
  {--force : Overwrite existing configuration files}
  {--mini : Minimal install - config only with setup instructions}
```

### What It Does

The wizard performs these steps (when not using `--mini`):

1. **Environment Detection** — Checks for existing auth systems (Breeze, Jetstream, Fortify)
2. **Node Tooling Detection** — Looks for npm/bun and delegates env setup to WorkOS CLI if available
3. **Configuration Publishing** — Copies `config/workos.php` to your app
4. **User Model Setup** — Creates or updates your User model with required traits
5. **Organization Model** — Creates an Organization model if organizations feature is enabled
6. **Database Migrations** — Publishes and runs required migrations
7. **Authentication Routes** — Registers `/auth/login`, `/auth/callback`, `/auth/logout` routes
8. **Organization Routes** — If enabled, registers `/organizations/switch` and invitation routes
9. **Webhook Configuration** — Sets up the webhook endpoint (if enabled)
10. **Migration Plan** — If upgrading from Breeze/Jetstream/Fortify, generates a migration plan

### Output Example

```
Environment Detection

  ✓ No existing auth setup detected - fresh install

Detected Node tooling (bun), delegating env setup to WorkOS CLI...

WorkOS API Configuration

  ? WORKOS_API_KEY: sk_test_...
  ? WORKOS_CLIENT_ID: client_...
  ? WORKOS_REDIRECT_URI: https://myapp.com/auth/callback

Creating/updating User model...
  ✓ Added HasWorkOSId trait
  ✓ Added HasWorkOSPermissions trait

Creating Organization model...
  ✓ Created app/Models/Organization.php
  ✓ Added HasOrganization trait

Publishing and running migrations...
  ✓ Migration 2024_01_01_000001_create_organizations_table
  ✓ Migration 2024_01_01_000002_create_organization_memberships_table

Registering authentication routes...
  ✓ Routes registered at /auth/login, /auth/callback, /auth/logout

Setup complete! Visit /auth/login to test.
```

### --force Option

Skips all prompts and overwrites existing files:

```bash
php artisan workos:install --force
```

Use this for:
- Automated deployments
- CI/CD pipelines
- Resetting a broken installation

### --mini Option

Minimal setup: publishes config, writes env placeholders, and shows instructions.

```bash
php artisan workos:install --mini
```

Use this when you:
- Want to customize configuration before running the full wizard
- Have existing auth setup that needs careful migration planning
- Are setting up a multi-environment deployment

Output includes:

```
Minimal Install

  ✓ Configuration published to config/workos.php
  ✓ Environment variables placeholder written

Environment Variables Required:
  
  WORKOS_API_KEY=sk_test_...
  WORKOS_CLIENT_ID=client_...
  WORKOS_REDIRECT_URI=https://myapp.com/auth/callback
  WORKOS_WEBHOOK_SECRET=whsec_...

Next Steps:

  1. Add the above variables to your .env file
  2. Run: php artisan migrate
  3. Run: php artisan workos:install --force (to complete)
```

### Troubleshooting

**"Node tooling detected but installation failed"**

The wizard tried to use the WorkOS CLI but it failed. The built-in setup continues automatically. Ensure you have Node.js 16+ and the WorkOS CLI installed:

```bash
npm install -g @workos-inc/cli
workos version
```

**"Existing auth system detected"**

If you're upgrading from Breeze, Jetstream, or Fortify, the wizard generates a migration plan. Review it before proceeding:

```bash
cat storage/workos-migration-plan.md
```

**"User model not found"**

The wizard tries to find your User model at `App\Models\User`. If it's elsewhere, manually add the traits:

```php
use WorkOS\AuthKit\Models\Concerns\HasWorkOSId;
use WorkOS\AuthKit\Models\Concerns\HasWorkOSPermissions;

class User extends Authenticatable
{
    use HasWorkOSId, HasWorkOSPermissions;
}
```

## workos:sync-users

Manually fetch users from WorkOS and sync them to your local database.

Useful for backfilling users before webhooks are set up, or recovering from a sync failure.

### Usage

```bash
php artisan workos:sync-users
```

Sync users from a specific organization:

```bash
php artisan workos:sync-users --organization=org_123abc
```

Limit the number of users synced:

```bash
php artisan workos:sync-users --limit=100
```

Combine options:

```bash
php artisan workos:sync-users --organization=org_123abc --limit=50
```

### Signature

```
workos:sync-users
  {--organization= : Sync users from a specific organization}
  {--limit= : Maximum number of users to sync}
```

### What It Does

1. Connects to the WorkOS User Management API
2. Fetches users (paginated) from your organization
3. Calls your User model's `findOrCreateByWorkOS()` method for each user
4. Displays progress and a summary

### Output Example

```
Syncing users from WorkOS...

  Processing user_123abc (john@example.com)...
  Processing user_124abc (jane@example.com)...
  Processing user_125abc (bob@example.com)...

Synced 3 users.
```

### --organization Option

Sync users from a specific organization:

```bash
php artisan workos:sync-users --organization=org_abc123def456
```

If omitted, syncs all users across all organizations.

### --limit Option

Limit the number of users to sync:

```bash
php artisan workos:sync-users --limit=10
```

Useful for testing before a large sync.

If omitted, syncs all users.

### Customization

The command uses your User model's `findOrCreateByWorkOS()` method. Customize sync behavior:

```php
<?php

namespace App\Models;

class User extends Authenticatable
{
    public static function findOrCreateByWorkOS(array $workosUser): self
    {
        return self::updateOrCreate(
            ['workos_id' => $workosUser['id']],
            [
                'email' => $workosUser['email'],
                'name' => $workosUser['first_name'].' '.$workosUser['last_name'],
                'avatar_url' => $workosUser['profile_image_url'] ?? null,
            ]
        );
    }
}
```

### Troubleshooting

**"API key not configured"**

Set `WORKOS_API_KEY` in your `.env` and clear config:

```bash
php artisan config:clear
```

**"No users synced"**

Check that:
1. Users exist in your WorkOS organization
2. Your User model has the `findOrCreateByWorkOS()` method (or uses `HasWorkOSId` trait)
3. The method doesn't have validation that rejects the data

**"SQLSTATE[HY000]: General error"**

Your User model's `findOrCreateByWorkOS()` threw an exception. Check the full error output and fix any validation or database issues.

## workos:events-listen

Poll the WorkOS Events API for data sync.

This is a persistent background worker that continuously fetches events from the Events API and dispatches them as Laravel events.

See [Events API and Webhooks](events.md) for comprehensive documentation.

### Usage

```bash
php artisan workos:events-listen
```

Poll once and exit (for debugging):

```bash
php artisan workos:events-listen --once
```

Start from a specific date on first run:

```bash
php artisan workos:events-listen --since=2024-01-15
```

Override poll interval:

```bash
php artisan workos:events-listen --sleep=10
```

Combine options:

```bash
php artisan workos:events-listen --once --since=2024-01-15 --sleep=2
```

### Signature

```
workos:events-listen
  {--once : Poll a single page and exit}
  {--since= : ISO 8601 date to start from on first run (e.g. 2024-01-15)}
  {--sleep= : Seconds between polls when caught up (overrides config)}
```

### What It Does

1. Checks configuration for events routed to `'events_api'` or `'both'`
2. Loads or initializes the event cursor from cache
3. Continuously polls the WorkOS Events API
4. For each event:
   - Dispatches a `WebhookReceived` event
   - Dispatches the specific event class (e.g., `WorkOSUserCreated`)
5. Persists the cursor after each event
6. Sleeps between polling cycles
7. Handles graceful shutdown on SIGTERM/SIGINT

### Output Example

```
Polling WorkOS Events API...
Event types: user.created, user.updated, organization.created, organization_membership.created, organization_membership.updated, organization_membership.deleted

First run — bootstrapping from 7 days ago

Processing: user.created (evt_123abc)
Processing: organization.created (evt_124abc)
Processing: organization_membership.created (evt_125abc)

Processed 3 events. Caught up, sleeping 5s...

[Repeats every 5s when caught up]
```

### --once Option

Poll once and exit:

```bash
php artisan workos:events-listen --once
```

Useful for:
- Debugging event processing
- Scheduled cron jobs (via `schedule()` in Kernel)
- Testing integration

### --since Option

Start from a specific ISO 8601 date on first run:

```bash
php artisan workos:events-listen --since=2024-01-15
```

Overrides the configured `lookback_days`:

```bash
# Without --since: bootstraps from 7 days ago (default)
php artisan workos:events-listen

# With --since: bootstraps from 2024-01-15
php artisan workos:events-listen --since=2024-01-15
```

### --sleep Option

Override the configured poll interval (in seconds):

```bash
# Use configured interval (5s by default)
php artisan workos:events-listen

# Override to 10s
php artisan workos:events-listen --sleep=10

# Poll more aggressively
php artisan workos:events-listen --sleep=2
```

### Running as a Background Worker

Use Supervisor to keep the worker running:

```ini
[program:workos-events]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/app/artisan workos:events-listen
autostart=true
autorestart=true
stopasgroup=true
redirect_stderr=true
stdout_logfile=/path/to/app/storage/logs/workos-events.log
numprocs=1
user=www-data
```

Or with systemd:

```ini
[Service]
ExecStart=/usr/bin/php /path/to/app/artisan workos:events-listen
Restart=always
RestartSec=10
```

See [Events API and Webhooks](events.md) for detailed deployment instructions.

### Cursor Persistence

The worker stores the last processed event ID in cache:

```php
// config/workos.php
'events' => [
    'cache_store' => 'redis',  // or your cache driver
    'cache_key' => 'workos.events.cursor',
],
```

To reset the cursor and resync from scratch:

```php
// In tinker or your app
Cache::forget('workos.events.cursor');
```

Or via command line:

```bash
php artisan tinker
>>> Cache::forget('workos.events.cursor')
```

### Troubleshooting

**"No event types configured for events_api routing"**

Update your config to route at least one event category to `'events_api'` or `'both'`:

```php
'routing' => [
    'categories' => [
        'dsync' => 'events_api',  // Enable the worker
    ],
],
```

**"API request failed: 401"**

Your API key is invalid or missing. Check:

```bash
php artisan config:clear
echo $WORKOS_API_KEY
```

**"Worker stops immediately after starting"**

If `--once` completes, that's expected. For a persistent worker, run without `--once`:

```bash
php artisan workos:events-listen
```

**"High memory usage"**

The worker maintains a long-running process. Monitor with:

```bash
ps aux | grep workos:events-listen
```

If memory grows unbounded, there may be a listener accumulating state. Ensure listeners are stateless.

## Quick Reference

| Command | Purpose | Key Options |
|---------|---------|------------|
| `workos:install` | Initial setup | `--force`, `--mini` |
| `workos:sync-users` | Manual user sync | `--organization`, `--limit` |
| `workos:events-listen` | Poll Events API | `--once`, `--since`, `--sleep` |

## Related Documentation

- [Events API and Webhooks](events.md) — Event routing and polling worker
- [Configuration](configuration.md) — Config reference
- [Installation](installation.md) — Initial setup steps
