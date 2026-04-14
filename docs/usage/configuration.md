# Configuration Reference

Complete reference for all configuration options in `config/workos.php`.

## Overview

The package configuration is organized into sections:

1. **API Credentials** — WorkOS API keys
2. **Auth Guard** — Guard name for Laravel auth system
3. **Session** — Cookie and token settings
4. **Features** — Feature flags
5. **Widgets** — Widget API configuration
6. **Routes** — Built-in route configuration
7. **Webhooks** — Webhook endpoint configuration
8. **Events** — Events API polling configuration
9. **API Keys** — API key validation base URL
10. **FGA** — Fine-grained authorization
11. **Sync** — Custom sync listener overrides
12. **Models** — User and Organization model classes
13. **DSync** — Directory sync model classes

## API Credentials

### api_key

**Type:** `string`  
**Default:** `env('WORKOS_API_KEY')`  
**Required:** Yes

Your WorkOS API key for server-side authentication.

Get this from your WorkOS Dashboard under API Keys.

```env
WORKOS_API_KEY=sk_test_abc123...
```

### client_id

**Type:** `string`  
**Default:** `env('WORKOS_CLIENT_ID')`  
**Required:** Yes

Your WorkOS client ID for OAuth flows.

```env
WORKOS_CLIENT_ID=client_abc123...
```

### redirect_uri

**Type:** `string`  
**Default:** `env('WORKOS_REDIRECT_URI', env('APP_URL').'/auth/callback')`  
**Required:** Yes

The full URL where WorkOS redirects after authentication.

Must match exactly what you configured in your WorkOS Dashboard.

```env
WORKOS_REDIRECT_URI=https://myapp.com/auth/callback
```

### webhook_secret

**Type:** `string | null`  
**Default:** `env('WORKOS_WEBHOOK_SECRET')`  
**Required:** If webhooks are enabled

Secret for verifying webhook signatures from WorkOS.

Get this from your WorkOS Dashboard in the Webhooks section.

```env
WORKOS_WEBHOOK_SECRET=whsec_test_abc123...
```

## Auth Guard Configuration

### guard

**Type:** `string`  
**Default:** `'workos'`

The name of the authentication guard for use with Laravel's auth system.

If you change this, update `config/auth.php` to match:

```php
// config/auth.php
'guards' => [
    'web' => [
        'driver' => 'workos',  // Must match workos.guard
        'provider' => 'users',
    ],
],
```

## Session Configuration

The `session` array controls cookie and token behavior.

### session.cookie_name

**Type:** `string`  
**Default:** `env('WORKOS_COOKIE_NAME', 'wos-session')`

Name of the encrypted cookie that stores the session.

The cookie is automatically encrypted using your Laravel app key.

```env
WORKOS_COOKIE_NAME=wos-session
```

### session.access_token_lifetime

**Type:** `int`  
**Default:** `env('WORKOS_ACCESS_TOKEN_LIFETIME', 60)`

Lifetime of the access token in minutes.

When the token approaches expiry, it's automatically refreshed using the refresh token.

```env
WORKOS_ACCESS_TOKEN_LIFETIME=60
```

## Feature Flags

The `features` array enables/disables optional functionality.

### features.audit_logs

**Type:** `bool`  
**Default:** `env('WORKOS_FEATURE_AUDIT_LOGS', false)`

Enable integration with WorkOS Audit Logs API.

Requires Enterprise plan. When disabled, `WorkOS::audit()` calls are silently skipped.

```env
WORKOS_FEATURE_AUDIT_LOGS=false
```

### features.organizations

**Type:** `bool`  
**Default:** `env('WORKOS_FEATURE_ORGANIZATIONS', true)`

Enable multi-organization support.

When disabled, organization-related middleware and routes are not registered.

```env
WORKOS_FEATURE_ORGANIZATIONS=true
```

### features.impersonation

**Type:** `bool`  
**Default:** `env('WORKOS_FEATURE_IMPERSONATION', true)`

Enable admin impersonation detection.

Allows admins to test the app as a specific user. Sessions include impersonator info.

