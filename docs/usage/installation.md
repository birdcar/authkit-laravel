# Installation

Get WorkOS AuthKit running in your Laravel application in just a few minutes.

## System Requirements

- PHP 8.3 or higher
- Laravel 11.0 or 12.0
- Composer 2.x
- A WorkOS account (free tier available at https://workos.com)

## Step 1: Install the Package

Install authkit-laravel via Composer:

```bash
composer require birdcar/authkit-laravel
```

This adds WorkOS AuthKit to your project and registers the service provider automatically.

## Step 2: Set Environment Variables

Add your WorkOS credentials to your `.env` file. You can find these in your WorkOS Dashboard under API Keys:

```env
WORKOS_API_KEY=sk_test_your_api_key_here
WORKOS_CLIENT_ID=client_your_client_id_here
WORKOS_REDIRECT_URI=http://localhost:8000/auth/callback
WORKOS_WEBHOOK_SECRET=whsec_your_webhook_secret_here
```

If your redirect URI uses HTTPS in production, update it accordingly:

```env
WORKOS_REDIRECT_URI=https://yourdomain.com/auth/callback
```

## Step 3: Run the Interactive Installer

Execute the setup wizard:

```bash
php artisan workos:install
```

This command will:
- Detect your environment (fresh install or existing auth system)
- Publish the configuration file to `config/workos.php`
- Publish database migrations
- Register authentication routes
- Optionally set up webhooks
- Suggest migration steps if you're upgrading from Breeze, Jetstream, or Fortify

### Mini Install Mode

For a minimal setup with configuration only and setup instructions:

```bash
php artisan workos:install --mini
```

### Force Overwrite

To overwrite existing configuration files:

```bash
php artisan workos:install --force
```

## Step 4: Publish Config (if needed)

The installer publishes the config automatically, but you can also publish it manually:

```bash
php artisan vendor:publish --tag=workos-config
```

This creates `config/workos.php` with sensible defaults.

## Step 5: Run Migrations

Publish and run the package's database migrations:

```bash
php artisan migrate
```

This creates tables for storing user and organization data synced from WorkOS.

## Configuration File

The `config/workos.php` file controls the package behavior:

```php
return [
    // WorkOS API credentials (from environment variables)
    'api_key' => env('WORKOS_API_KEY'),
    'client_id' => env('WORKOS_CLIENT_ID'),
    'redirect_uri' => env('WORKOS_REDIRECT_URI', env('APP_URL').'/auth/callback'),
    'webhook_secret' => env('WORKOS_WEBHOOK_SECRET'),

    // Auth guard name
    'guard' => 'workos',

    // Session configuration
    'session' => [
        'cookie_name' => env('WORKOS_COOKIE_NAME', 'wos-session'),
        'access_token_lifetime' => env('WORKOS_ACCESS_TOKEN_LIFETIME', 60),
    ],

    // Feature flags
    'features' => [
        'audit_logs' => env('WORKOS_FEATURE_AUDIT_LOGS', false),
        'organizations' => env('WORKOS_FEATURE_ORGANIZATIONS', true),
        'impersonation' => env('WORKOS_FEATURE_IMPERSONATION', true),
        'webhooks' => env('WORKOS_FEATURE_WEBHOOKS', true),
        'widgets' => env('WORKOS_FEATURE_WIDGETS', true),
        'feature_flags' => env('WORKOS_FEATURE_FEATURE_FLAGS', true),
        'vault' => env('WORKOS_FEATURE_VAULT', false),
        'radar' => env('WORKOS_FEATURE_RADAR', false),
        'pipes' => env('WORKOS_FEATURE_PIPES', false),
        'domain_verification' => env('WORKOS_FEATURE_DOMAIN_VERIFICATION', false),
    ],

    // Widgets API configuration
    'widgets' => [
        'base_url' => env('WORKOS_BASE_API_URL', 'https://api.workos.com'),
    ],

    // Routes configuration
    'routes' => [
        'enabled' => true,
        'prefix' => 'auth',
        'organizations_prefix' => 'organizations',
        'middleware' => ['web'],
        'home' => '/',
    ],

    // Webhooks configuration
    'webhooks' => [
        'enabled' => true,
        'prefix' => 'webhooks/workos',
    ],

    // API keys configuration
    'api_keys' => [
        // ...
    ],

    // Fine-Grained Authorization configuration
    'fga' => [
        // ...
    ],

    // Sync configuration
    'sync' => [
        'listeners' => [
            // Set a listener class to null to disable it
        ],
    ],

    // Directory Sync configuration
    'dsync' => [
        // ...
    ],

    // User and organization models
    'user_model' => env('WORKOS_USER_MODEL', 'App\\Models\\User'),
    'organization_model' => env('WORKOS_ORGANIZATION_MODEL', 'App\\Models\\Organization'),
];
```

### Key Configuration Options

**api_key** and **client_id**
Your WorkOS API credentials. Required for authentication to work.

**redirect_uri**
The URL where users return after signing in via WorkOS. Must match the redirect URI configured in your WorkOS Dashboard.

**guard**
The name of the authentication guard. Defaults to `'workos'`. Change this if you want to use a different guard name.

**features**
Feature flags control optional functionality:
- `audit_logs`: Enable WorkOS Audit Logs API integration (requires enterprise plan)
- `organizations`: Enable multi-organization support
- `impersonation`: Allow admin impersonation for testing
- `webhooks`: Enable webhook ingestion from WorkOS
- `widgets`: Enable Livewire widget components
- `feature_flags`: Enable WorkOS Feature Flags integration (default: true)
- `vault`: Enable WorkOS Vault integration (default: false)
- `radar`: Enable WorkOS Radar integration (default: false)
- `pipes`: Enable WorkOS Pipes integration (default: false)
- `domain_verification`: Enable domain verification support (default: false)

**user_model** and **organization_model**
Fully qualified class names of your User and Organization models. These are used for syncing data from WorkOS webhooks.

**routes**
Control the built-in authentication routes:
- `enabled`: Set to `false` to register your own routes manually
- `prefix`: URL prefix for auth routes (e.g., `/auth/login`, `/auth/callback`)
- `organizations_prefix`: URL prefix for organization endpoints
- `home`: Default redirect URL after successful login

## Update Your User Model

Add the `HasWorkOSId` trait to your User model:

```php
<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use WorkOS\AuthKit\Models\Concerns\HasWorkOSId;
use WorkOS\AuthKit\Models\Concerns\HasWorkOSPermissions;

class User extends Authenticatable
{
    use HasWorkOSId;
    use HasWorkOSPermissions;

    protected $fillable = [
        'workos_id',
        'email',
        'name',
    ];
}
```

The traits add:
- **HasWorkOSId**: Methods to find and create users by WorkOS ID
- **HasWorkOSPermissions**: Methods to check roles and permissions

## Create Your Organization Model (if using organizations)

If you enabled the `organizations` feature, create an Organization model:

```bash
php artisan make:model Organization
```

Add the `HasOrganization` trait to both User and Organization models:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use WorkOS\AuthKit\Models\Concerns\HasOrganization;

class User extends Model
{
    use HasOrganization;
    // ...
}

class Organization extends Model
{
    use HasOrganization;
    // ...
}
```

## Migrating from Existing Auth Systems

If you're upgrading from Laravel Breeze, Jetstream, or Fortify, the installer offers migration assistance.

The `workos:install` command detects your existing auth setup and suggests:
- Tables and columns to remove
- Route names to update
- Configuration changes needed
- Test setup updates

Review the migration plan carefully, as it depends on your specific implementation.

### Manual Migration Steps

1. **Update Guards**
   Configure the `'workos'` guard in `config/auth.php`:
   ```php
   'guards' => [
       'web' => [
           'driver' => 'workos',
           'provider' => 'users',
       ],
   ],
   ```

2. **Update User Provider**
   Ensure your user provider points to your User model:
   ```php
   'providers' => [
       'users' => [
           'driver' => 'eloquent',
           'model' => App\Models\User::class,
       ],
   ],
   ```

3. **Remove Old Auth Middleware**
   Remove middleware from `app/Http/Middleware` if provided by the old package.

4. **Update Routes**
   Replace old auth route registrations with the new WorkOS routes or customize as needed.

5. **Remove Old Packages**
   Once fully migrated, remove old auth packages:
   ```bash
   composer remove laravel/breeze laravel/jetstream laravel/fortify
   ```

## Verify Installation

Manually test by visiting your login page:

```
http://localhost:8000/auth/login
```

You should be redirected to the WorkOS authentication page. If you get an error, check:
- Environment variables are set correctly
- `config/workos.php` is published
- Your WorkOS Dashboard has the correct redirect URI

## Next Steps

- Read [Authentication](authentication.md) to understand session management
- Learn [Authorization](authorization.md) for roles and permissions
- Explore [Organizations](organizations.md) for multi-org support
- Review [Webhooks](webhooks.md) for syncing data from WorkOS
- Check [Testing](testing.md) for test setup

## Troubleshooting

**"WORKOS_API_KEY not configured"**
Make sure `WORKOS_API_KEY` is set in your `.env` file and you've run `php artisan config:clear`.

**"Redirect URI mismatch"**
Verify the `WORKOS_REDIRECT_URI` in your `.env` matches exactly what's configured in your WorkOS Dashboard.

**"Authentication failed at callback"**
Check that your `WORKOS_CLIENT_ID` is correct and the user exists in your WorkOS organization.

**Migrations don't run**
Run `php artisan vendor:publish --tag=workos-migrations` to publish them, then `php artisan migrate`.