```env
WORKOS_FEATURE_IMPERSONATION=true
```

### features.webhooks

**Type:** `bool`  
**Default:** `env('WORKOS_FEATURE_WEBHOOKS', true)`

Enable webhook ingestion endpoint.

When disabled, the webhook endpoint is not registered and webhook routes are skipped.

```env
WORKOS_FEATURE_WEBHOOKS=true
```

### features.widgets

**Type:** `bool`  
**Default:** `env('WORKOS_FEATURE_WIDGETS', true)`

Enable Livewire widget components.

Requires Livewire to be installed. When disabled, widget classes are not registered.

```env
WORKOS_FEATURE_WIDGETS=true
```

### features.feature_flags

**Type:** `bool`  
**Default:** `env('WORKOS_FEATURE_FLAGS', true)`

Enable the WorkOS Feature Flags service.

```env
WORKOS_FEATURE_FLAGS=true
```

### features.vault

**Type:** `bool`  
**Default:** `env('WORKOS_FEATURE_VAULT', false)`

Enable the WorkOS Vault service for secrets management.

```env
WORKOS_FEATURE_VAULT=false
```

### features.radar

**Type:** `bool`  
**Default:** `env('WORKOS_FEATURE_RADAR', false)`

Enable the WorkOS Radar service for bot and abuse detection.

```env
WORKOS_FEATURE_RADAR=false
```

### features.pipes

**Type:** `bool`  
**Default:** `env('WORKOS_FEATURE_PIPES', false)`

Enable the WorkOS Pipes integration service.

```env
WORKOS_FEATURE_PIPES=false
```

### features.domain_verification

**Type:** `bool`  
**Default:** `env('WORKOS_FEATURE_DOMAIN_VERIFICATION', false)`

Enable domain verification workflows.

```env
WORKOS_FEATURE_DOMAIN_VERIFICATION=false
```

## Widgets Configuration

The `widgets` array configures the Livewire widget components.

### widgets.base_url

**Type:** `string`  
**Default:** `env('WORKOS_BASE_API_URL', 'https://api.workos.com')`

Base URL for the WorkOS API used by widgets.

Override for staging or local development:

```env
WORKOS_BASE_API_URL=https://staging-api.workos.com
```

## Routes Configuration

The `routes` array controls built-in authentication routes.

### routes.enabled

**Type:** `bool`  
**Default:** `true`

Whether to register the package's built-in routes (`/auth/login`, `/auth/callback`, `/auth/logout`).

Set to `false` to register your own routes manually:

```php
Route::post('/auth/login', [AuthController::class, 'login']);
Route::get('/auth/callback', [AuthController::class, 'callback']);
Route::post('/auth/logout', [AuthController::class, 'logout']);
```

### routes.prefix

**Type:** `string`  
**Default:** `'auth'`

URL prefix for authentication routes.

Changes the login URL from `/auth/login` to `/{prefix}/login`:

```php
'prefix' => 'account',  // Routes become /account/login, /account/callback, /account/logout
```

### routes.organizations_prefix

**Type:** `string`  
**Default:** `'organizations'`

URL prefix for organization routes.

Changes organization routes from `/organizations/switch` to `/{prefix}/switch`:

```php
'organizations_prefix' => 'orgs',  // Routes become /orgs/switch, /orgs/{id}/invitations
```

### routes.middleware

**Type:** `array`  
**Default:** `['web']`

Middleware applied to all package routes.

The `web` middleware group typically includes session, CSRF protection, and other global middleware.

```php
'middleware' => ['web', 'throttle:60,1'],  // Add rate limiting
```

### routes.home

**Type:** `string`  
**Default:** `'/'`

Where to redirect after successful login.

Used if no `return_to` parameter is specified in the login flow.

```php
'home' => '/dashboard',
```

## Webhooks Configuration

The `webhooks` array controls the webhook endpoint.

### webhooks.enabled

**Type:** `bool`  
**Default:** `true`

Whether to register the webhook endpoint at `/webhooks/workos`.

Set to `false` if you want to handle webhooks manually:

```php
'enabled' => false,
```

### webhooks.prefix

**Type:** `string`  
**Default:** `'webhooks/workos'`

URL prefix for the webhook endpoint.

Changes the endpoint from `/webhooks/workos` to `/{prefix}`:

```php
'prefix' => 'webhooks/workos',  // Endpoint: POST /webhooks/workos
```

## Events Configuration

The `events` array configures event routing and the polling worker.

### events.routing.categories

**Type:** `array<string, string>`  
**Default:** See below

Maps event categories to routing methods: `'webhooks'`, `'events_api'`, or `'both'`.

Supported categories:

- `'user'` — User lifecycle events
- `'organization'` — Organization lifecycle events
- `'organization_membership'` — Membership lifecycle events
- `'dsync'` — Directory sync (LDAP, SCIM) events
- `'session'` — Session lifecycle events
- `'authentication'` — Authentication method events
- `'organization_domain'` — Organization domain verification events

Example configuration:

```php
'categories' => [
    'user' => env('WORKOS_SYNC_USER', 'webhooks'),
    'organization' => env('WORKOS_SYNC_ORGANIZATION', 'webhooks'),
    'organization_membership' => env('WORKOS_SYNC_MEMBERSHIP', 'webhooks'),
    'dsync' => env('WORKOS_SYNC_DSYNC', 'events_api'),
    'session' => env('WORKOS_SYNC_SESSION', 'webhooks'),
    'authentication' => env('WORKOS_SYNC_AUTH', 'webhooks'),
    'organization_domain' => env('WORKOS_SYNC_ORGANIZATION_DOMAIN', 'webhooks'),
],
```

Environment variables:

```env
WORKOS_SYNC_USER=webhooks
WORKOS_SYNC_ORGANIZATION=webhooks
WORKOS_SYNC_MEMBERSHIP=webhooks
WORKOS_SYNC_DSYNC=events_api
WORKOS_SYNC_SESSION=webhooks
WORKOS_SYNC_AUTH=webhooks
WORKOS_SYNC_ORGANIZATION_DOMAIN=webhooks
```

### events.routing.overrides

**Type:** `array<string, string>`  
**Default:** `[]`

Per-event-type routing overrides (takes precedence over categories).

Use to route specific events differently:

```php
'overrides' => [
    'user.created' => 'events_api',      // Force via Events API
    'session.created' => 'both',          // Accept from either source
],
```

### events.poll_interval

**Type:** `int`  
**Default:** `env('WORKOS_EVENTS_POLL_INTERVAL', 5)`

Seconds between polls when caught up (no new events).

Lower values = more frequent API requests and lower latency. Higher values = fewer requests and lower cost.

```env
WORKOS_EVENTS_POLL_INTERVAL=5
```

### events.lookback_days

**Type:** `int`  
**Default:** `env('WORKOS_EVENTS_LOOKBACK_DAYS', 7)`

Days to backfill on first run if no cursor exists.

Controls the initial sync window when `workos:events-listen` is run for the first time.

```env
WORKOS_EVENTS_LOOKBACK_DAYS=7
```

### events.limit

**Type:** `int`  
**Default:** `env('WORKOS_EVENTS_LIMIT', 100)`

Events per API request (max 1000).

Higher values = fewer requests but slower processing of each batch.

```env
WORKOS_EVENTS_LIMIT=100
```

### events.cache_store

**Type:** `string | null`  
**Default:** `env('WORKOS_EVENTS_CACHE_STORE', null)`

Cache store for cursor persistence.

Defaults to your application's `CACHE_DRIVER` if `null`. Use `'redis'` for distributed systems.

```env
WORKOS_EVENTS_CACHE_STORE=redis
```

### events.cache_key

**Type:** `string`  
**Default:** `'workos.events.cursor'`

Cache key for storing the event cursor.

```php
'cache_key' => 'workos.events.cursor',
```

## API Keys Configuration

The `api_keys` array configures WorkOS API key validation.

### api_keys.base_url

**Type:** `string`  
**Default:** `env('WORKOS_BASE_API_URL', 'https://api.workos.com')`

Base URL for API key validation requests.

Override for staging or local development:

```env
WORKOS_BASE_API_URL=https://staging-api.workos.com
```

## FGA Configuration

The `fga` array configures WorkOS Fine-Grained Authorization.

### fga.enabled

**Type:** `bool`  
**Default:** `env('WORKOS_FGA_ENABLED', false)`

Enable FGA for resource-level access control.

When enabled, the `workos.fga` middleware and `@workosAccess` Blade directive become available.

```env
WORKOS_FGA_ENABLED=false
```

### fga.gate_integration

**Type:** `bool`  
**Default:** `env('WORKOS_FGA_GATE_INTEGRATION', false)`

Register a Laravel Gate after-hook that delegates to FGA for `FGAResource` arguments.

When enabled, `Gate::allows()` calls with FGA resource types automatically check WorkOS FGA.

```env
WORKOS_FGA_GATE_INTEGRATION=false
```

## Sync Configuration

The `sync` array controls which listeners handle WorkOS sync events.

### sync.listeners

**Type:** `array<class-string, class-string|null>`  
**Default:** `[]`

Map event classes to custom listener classes, or `null` to disable a listener entirely. Events omitted from this array use the package's default listeners.

```php
'listeners' => [
    // Replace the default listener with your own
    WorkOS\AuthKit\Events\Sync\WorkOSUserCreated::class => App\Listeners\MyUserSync::class,

    // Disable the default listener for this event
    WorkOS\AuthKit\Events\Sync\WorkOSUserDeleted::class => null,
],
```

## Models Configuration

### user_model

**Type:** `string`  
**Default:** `env('WORKOS_USER_MODEL', 'App\\Models\\User')`

Fully qualified class name of your User model.

Used for webhook sync and user lookups. The model must implement Laravel's `Authenticatable` contract.

```env
WORKOS_USER_MODEL=App\Models\User
```

### organization_model

**Type:** `string`  
**Default:** `env('WORKOS_ORGANIZATION_MODEL', 'App\\Models\\Organization')`

Fully qualified class name of your Organization model.

Used for organization-related webhook sync. The model should implement Laravel's `Model` contract.

```env
WORKOS_ORGANIZATION_MODEL=App\Models\Organization
```

## DSync Configuration

The `dsync` array configures models for directory sync data.

### dsync.user_model

**Type:** `string | null`  
**Default:** `env('WORKOS_DSYNC_USER_MODEL')`

Fully qualified class name of the model that receives directory user records.

Directory users are IDP-managed records (LDAP/SCIM), distinct from your application's auth user model. Set to `null` to disable directory user sync.

```env
WORKOS_DSYNC_USER_MODEL=App\Models\DirectoryUser
```

### dsync.group_model

**Type:** `string | null`  
**Default:** `env('WORKOS_DSYNC_GROUP_MODEL')`

Fully qualified class name of the model that receives directory group records.

Set to `null` to disable directory group sync.

```env
WORKOS_DSYNC_GROUP_MODEL=App\Models\DirectoryGroup
```

## Complete Example

Here's a production-ready configuration:

```php
<?php

return [
    // API Credentials
    'api_key' => env('WORKOS_API_KEY'),
    'client_id' => env('WORKOS_CLIENT_ID'),
    'redirect_uri' => env('WORKOS_REDIRECT_URI', env('APP_URL').'/auth/callback'),
    'webhook_secret' => env('WORKOS_WEBHOOK_SECRET'),

    // Guard
    'guard' => 'workos',

    // Session
    'session' => [
        'cookie_name' => env('WORKOS_COOKIE_NAME', 'wos-session'),
        'access_token_lifetime' => env('WORKOS_ACCESS_TOKEN_LIFETIME', 60),
    ],

    // Features
    'features' => [
        'audit_logs' => env('WORKOS_FEATURE_AUDIT_LOGS', false),
        'organizations' => env('WORKOS_FEATURE_ORGANIZATIONS', true),
        'impersonation' => env('WORKOS_FEATURE_IMPERSONATION', true),
        'webhooks' => env('WORKOS_FEATURE_WEBHOOKS', true),
        'widgets' => env('WORKOS_FEATURE_WIDGETS', true),
        'feature_flags' => env('WORKOS_FEATURE_FLAGS', true),
        'vault' => env('WORKOS_FEATURE_VAULT', false),
        'radar' => env('WORKOS_FEATURE_RADAR', false),
        'pipes' => env('WORKOS_FEATURE_PIPES', false),
        'domain_verification' => env('WORKOS_FEATURE_DOMAIN_VERIFICATION', false),
    ],

    // Widgets
    'widgets' => [
        'base_url' => env('WORKOS_BASE_API_URL', 'https://api.workos.com'),
    ],

    // Routes
    'routes' => [
        'enabled' => true,
        'prefix' => 'auth',
        'organizations_prefix' => 'organizations',
        'middleware' => ['web'],
        'home' => '/',
    ],

    // Webhooks
    'webhooks' => [
        'enabled' => true,
        'prefix' => 'webhooks/workos',
    ],

    // Events
    'events' => [
        'routing' => [
            'categories' => [
                'user' => env('WORKOS_SYNC_USER', 'webhooks'),
                'organization' => env('WORKOS_SYNC_ORGANIZATION', 'webhooks'),
                'organization_membership' => env('WORKOS_SYNC_MEMBERSHIP', 'webhooks'),
                'dsync' => env('WORKOS_SYNC_DSYNC', 'events_api'),
                'session' => env('WORKOS_SYNC_SESSION', 'webhooks'),
                'authentication' => env('WORKOS_SYNC_AUTH', 'webhooks'),
                'organization_domain' => env('WORKOS_SYNC_ORGANIZATION_DOMAIN', 'webhooks'),
            ],
            'overrides' => [],
        ],
        'poll_interval' => env('WORKOS_EVENTS_POLL_INTERVAL', 5),
        'lookback_days' => env('WORKOS_EVENTS_LOOKBACK_DAYS', 7),
        'limit' => env('WORKOS_EVENTS_LIMIT', 100),
        'cache_store' => env('WORKOS_EVENTS_CACHE_STORE'),
        'cache_key' => 'workos.events.cursor',
    ],

    // API Keys
    'api_keys' => [
        'base_url' => env('WORKOS_BASE_API_URL', 'https://api.workos.com'),
    ],

    // FGA
    'fga' => [
        'enabled' => env('WORKOS_FGA_ENABLED', false),
        'gate_integration' => env('WORKOS_FGA_GATE_INTEGRATION', false),
    ],

    // Sync listeners
    'sync' => [
        'listeners' => [],
    ],

    // Models
    'user_model' => env('WORKOS_USER_MODEL', 'App\\Models\\User'),
    'organization_model' => env('WORKOS_ORGANIZATION_MODEL', 'App\\Models\\Organization'),

    // DSync
    'dsync' => [
        'user_model' => env('WORKOS_DSYNC_USER_MODEL'),
        'group_model' => env('WORKOS_DSYNC_GROUP_MODEL'),
    ],
];
```

## Publishing the Config

Manually publish the config file:

```bash
php artisan vendor:publish --tag=workos-config
```

This copies `config/workos.php` to your application, allowing you to customize without editing vendor files.

## Troubleshooting

### "Configuration option does not exist"

Ensure you've published the config:

```bash
php artisan vendor:publish --tag=workos-config
```

Then clear the config cache:

```bash
php artisan config:clear
```

### Environment variables not being used

Verify the `.env` file exists and contains the variables. Clear config cache:

```bash
php artisan config:clear
```

### Feature flags not working

Ensure the syntax is correct in your config. Test with:

```php
dd(config('workos.features.audit_logs'));
```

Should return a boolean, not a string like `'true'`.

## Related Documentation

- [Events API and Webhooks](events.md) — Event routing and polling worker
- [Installation](installation.md) — Initial setup and publish steps
- [Commands](commands.md) — Artisan commands reference
